<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed, watch } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { useClock } from '../composables/useClock'
import type { Contract, Vehicle, Trailer, Driver, Shipment } from '../types'
import { credits, num, fleetTag } from '../utils/format'
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
const filters = ref({ origin_city_id: '', commodity_id: '', sort: 'distance_km' })
const haulableOnly = ref(true)
// On by default: show jobs starting where your trucks are (parked or arriving),
// so a free truck always sees local work first. Untick to see the whole market.
const backhaulOnly = ref(true)

// Your HQ city — newly bought trucks spawn here, so "jobs leaving HQ" is the
// natural first-leg view. Toggling it just pins the origin filter to HQ.
const hqCityId = computed(() => game.dashboard?.company?.headquarters?.id ?? null)
const hqOnly = ref(false)
function toggleHq() {
  filters.value.origin_city_id = hqOnly.value && hqCityId.value ? String(hqCityId.value) : ''
  load()
}
const selection = ref<Record<number, { vehicle_id: number | null; trailer_id: number | null; driver_id: number | null }>>({})

const sortedCities = computed(() => [...game.cities].sort((a, b) => a.name.localeCompare(b.name)))

// Rough delivery time from the base travel speed (225 km/min); real time
// varies a little with weather/traffic/road/driver.
const KM_PER_MIN = 225
function etaText(km: number): string {
  const secs = Math.max(20, Math.round((km / KM_PER_MIN) * 60))
  if (secs < 60) return `~${secs}s`
  const m = Math.floor(secs / 60)
  const s = secs % 60
  return s ? `~${m}m ${s}s` : `~${m}m`
}

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const params: Record<string, string> = { sort: filters.value.sort }
    if (filters.value.origin_city_id) params.origin_city_id = filters.value.origin_city_id
    if (filters.value.commodity_id) params.commodity_id = filters.value.commodity_id
    if (haulableOnly.value) params.haulable = '1'
    if (backhaulOnly.value) params.backhaul = '1'
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
  void loadEstimate()
}

const upkeepEst = ref<{ service: { count: number; total: number }; fuel: { count: number; total: number } }>(
  { service: { count: 0, total: 0 }, fuel: { count: 0, total: 0 } },
)
async function loadEstimate() {
  try {
    const { data } = await api.get('/fleet/service-estimate')
    upkeepEst.value = data
  } catch { /* estimate is best-effort */ }
}

// --- Side panel: live fleet at a glance -------------------------------------
const now = useClock(1000)
const shipments = ref<Shipment[]>([])
async function loadShipments() {
  try {
    const { data } = await api.get('/shipments')
    shipments.value = data.data
  } catch { /* best-effort */ }
}
// On the road, soonest arrival first.
const onTheRoad = computed(() =>
  shipments.value
    .filter((s) => s.status === 'en_route')
    .sort((a, b) => new Date(a.eta_at).getTime() - new Date(b.eta_at).getTime()),
)
function etaLeft(s: Shipment): string {
  const ms = new Date(s.eta_at).getTime() - now.value
  if (ms <= 0) return 'arriving…'
  const secs = Math.round(ms / 1000)
  const m = Math.floor(secs / 60)
  const sec = secs % 60
  return m ? `${m}m ${sec}s` : `${sec}s`
}

// The instant a shipment's ETA passes, settle it and refresh the board — no
// manual refresh needed: the delivered truck frees up and its next jobs appear.
const arrivedHandled = new Set<number>()
watch(now, () => {
  const due = shipments.value.filter(
    (s) => s.status === 'en_route'
      && new Date(s.eta_at).getTime() <= now.value
      && !arrivedHandled.has(s.id),
  )
  if (!due.length) return
  due.forEach((s) => arrivedHandled.add(s.id))
  // Hitting /shipments settles due deliveries server-side (self-healing).
  loadShipments()
  loadFleet().catch(() => {})
  load(true)
  game.refreshDashboard().catch(() => {})
})
// Free (idle) vehicles and where they're parked.
const freeVehicles = computed(() => vehicles.value.filter((v) => v.available))
// What service a vehicle needs, so it's visible without leaving the market.
function vehicleNeed(v: Vehicle): string {
  return [
    ((v.condition ?? 100) < 70 || (v.tire_wear ?? 0) > 40) ? 'repair' : '',
    (v.oil_level ?? 100) < 40 ? 'oil' : '',
    (v.battery ?? 100) < 40 ? 'battery' : '',
    !v.is_insured ? 'insurance' : '',
    !v.is_registered ? 'registration' : '',
  ].filter(Boolean).join(' · ')
}

