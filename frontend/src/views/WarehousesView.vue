<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { credits, num, cur } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

const warehouses = ref<any[]>([])
const buildCost = ref(0)
const busy = ref(false)
const buildForm = ref({ city_id: '', name: '' })
const trade = ref<Record<number, { commodity_id: string; units: number }>>({})

const sortedCities = computed(() => [...game.cities].sort((a, b) => a.name.localeCompare(b.name)))

async function load() {
  const { data } = await api.get('/warehouses')
  warehouses.value = data.data
  buildCost.value = data.build_cost
  for (const w of warehouses.value) if (!trade.value[w.id]) trade.value[w.id] = { commodity_id: '', units: 50 }
}

async function build() {
  if (!buildForm.value.city_id) return toast.error('Choose a city.')
  busy.value = true
  try {
    await api.post('/warehouses', { city_id: buildForm.value.city_id, name: buildForm.value.name })
    toast.success('Warehouse built.')
    buildForm.value = { city_id: '', name: '' }
    await load()
    game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { busy.value = false }
}

async function doTrade(w: any, action: 'buy' | 'sell') {
  const t = trade.value[w.id]
  if (!t.commodity_id || !t.units) return toast.error('Pick a commodity and quantity.')
  try {
    const { data } = await api.post(`/warehouses/${w.id}/${action}`, { commodity_id: t.commodity_id, units: t.units })
    toast.success(data.message)
    await load()
    game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) }
}

onMounted(async () => { await game.loadReference().catch(() => {}); await load().catch((e) => toast.error(apiError(e))) })
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-2xl font-bold">Warehouses</h1>
      <p class="text-slate-400 text-sm">Buy goods where they're cheap, store them, and sell when prices rise.</p>
    </div>

    <!-- Build -->
    <div class="glass p-4 flex flex-wrap items-end gap-3">
      <div class="flex-1 min-w-[160px]">
        <label class="stat-label">Build in city</label>
        <select v-model="buildForm.city_id" class="input mt-1">
          <option value="">Choose city…</option>
          <option v-for="c in sortedCities" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="flex-1 min-w-[160px]">
        <label class="stat-label">Name (optional)</label>
        <input v-model="buildForm.name" class="input mt-1" placeholder="Central Depot" />
      </div>
      <button class="btn-primary" :disabled="busy" @click="build">Build ({{ credits(buildCost) }})</button>
    </div>

    <div v-if="!warehouses.length" class="glass p-8 text-center text-slate-400">
      No warehouses yet. Build one to start trading commodities directly.
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
      <div v-for="w in warehouses" :key="w.id" class="glass p-4">
        <div class="flex items-center justify-between">
          <div>
            <p class="font-semibold">{{ w.name }}</p>
            <p class="text-[11px] text-slate-400">{{ w.city.name }} · Tier {{ w.tier }}</p>
          </div>
          <div class="text-right">
            <p class="stat-label">Space</p>
            <p class="font-mono text-sm">{{ num(w.used) }} / {{ num(w.capacity) }}</p>
          </div>
        </div>

        <!-- Inventory -->
        <div v-if="w.inventory.length" class="mt-3 space-y-1.5">
          <div v-for="row in w.inventory" :key="row.commodity_id" class="flex items-center justify-between text-xs bg-ink-900/50 rounded-lg px-3 py-2">
            <span class="font-medium">{{ row.commodity }}</span>
            <span class="text-slate-400">{{ num(row.units) }} u @ {{ cur() }}{{ num(row.avg_unit_cost, 2) }}</span>
            <span class="font-mono" :class="row.unrealized >= 0 ? 'text-gain' : 'text-loss'">
              {{ row.unrealized >= 0 ? '+' : '' }}{{ cur() }}{{ num(row.unrealized, 0) }}
            </span>
          </div>
        </div>
        <p v-else class="text-[11px] text-slate-500 mt-3">Empty — buy some goods below.</p>

        <!-- Trade panel -->
        <div class="mt-3 grid grid-cols-[1fr_auto_auto_auto] gap-2 items-center">
          <select v-model="trade[w.id].commodity_id" class="input !py-1.5 text-xs">
            <option value="">Commodity…</option>
            <option v-for="k in game.commodities" :key="k.id" :value="k.id">{{ k.name }}</option>
          </select>
          <input v-model.number="trade[w.id].units" type="number" min="1" class="input !py-1.5 text-xs w-20" />
          <button class="btn-ghost !py-1.5 text-xs" @click="doTrade(w, 'buy')">Buy</button>
          <button class="btn-primary !py-1.5 text-xs" @click="doTrade(w, 'sell')">Sell</button>
        </div>
      </div>
    </div>
  </div>
</template>
