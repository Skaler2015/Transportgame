<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import type { Contract } from '../types'
import { credits, num } from '../utils/format'
import CommodityBadge from '../components/CommodityBadge.vue'
import DifficultyStars from '../components/DifficultyStars.vue'

const game = useGameStore()
const auth = useAuthStore()
const toast = useToastStore()
let poll: number | undefined

const contracts = ref<Contract[]>([])
const loading = ref(false)
const accepting = ref<number | null>(null)
const filters = ref({ origin_city_id: '', commodity_id: '', sort: 'payout' })
const haulableOnly = ref(true)

const sortedCities = computed(() => [...game.cities].sort((a, b) => a.name.localeCompare(b.name)))

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const params: Record<string, string> = { sort: filters.value.sort }
    if (filters.value.origin_city_id) params.origin_city_id = filters.value.origin_city_id
    if (filters.value.commodity_id) params.commodity_id = filters.value.commodity_id
    if (haulableOnly.value) params.haulable = '1'
    const { data } = await api.get('/contracts', { params })
    contracts.value = data.data
  } catch (e) {
    if (!silent) toast.error(apiError(e)) // stay quiet on background refreshes
  } finally {
    loading.value = false
  }
}

async function accept(c: Contract) {
  accepting.value = c.id
  try {
    await api.post(`/contracts/${c.id}/accept`)
    contracts.value = contracts.value.filter((x) => x.id !== c.id)
    toast.success('Contract claimed. Dispatch a truck from Operations.')
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    accepting.value = null
  }
}

onMounted(async () => {
  await game.loadReference().catch(() => {})
  load()
  // Keep the market fresh — new contracts are minted every world tick.
  poll = window.setInterval(() => load(true), 15000)
})
onUnmounted(() => clearInterval(poll))
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Contract Market</h1>
        <p class="text-slate-400 text-sm">Open haulage jobs across {{ auth.company?.country_name || 'your country' }}. Auto-refreshes as new jobs appear.</p>
      </div>
    </div>

    <!-- Filters -->
    <div class="glass p-4 flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-[160px]">
        <label class="stat-label">Origin</label>
        <select v-model="filters.origin_city_id" class="input mt-1" @change="load()">
          <option value="">Any city</option>
          <option v-for="c in sortedCities" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>
      </div>
      <div class="flex-1 min-w-[160px]">
        <label class="stat-label">Commodity</label>
        <select v-model="filters.commodity_id" class="input mt-1" @change="load()">
          <option value="">Any cargo</option>
          <option v-for="k in game.commodities" :key="k.id" :value="k.id">{{ k.name }}</option>
        </select>
      </div>
      <div class="min-w-[140px]">
        <label class="stat-label">Sort by</label>
        <select v-model="filters.sort" class="input mt-1" @change="load()">
          <option value="payout">Highest payout</option>
          <option value="distance_km">Shortest distance</option>
          <option value="difficulty">Easiest</option>
          <option value="deadline_at">Deadline soonest</option>
        </select>
      </div>
      <button class="btn-ghost" @click="load()">↻ Refresh</button>
      <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none ml-auto">
        <input type="checkbox" v-model="haulableOnly" class="accent-brand h-4 w-4" @change="load()" />
        Only what my fleet can haul
      </label>
    </div>

    <div v-if="loading" class="grid place-items-center h-64 text-slate-500">Loading market…</div>

    <div v-else class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="c in contracts" :key="c.id" class="glass glass-hover p-4 flex flex-col">
        <div class="flex items-start justify-between gap-2">
          <CommodityBadge :commodity="c.commodity" size="md" />
          <div class="flex items-center gap-2">
            <span v-if="c.is_rush" class="chip bg-loss/20 text-loss">RUSH</span>
            <DifficultyStars :value="c.difficulty" />
          </div>
        </div>

        <div class="mt-3 flex items-center gap-2 text-sm font-semibold">
          <span>{{ c.origin?.name }}</span>
          <span class="text-brand">→</span>
          <span>{{ c.destination?.name }}</span>
        </div>
        <p class="text-[11px] text-slate-400">{{ num(c.distance_km) }} km · {{ num(c.units) }} units · {{ num(c.total_weight ?? 0, 1) }} t</p>

        <div class="grid grid-cols-2 gap-2 mt-3 text-sm">
          <div class="rounded-lg bg-ink-900/60 px-3 py-2">
            <p class="stat-label">Payout</p>
            <p class="font-mono text-gold font-semibold">{{ credits(c.payout) }}</p>
          </div>
          <div class="rounded-lg bg-ink-900/60 px-3 py-2">
            <p class="stat-label">Penalty</p>
            <p class="font-mono text-loss">{{ credits(c.penalty) }}</p>
          </div>
        </div>

        <div class="flex items-center justify-between mt-3">
          <span class="text-[11px] text-slate-400">+{{ c.reputation_reward }} rep</span>
          <button class="btn-primary !py-1.5 !px-4" :disabled="accepting === c.id" @click="accept(c)">
            {{ accepting === c.id ? '…' : 'Claim' }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="!loading && !contracts.length" class="glass p-10 text-center text-slate-400">
      <p v-if="haulableOnly">No open contracts fit an available truck right now.</p>
      <p v-else>No contracts match those filters. Try widening your search or advancing the world.</p>
      <p v-if="haulableOnly" class="text-xs text-slate-500 mt-2">
        Free up or buy a bigger truck, or untick “Only what my fleet can haul” to see everything.
      </p>
    </div>
  </div>
</template>