// Per-shipment value & estimated profit, and the totals for everything on the
// road — same cost model as the contract cards (tolls + tax + fuel).
function shipmentPnl(s: Shipment) {
  const c = s.contract
  const dist = s.distance_km || 0
  const payout = s.projected_payout || 0
  const tax = Math.round(payout * (c?.destination?.tax_rate ?? 0))
  const avgToll = ((c?.origin?.toll_per_km ?? 0) + (c?.destination?.toll_per_km ?? 0)) / 2
  const toll = Math.round(dist * avgToll * 100)
  const fuel = Math.round(dist * (s.vehicle?.model?.fuel_economy ?? 0) * (c?.origin?.fuel_price ?? 0) * 100)
  return { payout, profit: payout - tax - toll - fuel }
}
const roadTotals = computed(() =>
  onTheRoad.value.reduce(
    (acc, s) => {
      const p = shipmentPnl(s)
      acc.value += p.payout
      acc.profit += p.profit
      return acc
    },
    { value: 0, profit: 0 },
  ),
)

// Contract table: expand-to-dispatch drawer + client-side sort on any column.
const expandedContract = ref<number | null>(null)
function toggleContract(id: number) {
  expandedContract.value = expandedContract.value === id ? null : id
}

type CSortKey = 'cargo' | 'from' | 'to' | 'status' | 'dist' | 'load' | 'eta' | 'value' | 'profit' | 'diff'
// Default: shortest ETA on top.
const cSort = ref<{ k: CSortKey; dir: 'asc' | 'desc' }>({ k: 'eta', dir: 'asc' })
function clickSort(k: CSortKey) {
  if (cSort.value.k === k) cSort.value = { k, dir: cSort.value.dir === 'asc' ? 'desc' : 'asc' }
  else cSort.value = { k, dir: ['from', 'to', 'cargo'].includes(k) ? 'asc' : 'desc' }
}
function sortMark(k: CSortKey): string {
  return cSort.value.k === k ? (cSort.value.dir === 'asc' ? ' ▲' : ' ▼') : ''
}
// The top "Sort by" dropdown drives the same client sort.
const dropdownSort = ref<CSortKey>('eta')
function applyDropdownSort() {
  const k = dropdownSort.value
  cSort.value = { k, dir: (k === 'value' || k === 'profit') ? 'desc' : 'asc' }
  // Fetch the matching slice from the server, then client-sort it.
  filters.value.sort = (k === 'value' || k === 'profit') ? 'payout' : k === 'diff' ? 'difficulty' : 'distance_km'
  load()
}
function cVal(c: Contract, k: CSortKey): number | string {
  switch (k) {
    case 'cargo': return (c.commodity?.name ?? '').toLowerCase()
    case 'from': return (c.origin?.name ?? '').toLowerCase()
    case 'to': return (c.destination?.name ?? '').toLowerCase()
    case 'status': return c.at_fleet_city ? (c.fleet_arriving ? 1 : 2) : 0
    case 'dist': case 'eta': return c.distance_km ?? 0
    case 'load': return c.total_weight ?? 0
    case 'value': return c.payout ?? 0
    case 'profit': return costBreakdown(c).profit
    default: return c.difficulty ?? 0
  }
}
// Always client-sorted (default: shortest ETA first); header/dropdown change it.
const sortedContracts = computed(() => {
  const { k, dir } = cSort.value
  return [...contracts.value].sort((a, b) => {
    const av = cVal(a, k), bv = cVal(b, k)
    const cmp = typeof av === 'string' ? av.localeCompare(bv as string) : (av as number) - (bv as number)
    return dir === 'asc' ? cmp : -cmp
  })
})

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
  // A truck can only start this job if it's parked AT the origin city.
  return vehicles.value.filter((v) => v.available && v.model
    && v.city?.id === c.origin?.id
    && modeOk(v, c) && (v.model.needs_trailer ? true : vehicleSelfHauls(v, c)))
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

