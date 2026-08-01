<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { Contract, Vehicle, Trailer, Driver } from '../types'
import { credits, num } from '../utils/format'
import CommodityBadge from '../components/CommodityBadge.vue'
import DifficultyStars from '../components/DifficultyStars.vue'

const game = useGameStore()
const toast = useToastStore()
let poll: number | undefined

const contracts = ref<Contract[]>([])
const vehicles = ref<Vehicle[]>([])
const trailers = ref<Trailer[]>([])
const drivers = ref<Driver[]>([])
const loading = ref(false)
const accepting = ref<number | null>(null)
const dispatching = ref<number | null>(null)
const filters = ref({ origin_city_id: '', commodity_id: '', sort: 'payout' })
const haulableOnly = ref(true)
const selection = ref<Record<number, { vehicle_id: number | null; trailer_id: number | null; driver_id: number | null }>>({})

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
    for (const c of contracts.value) {
      if (!selection.value[c.id]) selection.value[c.id] = { vehicle_id: null, trailer_id: null, driver_id: null }
    }
  } catch (e) {
    if (!silent) toast.error(apiError(e))
  } finally {
    loading.value = false
  }
}

async function loadFleet() {
  const [f, tr, d] = await Promise.allSettled([api.get('/fleet'), api.get('/trailers'), api.get('/drivers')])
  if (f.status === 'fulfilled') vehicles.value = f.value.data.data
  if (tr.status === 'fulfilled') trailers.value = tr.value.data.data
  if (d.status === 'fulfilled') drivers.value = d.value.data.data
}

// ---- compatibility (mirrors Operations) -----------------------------------
function modeOk(v: Vehicle, c: Contract): boolean {
  const mode = v.model?.mode ?? 'road'
  if (mode === 'sea') return !!(c.origin?.has_port && c.destination?.has_port)
  if (mode === 'air') return !!(c.origin?.has_airport && c.destination?.has_airport)
  return true
}
function vehicleSelfHauls(v: Vehicle, c: Contract): boolean {
  const m = v.model, com = c.commodity
  if (!m || !com) return false
  if (com.requires_reefer && !m.can_reefer) return false
  if (com.requires_tanker && !m.can_tanker) return false
  if (com.is_hazardous && !m.can_hazmat) return false
  return m.capacity_weight >= (c.total_weight ?? 0) && m.capacity_volume >= (c.total_volume ?? 0)
}
function compatibleVehicles(c: Contract): Vehicle[] {
  return vehicles.value.filter((v) => v.available && v.model && modeOk(v, c) && (v.model.needs_trailer ? true : vehicleSelfHauls(v, c)))
}
function trailerCarries(t: Trailer, c: Contract): boolean {
  const m = t.model, com = c.commodity
  if (!m || !com) return false
  if (com.requires_reefer && !m.can_reefer) return false
  if (com.requires_tanker && !m.can_tanker) return false
  if (com.is_hazardous && !m.can_hazmat) return false
  return m.capacity_weight >= (c.total_weight ?? 0) && m.capacity_volume >= (c.total_volume ?? 0)
}
function compatibleTrailers(c: Contract): Trailer[] {
  return trailers.value.filter((t) => t.available && trailerCarries(t, c))
}
function compatibleDrivers(c: Contract): Driver[] {
  return drivers.value.filter((d) => d.available && (!c.commodity?.is_hazardous || d.hazmat_licence))
}
function selectedVehicle(c: Contract): Vehicle | undefined {
  return vehicles.value.find((v) => v.id === selection.value[c.id]?.vehicle_id)
}
function needsTrailer(c: Contract): boolean {
  return !!selectedVehicle(c)?.model?.needs_trailer
}
function canDispatch(c: Contract): boolean {
  const sel = selection.value[c.id]
  return !!sel?.vehicle_id && !!sel?.driver_id && (!needsTrailer(c) || !!sel?.trailer_id)
}

async function accept(c: Contract) {
  accepting.value = c.id
  try {
    await api.post(`/contracts/${c.id}/accept`)
    contracts.value = contracts.value.filter((x) => x.id !== c.id)
    toast.success('Contract claimed. Dispatch it from Operations.')
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    accepting.value = null
  }
}

async function dispatchNow(c: Contract) {
  const sel = selection.value[c.id]
  if (!sel?.vehicle_id || !sel?.driver_id) return toast.error('Pick a vehicle and a driver first.')
  if (needsTrailer(c) && !sel.trailer_id) return toast.error('This tractor needs a trailer — attach one.')
  dispatching.value = c.id
  try {
    await api.post(`/contracts/${c.id}/dispatch`, {
      vehicle_id: sel.vehicle_id,
      trailer_id: needsTrailer(c) ? sel.trailer_id : null,
      driver_id: sel.driver_id,
    })
    toast.success('Dispatched! Truck is rolling.')
    contracts.value = contracts.value.filter((x) => x.id !== c.id)
    await loadFleet()
    load(true)
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    dispatching.value = null
  }
}

onMounted(async () => {
  await game.loadReference().catch(() => {})
  await loadFleet().catch(() => {})
  load()
  poll = window.setInterval(() => load(true), 15000)
})
onUnmounted(() => clearInterval(poll))
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Contract Market</h1>
        <p class="text-slate-400 text-sm">Claim a job and dispatch it right here. Auto-refreshes as new jobs appear.</p>
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

        <!-- Inline dispatch: pick vehicle + (trailer) + driver, then GO -->
        <div class="mt-3 space-y-1.5 pt-3 border-t border-white/5">
          <select v-model="selection[c.id].vehicle_id" class="input !py-1.5 text-xs">
            <option :value="null" disabled>Choose vehicle…</option>
            <option v-for="v in compatibleVehicles(c)" :key="v.id" :value="v.id">
              {{ v.nickname || v.model?.name }} · fuel {{ Math.round(v.fuel_pct ?? 100) }}%
            </option>
          </select>
          <select v-if="needsTrailer(c)" v-model="selection[c.id].trailer_id" class="input !py-1.5 text-xs">
            <option :value="null" disabled>Attach trailer…</option>
            <option v-for="t in compatibleTrailers(c)" :key="t.id" :value="t.id">{{ t.model?.name }}</option>
          </select>
          <select v-model="selection[c.id].driver_id" class="input !py-1.5 text-xs">
            <option :value="null" disabled>Choose driver…</option>
            <option v-for="d in compatibleDrivers(c)" :key="d.id" :value="d.id">{{ d.name }} · skill {{ d.skill }}</option>
          </select>
        </div>

        <div class="flex items-center justify-between mt-3">
          <button class="text-[11px] text-slate-400 hover:text-slate-200" :disabled="accepting === c.id" @click="accept(c)">
            {{ accepting === c.id ? '…' : 'Claim for later' }}
          </button>
          <button
            class="btn-primary !py-1.5 !px-4"
            :disabled="dispatching === c.id || !canDispatch(c)"
            @click="dispatchNow(c)"
          >
            {{ dispatching === c.id ? '…' : 'GO →' }}
          </button>
        </div>
        <p v-if="!compatibleVehicles(c).length" class="text-[10px] text-loss mt-1">No compatible idle vehicle.</p>
        <p v-else-if="needsTrailer(c) && !compatibleTrailers(c).length" class="text-[10px] text-loss mt-1">Needs a matching trailer — buy one in Fleet.</p>
        <p v-else-if="!compatibleDrivers(c).length" class="text-[10px] text-loss mt-1">No available driver — rest or hire crew.</p>
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
