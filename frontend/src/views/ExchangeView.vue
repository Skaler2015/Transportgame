<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

const listings = ref<any[]>([])
const mine = ref<any[]>([])
const warehouses = ref<any[]>([])
const form = ref({ warehouse_id: '', commodity_id: '', units: 10, price_per_unit: 100 })
const busy = ref(false)

async function load() {
  const [ex, wh] = await Promise.all([api.get('/exchange'), api.get('/warehouses')])
  listings.value = ex.data.listings
  mine.value = ex.data.mine
  warehouses.value = wh.data.data
}

// Commodities available in the chosen warehouse.
const stockOptions = computed(() => {
  const w = warehouses.value.find((x) => String(x.id) === String(form.value.warehouse_id))
  return w ? w.inventory : []
})

async function act(fn: () => Promise<any>) {
  busy.value = true
  try { const { data } = await fn(); toast.success(data.message); await load(); game.refreshDashboard().catch(() => {}) }
  catch (e) { toast.error(apiError(e)) } finally { busy.value = false }
}

const create = () => act(() => api.post('/exchange', {
  warehouse_id: form.value.warehouse_id,
  commodity_id: form.value.commodity_id,
  units: form.value.units,
  price_per_unit: form.value.price_per_unit,
}))
const buy = (l: any) => act(() => api.post(`/exchange/${l.id}/buy`))
const cancel = (l: any) => act(() => api.post(`/exchange/${l.id}/cancel`))

onMounted(async () => { await game.loadReference().catch(() => {}); await load().catch((e) => toast.error(apiError(e))) })
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-2xl font-bold">Player Exchange</h1>
      <p class="text-slate-400 text-sm">Buy and sell commodities directly with other companies. Goods move between warehouses.</p>
    </div>

    <!-- Create listing -->
    <div class="glass p-4">
      <h2 class="font-semibold mb-3">Sell from a warehouse</h2>
      <div v-if="warehouses.length" class="grid sm:grid-cols-2 lg:grid-cols-5 gap-2 items-end">
        <div>
          <label class="stat-label">Warehouse</label>
          <select v-model="form.warehouse_id" class="input !py-1.5 text-xs mt-1">
            <option value="">Choose…</option>
            <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }} ({{ w.city.name }})</option>
          </select>
        </div>
        <div>
          <label class="stat-label">Commodity</label>
          <select v-model="form.commodity_id" class="input !py-1.5 text-xs mt-1">
            <option value="">Choose…</option>
            <option v-for="row in stockOptions" :key="row.commodity_id" :value="row.commodity_id">{{ row.commodity }} ({{ row.units }})</option>
          </select>
        </div>
        <div>
          <label class="stat-label">Units</label>
          <input v-model.number="form.units" type="number" min="1" class="input !py-1.5 text-xs mt-1" />
        </div>
        <div>
          <label class="stat-label">₡ / unit</label>
          <input v-model.number="form.price_per_unit" type="number" min="0.01" step="0.01" class="input !py-1.5 text-xs mt-1" />
        </div>
        <button class="btn-primary !py-2" :disabled="busy" @click="create">List</button>
      </div>
      <p v-else class="text-sm text-slate-500">Build a warehouse and stock it first, then you can list goods here.</p>
    </div>

    <!-- My listings -->
    <div v-if="mine.length">
      <h2 class="font-semibold mb-2">Your listings</h2>
      <div class="glass divide-y divide-white/5">
        <div v-for="l in mine" :key="l.id" class="flex items-center justify-between px-4 py-3 text-sm">
          <span>{{ num(l.units) }}× {{ l.commodity }} @ ₡{{ num(l.price_per_unit, 2) }} · {{ l.city }}</span>
          <div class="flex items-center gap-3">
            <span class="font-mono text-gold">{{ credits(l.total) }}</span>
            <button class="btn-ghost !py-1 text-xs" :disabled="busy" @click="cancel(l)">Cancel</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Market -->
    <div>
      <h2 class="font-semibold mb-2">Open listings</h2>
      <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div v-for="l in listings" :key="l.id" class="glass p-4">
          <div class="flex items-center justify-between">
            <p class="font-semibold text-sm">{{ l.commodity }}</p>
            <span class="text-[11px] text-slate-400">{{ l.city }}</span>
          </div>
          <p class="text-[11px] text-slate-400 mt-0.5">{{ num(l.units) }} units · ₡{{ num(l.price_per_unit, 2) }}/unit · by {{ l.seller }}</p>
          <div class="flex items-center justify-between mt-3">
            <span class="font-mono text-gold font-semibold">{{ credits(l.total) }}</span>
            <button v-if="!l.is_mine" class="btn-primary !py-1.5" :disabled="busy" @click="buy(l)">Buy</button>
            <span v-else class="chip bg-white/5 text-slate-400">Yours</span>
          </div>
        </div>
        <div v-if="!listings.length" class="glass p-6 text-center text-slate-500 col-span-full">No open listings right now.</div>
      </div>
    </div>
  </div>
</template>