// Estimated P&L for a contract, mirroring what the delivery actually charges:
// tax on the payout (destination rate), tolls along the lane, and fuel burned.
// Tax + tolls are vehicle-independent; fuel uses the chosen vehicle (or the
// best compatible one as a preview) so profit is meaningful before you pick.
function estFuelModel(c: Contract) {
  return selectedVehicle(c)?.model ?? compatibleVehicles(c)[0]?.model
}
function costBreakdown(c: Contract) {
  const dist = c.distance_km || 0
  const tax = Math.round((c.payout || 0) * (c.destination?.tax_rate ?? 0))
  const avgToll = ((c.origin?.toll_per_km ?? 0) + (c.destination?.toll_per_km ?? 0)) / 2
  const toll = Math.round(dist * avgToll * 100)
  const m = estFuelModel(c)

  // Only FUEL is auto-charged on arrival (servicing is manual), so the per-run
  // deduction the player sees is fuel. Costs in cents.
  const fuel = m ? Math.round(dist * (m.fuel_economy || 0) * (c.origin?.fuel_price ?? 0) * 100) : 0

  const expenses = tax + toll + fuel
  return { tax, toll, fuel, expenses, profit: (c.payout || 0) - expenses, hasVehicle: !!m }
}

const busyUpkeep = ref<'service' | 'fuel' | null>(null)
async function serviceAll() {
  busyUpkeep.value = 'service'
  try {
    const { data } = await api.post('/fleet/service-all')
    toast.success(data.message)
    await loadFleet(); load(true)
    game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { busyUpkeep.value = null }
}
async function fuelAll() {
  busyUpkeep.value = 'fuel'
  try {
    const { data } = await api.post('/fleet/refuel-all')
    toast.success(data.message)
    await loadFleet(); load(true)
    game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { busyUpkeep.value = null }
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
    await Promise.all([loadFleet(), loadShipments()])
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
  loadShipments()
  load()
  poll = window.setInterval(() => { load(true); loadShipments(); loadFleet().catch(() => {}) }, 15000)
})
onUnmounted(() => clearInterval(poll))
</script>

<template>
  <div class="lg:flex lg:gap-5 lg:items-start">
   <div class="flex-1 min-w-0 space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Contract Market</h1>
        <p class="text-slate-400 text-sm">Claim a job and dispatch it right here. Auto-refreshes as new jobs appear.</p>
      </div>
      <div class="flex gap-2">
        <button class="btn-ghost !py-1.5" :disabled="busyUpkeep !== null || upkeepEst.service.count === 0" @click="serviceAll">
          <template v-if="busyUpkeep === 'service'">Servicing…</template>
          <template v-else-if="upkeepEst.service.count > 0">🔧 Service All · {{ credits(upkeepEst.service.total) }}</template>
          <template v-else>🔧 Service All</template>
        </button>
        <button class="btn-ghost !py-1.5" :disabled="busyUpkeep !== null || upkeepEst.fuel.count === 0" @click="fuelAll">
          <template v-if="busyUpkeep === 'fuel'">Fuelling…</template>
          <template v-else-if="upkeepEst.fuel.count > 0">⛽ Fuel All · {{ credits(upkeepEst.fuel.total) }}</template>
          <template v-else>⛽ Fuel All</template>
        </button>
      </div>
    </div>

    <!-- Filters -->
    <div class="glass p-4 flex flex-wrap gap-3 items-end">
      <div class="flex-1 min-w-[160px]">
        <label class="stat-label">Origin</label>
        <select v-model="filters.origin_city_id" class="input mt-1" @change="hqOnly = String(filters.origin_city_id) === String(hqCityId ?? ''); load()">
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
        <select v-model="dropdownSort" class="input mt-1" @change="applyDropdownSort">
          <option value="eta">Soonest ETA</option>
          <option value="value">Highest payout</option>
          <option value="profit">Best profit</option>
          <option value="diff">Easiest</option>
        </select>
      </div>
      <button class="btn-ghost" @click="load()">↻ Refresh</button>
      <label v-if="hqCityId" class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none ml-auto"
        title="Only jobs leaving your HQ — where your new trucks are parked.">
        <input type="checkbox" v-model="hqOnly" class="accent-brand h-4 w-4" @change="toggleHq" />
        🏭 From my HQ
      </label>
      <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none"
        :class="hqCityId ? '' : 'ml-auto'"
        title="Jobs starting in a city where one of your trucks is parked — or heading right now — so it never runs back empty.">
        <input type="checkbox" v-model="backhaulOnly" class="accent-brand h-4 w-4" @change="load()" />
        🚚 Backhaul from my trucks
      </label>
      <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none">
        <input type="checkbox" v-model="haulableOnly" class="accent-brand h-4 w-4" @change="load()" />
        Only what my fleet can haul
      </label>
    </div>

    <div v-if="loading" class="grid place-items-center h-64 text-slate-500">Loading market…</div>

    <!-- Compact, sortable contract table. Click a row's GO to dispatch. -->
    <div v-else-if="contracts.length">
      <!-- Desktop: full sortable table (md and up) -->
      <div class="hidden md:block glass !p-0 overflow-hidden">
      <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[880px] border-collapse">
        <thead class="text-[10px] uppercase tracking-wider text-slate-400 select-none bg-ink-900/80 backdrop-blur sticky top-0 z-10">
          <tr class="border-b border-white/10 [&>th]:cursor-pointer [&>th:hover]:text-brand-soft [&>th]:transition">
            <th class="text-left px-3 py-3 font-semibold" @click="clickSort('cargo')">Cargo{{ sortMark('cargo') }}</th>
            <th class="text-left px-2 font-semibold" @click="clickSort('from')">From{{ sortMark('from') }}</th>
            <th class="text-left px-2 font-semibold" @click="clickSort('to')">To{{ sortMark('to') }}</th>
            <th class="text-center px-2" @click="clickSort('status')">Status{{ sortMark('status') }}</th>
            <th class="text-right px-2" @click="clickSort('dist')">Dist{{ sortMark('dist') }}</th>
            <th class="text-right px-2" @click="clickSort('load')">Load{{ sortMark('load') }}</th>
            <th class="text-right px-2" @click="clickSort('eta')">ETA{{ sortMark('eta') }}</th>
            <th class="text-right px-2" @click="clickSort('value')">Value{{ sortMark('value') }}</th>
            <th class="text-right px-2" @click="clickSort('profit')">Profit{{ sortMark('profit') }}</th>
            <th class="text-center px-2" @click="clickSort('diff')">Diff{{ sortMark('diff') }}</th>
            <th class="text-right px-3 !cursor-default"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="c in sortedContracts" :key="c.id">
            <tr class="border-b border-white/5 transition hover:bg-brand/[0.06] odd:bg-white/[0.015] cursor-pointer"
              :class="c.at_fleet_city ? 'bg-brand/[0.07]' : ''" @click="toggleContract(c.id)">
              <!-- Cargo -->
              <td class="px-3 py-2.5 border-l-2" :class="c.at_fleet_city ? (c.fleet_arriving ? 'border-gold/70' : 'border-brand') : 'border-transparent'">
                <div class="flex items-center gap-2 min-w-0">
                  <CommodityBadge :commodity="c.commodity" size="sm" />
                  <span class="truncate text-[12px] font-medium">{{ c.commodity?.name }}</span>
                </div>
              </td>
              <!-- From / To -->
              <td class="px-2 font-medium truncate max-w-[120px]">{{ c.origin?.name }}</td>
              <td class="px-2 font-medium truncate max-w-[120px]"><span class="text-brand">→</span> {{ c.destination?.name }}</td>
              <!-- Status -->
              <td class="px-2 text-center whitespace-nowrap">
                <span v-if="c.at_fleet_city" class="chip text-[9px]" :class="c.fleet_arriving ? 'bg-gold/20 text-gold' : 'bg-gain/20 text-gain'">
                  🚚 {{ c.fleet_arriving ? 'arriving' : 'here' }}
                </span>
                <span v-if="c.is_rush" class="chip text-[9px] bg-loss/20 text-loss ml-0.5">RUSH</span>
                <span v-if="!c.at_fleet_city && !c.is_rush" class="text-slate-600">·</span>
              </td>
              <!-- Distance / Load / ETA -->
              <td class="px-2 text-right font-mono text-[12px] text-slate-300 whitespace-nowrap">{{ num(c.distance_km) }} km</td>
              <td class="px-2 text-right font-mono text-[12px] text-slate-300 whitespace-nowrap">{{ num(c.total_weight ?? 0, 1) }}t</td>
              <td class="px-2 text-right font-mono text-[12px] text-brand-soft whitespace-nowrap">{{ etaText(c.distance_km) }}</td>
              <!-- Value / Profit / Diff -->
              <td class="px-2 text-right font-mono text-gold font-semibold whitespace-nowrap">{{ credits(c.payout) }}</td>
              <td class="px-2 text-right font-mono font-semibold whitespace-nowrap" :class="costBreakdown(c).profit >= 0 ? 'text-gain' : 'text-loss'">
                {{ credits(costBreakdown(c).profit) }}
              </td>
              <td class="px-2 text-center"><DifficultyStars :value="c.difficulty" /></td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button class="btn-primary !py-1 !px-3 text-[11px]" @click.stop="toggleContract(c.id)">
                  {{ expandedContract === c.id ? 'Close' : 'GO →' }}
                </button>
              </td>
            </tr>
            <!-- Dispatch drawer -->
            <tr v-if="expandedContract === c.id" class="bg-ink-900/50 border-b border-white/5">
              <td colspan="11" class="px-3 py-3">
                <div class="grid md:grid-cols-2 gap-3">
                  <!-- Cost breakdown -->
                  <div class="rounded-lg bg-ink-900/60 px-3 py-2 text-[12px] space-y-1">
                    <div class="flex justify-between"><span class="text-slate-400">Fuel {{ costBreakdown(c).hasVehicle ? '(est.)' : '' }}</span><span class="font-mono">{{ costBreakdown(c).hasVehicle ? '−' + credits(costBreakdown(c).fuel) : 'pick a truck' }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Tolls</span><span class="font-mono">−{{ credits(costBreakdown(c).toll) }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Tax ({{ Math.round((c.destination?.tax_rate ?? 0) * 100) }}%)</span><span class="font-mono">−{{ credits(costBreakdown(c).tax) }}</span></div>
                    <div class="flex justify-between pt-1 border-t border-white/10"><span class="font-semibold">Est. profit</span><span class="font-mono font-semibold" :class="costBreakdown(c).profit >= 0 ? 'text-gain' : 'text-loss'">{{ credits(costBreakdown(c).profit) }}</span></div>
                    <p class="text-[10px] text-slate-500">Fails/late penalty: {{ credits(c.penalty) }}</p>
                  </div>
                  <!-- Pick vehicle + (trailer) + driver, then GO -->
                  <div class="space-y-1.5">
                    <select v-model="selection[c.id].vehicle_id" class="input !py-1.5 text-xs">
                      <option :value="null" disabled>Choose vehicle…</option>
                      <option v-for="v in compatibleVehicles(c)" :key="v.id" :value="v.id">{{ fleetTag(v.fleet_no) }} {{ v.nickname || v.model?.name }} · fuel {{ Math.round(v.fuel_pct ?? 100) }}%</option>
                    </select>
                    <select v-if="needsTrailer(c)" v-model="selection[c.id].trailer_id" class="input !py-1.5 text-xs">
                      <option :value="null" disabled>Attach trailer…</option>
                      <option v-for="t in compatibleTrailers(c)" :key="t.id" :value="t.id">{{ t.model?.name }}</option>
                    </select>
                    <select v-model="selection[c.id].driver_id" class="input !py-1.5 text-xs">
                      <option :value="null" disabled>Choose driver…</option>
                      <option v-for="d in compatibleDrivers(c)" :key="d.id" :value="d.id">{{ fleetTag(d.crew_no) }} {{ d.name }} · skill {{ d.skill }}</option>
                    </select>
                    <div class="flex items-center justify-between pt-1">
                      <button class="text-[11px] text-slate-400 hover:text-slate-200" :disabled="accepting === c.id" @click="accept(c)">{{ accepting === c.id ? '…' : 'Claim for later' }}</button>
                      <button class="btn-primary !py-1.5 !px-4" :disabled="dispatching === c.id || !canDispatch(c)" @click="dispatchNow(c)">{{ dispatching === c.id ? '…' : 'GO →' }}</button>
                    </div>
                    <p v-if="!compatibleVehicles(c).length" class="text-[10px] text-loss">No compatible idle vehicle.</p>
                    <p v-else-if="needsTrailer(c) && !compatibleTrailers(c).length" class="text-[10px] text-loss">Needs a matching trailer — buy one in Fleet.</p>
                    <p v-else-if="!compatibleDrivers(c).length" class="text-[10px] text-loss">No available driver — rest or hire crew.</p>
                  </div>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      </div>
      </div>

      <!-- Mobile: compact card list (tap a card to dispatch) -->
      <div class="md:hidden space-y-2">
        <div v-for="c in sortedContracts" :key="c.id" class="glass !p-3"
          :class="c.at_fleet_city ? 'ring-1 ring-brand/40' : ''">
          <div class="cursor-pointer" @click="toggleContract(c.id)">
            <div class="flex items-center justify-between gap-2">
              <div class="flex items-center gap-2 min-w-0">
                <CommodityBadge :commodity="c.commodity" size="sm" />
                <span class="font-semibold text-sm truncate">{{ c.origin?.name }} <span class="text-brand">→</span> {{ c.destination?.name }}</span>
              </div>
              <span class="font-mono text-gold font-semibold text-sm shrink-0">{{ credits(c.payout) }}</span>
            </div>
            <div class="flex items-center gap-1.5 mt-1 flex-wrap">
              <span v-if="c.at_fleet_city" class="chip text-[9px]" :class="c.fleet_arriving ? 'bg-gold/20 text-gold' : 'bg-gain/20 text-gain'">🚚 {{ c.fleet_arriving ? 'arriving' : 'here' }}</span>
              <span v-if="c.is_rush" class="chip text-[9px] bg-loss/20 text-loss">RUSH</span>
              <span class="text-[11px] text-slate-400 truncate">{{ c.commodity?.name }} · {{ num(c.distance_km) }}km · {{ num(c.total_weight ?? 0, 1) }}t · ⏱{{ etaText(c.distance_km) }}</span>
            </div>
            <div class="flex items-center justify-between mt-1.5 text-[12px]">
              <span class="text-slate-400">Profit
                <span class="font-mono font-semibold" :class="costBreakdown(c).profit >= 0 ? 'text-gain' : 'text-loss'">{{ credits(costBreakdown(c).profit) }}</span>
              </span>
              <span class="text-brand-soft font-semibold text-[11px]">{{ expandedContract === c.id ? 'Close ▲' : 'Dispatch →' }}</span>
            </div>
          </div>
          <!-- Expanded dispatch -->
          <div v-if="expandedContract === c.id" class="mt-2 pt-2 border-t border-white/10 space-y-1.5">
            <select v-model="selection[c.id].vehicle_id" class="input !py-1.5 text-xs">
              <option :value="null" disabled>Choose vehicle…</option>
              <option v-for="v in compatibleVehicles(c)" :key="v.id" :value="v.id">{{ fleetTag(v.fleet_no) }} {{ v.nickname || v.model?.name }} · fuel {{ Math.round(v.fuel_pct ?? 100) }}%</option>
            </select>
            <select v-if="needsTrailer(c)" v-model="selection[c.id].trailer_id" class="input !py-1.5 text-xs">
              <option :value="null" disabled>Attach trailer…</option>
              <option v-for="t in compatibleTrailers(c)" :key="t.id" :value="t.id">{{ t.model?.name }}</option>
            </select>
            <select v-model="selection[c.id].driver_id" class="input !py-1.5 text-xs">
              <option :value="null" disabled>Choose driver…</option>
              <option v-for="d in compatibleDrivers(c)" :key="d.id" :value="d.id">{{ fleetTag(d.crew_no) }} {{ d.name }} · skill {{ d.skill }}</option>
            </select>
            <div class="flex items-center justify-between pt-1">
              <button class="text-[11px] text-slate-400" :disabled="accepting === c.id" @click="accept(c)">{{ accepting === c.id ? '…' : 'Claim for later' }}</button>
              <button class="btn-primary !py-1.5 !px-5" :disabled="dispatching === c.id || !canDispatch(c)" @click="dispatchNow(c)">{{ dispatching === c.id ? '…' : 'GO →' }}</button>
            </div>
            <p v-if="!compatibleVehicles(c).length" class="text-[10px] text-loss">No compatible idle vehicle here.</p>
            <p v-else-if="needsTrailer(c) && !compatibleTrailers(c).length" class="text-[10px] text-loss">Needs a matching trailer.</p>
            <p v-else-if="!compatibleDrivers(c).length" class="text-[10px] text-loss">No available driver.</p>
          </div>
        </div>
      </div>
    </div>

    <div v-else class="glass p-10 text-center text-slate-400">
      <p v-if="haulableOnly">No open contracts fit an available truck right now.</p>
      <p v-else>No contracts match those filters. Try widening your search or advancing the world.</p>
      <p v-if="haulableOnly" class="text-xs text-slate-500 mt-2">
        Free up or buy a bigger truck, or untick “Only what my fleet can haul” to see everything.
      </p>
    </div>
   </div>

   <!-- Live fleet side panel -->
   <aside class="lg:w-80 shrink-0 space-y-4 lg:sticky lg:top-20">
     <!-- On the road: route + time left, soonest first -->
     <div class="glass p-4">
       <h3 class="font-semibold text-sm mb-2">
         On the Road <span class="chip bg-brand/15 text-brand-soft ml-1">{{ onTheRoad.length }}</span>
       </h3>
       <div v-if="onTheRoad.length" class="divide-y divide-white/5">
         <div v-for="s in onTheRoad" :key="s.id" class="py-2">
           <p class="text-xs font-medium">{{ s.contract?.origin?.name }} → {{ s.contract?.destination?.name }}</p>
           <p class="font-mono text-[11px] mt-0.5" :class="etaLeft(s) === 'arriving…' ? 'text-gain' : 'text-brand-soft'">
             ⏱ {{ etaLeft(s) }} left
           </p>
         </div>
       </div>
       <p v-else class="text-xs text-slate-500">Nothing en route right now.</p>

       <!-- Totals for everything currently on the road -->
       <div v-if="onTheRoad.length" class="border-t border-white/10 mt-1 pt-2 space-y-1">
         <div class="flex items-center justify-between text-xs">
           <span class="text-slate-400">Total value</span>
           <span class="font-mono text-gold font-semibold">{{ credits(roadTotals.value) }}</span>
         </div>
         <div class="flex items-center justify-between text-xs">
           <span class="text-slate-400">Total est. profit</span>
           <span class="font-mono font-semibold" :class="roadTotals.profit >= 0 ? 'text-gain' : 'text-loss'">
             {{ credits(roadTotals.profit) }}
           </span>
         </div>
       </div>
     </div>

     <!-- Free vehicles and where they're parked -->
     <div class="glass p-4">
       <h3 class="font-semibold text-sm mb-2">
         Free Vehicles <span class="chip bg-gain/15 text-gain ml-1">{{ freeVehicles.length }}</span>
       </h3>
       <div v-if="freeVehicles.length" class="divide-y divide-white/5">
         <div v-for="v in freeVehicles" :key="v.id" class="py-2">
           <p class="text-xs font-medium"><span class="font-mono text-brand-soft mr-1">{{ fleetTag(v.fleet_no) }}</span>{{ v.nickname || v.model?.name }}</p>
           <p class="text-[11px] text-slate-400 mt-0.5">📍 {{ v.city?.name || '—' }}</p>
           <p v-if="vehicleNeed(v)" class="text-[10px] text-gold mt-0.5">🛠 needs: {{ vehicleNeed(v) }}</p>
         </div>
       </div>
       <p v-else class="text-xs text-slate-500">All trucks are out on the road.</p>
     </div>
   </aside>
  </div>
</template>
