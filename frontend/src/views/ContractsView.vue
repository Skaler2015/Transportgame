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
// Filters can be hidden/shown on any screen. Start open on desktop, collapsed
// on mobile to save space.
const filtersOpen = ref(typeof window !== 'undefined' && window.matchMedia('(min-width: 640px)').matches)
const activeFilterCount = computed(() =>
  (filters.value.origin_city_id ? 1 : 0)
  + (filters.value.commodity_id ? 1 : 0)
  + (dropdownSort.value !== 'eta' ? 1 : 0),
)
const haulableOnly = ref(true)
// Show only jobs a rig can be dispatched on with one tap (GO), hiding the
// ones that would need the drawer to pick a vehicle/driver.
const goOnly = ref(false)
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
const deliveredToday = ref(0)
type TodayStats = { on_time: number; late: number; failed: number; revenue: number; best_route: { label: string; amount: number; trips: number } | null }
const today = ref<TodayStats>({ on_time: 0, late: 0, failed: 0, revenue: 0, best_route: null })
async function loadShipments() {
  try {
    const { data } = await api.get('/shipments')
    shipments.value = data.data
    deliveredToday.value = data.delivered_today ?? 0
    if (data.today) today.value = data.today
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
// What service a vehicle needs — each item is a one-click fix from the panel.
function vehicleNeeds(v: Vehicle): string[] {
  return [
    ((v.condition ?? 100) < 70 || (v.tire_wear ?? 0) > 40) ? 'repair' : '',
    (v.oil_level ?? 100) < 40 ? 'oil' : '',
    (v.battery ?? 100) < 40 ? 'battery' : '',
    !v.is_insured ? 'insurance' : '',
    !v.is_registered ? 'registration' : '',
  ].filter(Boolean)
}
const NEED_ICON: Record<string, string> = { repair: '🔧', oil: '🛢', battery: '🔋', insurance: '🛡', registration: '📋' }
// What a given fix costs this vehicle right now (₡ cents), for the chip label.
function needCost(v: Vehicle, need: string): number {
  return v.service_costs?.[need as keyof NonNullable<Vehicle['service_costs']>] ?? 0
}
// Free vehicles that need any service — for the mobile alert banner, which
// expands inline so a truck can be serviced right there (no scrolling away).
// Cheapest total repair on top, so the quickest wins to clear are first.
const vehiclesNeedingFix = computed(() =>
  freeVehicles.value
    .filter((v) => vehicleNeeds(v).length > 0)
    .sort((a, b) => vehicleFixTotal(a) - vehicleFixTotal(b)),
)
const serviceOpen = ref(false)
// Total cost to fix one vehicle (sum of its needed services), and the grand
// total to fix every free vehicle that needs service.
function vehicleFixTotal(v: Vehicle): number {
  return vehicleNeeds(v).reduce((sum, need) => sum + needCost(v, need), 0)
}
// Every trailer that isn't on the road — including worn-out ones (condition
// too low to count as "available"), so the player can repair them from here.
const freeTrailers = computed(() => trailers.value.filter((t) => t.status !== 'en_route'))
function trailerNeedsRepair(t: Trailer): boolean {
  return (t.condition ?? 100) < 90
}
// Idle trailers that need a repair — folded into the "need service" alert.
const trailersNeedingFix = computed(() => freeTrailers.value.filter((t) => trailerNeedsRepair(t)))
// Combined bill to fix every listed vehicle AND trailer.
const allFixTotal = computed(() =>
  vehiclesNeedingFix.value.reduce((sum, v) => sum + vehicleFixTotal(v), 0)
  + trailersNeedingFix.value.reduce((sum, t) => sum + (t.repair_cost ?? 0), 0),
)
const servingTrailer = ref<number | null>(null)
async function fixTrailer(t: Trailer) {
  servingTrailer.value = t.id
  try {
    await api.post(`/trailers/${t.id}/repair`)
    toast.success(`🔧 Repaired ${t.nickname || t.model?.name}.`)
    await loadFleet()
    game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { servingTrailer.value = null }
}
const servingVeh = ref<number | null>(null)
const bulkFixing = ref(false)
// Run every needed service on one vehicle in sequence, then refresh once.
async function fixAllForVehicle(v: Vehicle) {
  servingVeh.value = v.id
  try {
    for (const need of vehicleNeeds(v)) {
      if (need === 'repair') await api.post(`/vehicles/${v.id}/repair`)
      else await api.post(`/vehicles/${v.id}/service`, { type: need })
    }
    toast.success(`${fleetTag(v.fleet_no)} fully serviced.`)
    await loadFleet(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)); await loadFleet().catch(() => {}) } finally { servingVeh.value = null }
}
// Service every vehicle that needs it, one after another (stops on an error,
// e.g. out of cash), then refresh.
async function fixAllVehicles() {
  bulkFixing.value = true
  try {
    for (const v of [...vehiclesNeedingFix.value]) {
      for (const need of vehicleNeeds(v)) {
        if (need === 'repair') await api.post(`/vehicles/${v.id}/repair`)
        else await api.post(`/vehicles/${v.id}/service`, { type: need })
      }
    }
    // Repair worn trailers too, so "Fix all" clears the whole alert.
    for (const t of [...trailersNeedingFix.value]) {
      await api.post(`/trailers/${t.id}/repair`)
    }
    toast.success('All vehicles & trailers serviced.')
    await loadFleet(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)); await loadFleet().catch(() => {}) } finally { bulkFixing.value = false }
}
// One click fixes exactly what was clicked, right from the Free Vehicles panel.
async function fixNeed(v: Vehicle, need: string) {
  servingVeh.value = v.id
  try {
    if (need === 'repair') await api.post(`/vehicles/${v.id}/repair`)
    else await api.post(`/vehicles/${v.id}/service`, { type: need })
    toast.success(`${NEED_ICON[need]} ${need} done for ${v.nickname || v.model?.name}.`)
    await loadFleet()
    game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { servingVeh.value = null }
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
// Current cash, and what it becomes once all on-road profit lands.
const currentCash = computed(() => game.dashboard?.company?.cash ?? 0)
const projectedCash = computed(() => currentCash.value + roadTotals.value.profit)
// The longest wait among the running contracts (onTheRoad is soonest-first,
// so the last one has the furthest ETA).
const maxTimeLeft = computed(() => {
  const last = onTheRoad.value[onTheRoad.value.length - 1]
  return last ? etaLeft(last) : '—'
})
// Soonest arrival (onTheRoad is sorted soonest-first → the first element).
const minTimeLeft = computed(() => {
  const first = onTheRoad.value[0]
  return first ? etaLeft(first) : '—'
})
// Crew at a glance: free (available), busy (driving), resting.
const driverSummary = computed(() => ({
  free: drivers.value.filter((d) => d.status === 'available').length,
  busy: drivers.value.filter((d) => d.status === 'driving').length,
  resting: drivers.value.filter((d) => d.status === 'resting').length,
}))
// Trucks at a glance + how much of the fleet is earning right now.
const fleetStatus = computed(() => {
  const idle = vehicles.value.filter((v) => v.status === 'idle').length
  const enRoute = vehicles.value.filter((v) => v.status === 'en_route').length
  const maint = vehicles.value.filter((v) => v.status === 'maintenance').length
  const total = vehicles.value.length
  return { idle, enRoute, maint, total, util: total ? Math.round((enRoute / total) * 100) : 0 }
})
const trailerStatus = computed(() => ({
  inUse: trailers.value.filter((t) => t.status === 'en_route').length,
  free: trailers.value.filter((t) => t.status !== 'en_route').length,
}))
// Costs, margin and averages for everything currently rolling.
const roadCosts = computed(() => roadTotals.value.value - roadTotals.value.profit)
const avgProfit = computed(() => onTheRoad.value.length ? Math.round(roadTotals.value.profit / onTheRoad.value.length) : 0)
const profitMargin = computed(() => roadTotals.value.value > 0 ? Math.round((roadTotals.value.profit / roadTotals.value.value) * 100) : 0)
const totalKmOnRoad = computed(() => Math.round(onTheRoad.value.reduce((s, x) => s + (x.distance_km || 0), 0)))
const totalTonnageOnRoad = computed(() => onTheRoad.value.reduce((s, x) => s + (x.contract?.total_weight ?? 0), 0))
// Average time left, arriving-soon count, at-risk (will miss deadline) and low fuel.
const avgTimeLeft = computed(() => {
  if (!onTheRoad.value.length) return '—'
  const total = onTheRoad.value.reduce((s, x) => s + Math.max(0, new Date(x.eta_at).getTime() - now.value), 0)
  const secs = Math.round(total / onTheRoad.value.length / 1000)
  const m = Math.floor(secs / 60), sec = secs % 60
  return m ? `${m}m ${sec}s` : `${sec}s`
})
const arrivingSoon = computed(() => onTheRoad.value.filter((x) => {
  const ms = new Date(x.eta_at).getTime() - now.value
  return ms > 0 && ms <= 60000
}).length)
const atRiskCount = computed(() => onTheRoad.value.filter((x) => {
  const d = x.contract?.deadline_at
  return d && new Date(x.eta_at).getTime() > new Date(d).getTime()
}).length)
const lowFuelCount = computed(() => onTheRoad.value.filter((x) => (x.vehicle?.fuel_pct ?? 100) < 20).length)
// The commodity making up the biggest share of what's on the road.
const topCommodity = computed(() => {
  const tally: Record<string, number> = {}
  for (const s of onTheRoad.value) {
    const name = s.contract?.commodity?.name
    if (name) tally[name] = (tally[name] ?? 0) + 1
  }
  const top = Object.entries(tally).sort((a, b) => b[1] - a[1])[0]
  return top ? { name: top[0], count: top[1] } : null
})
// Today's on-time rate (of settled deliveries) for the performance line.
const onTimePct = computed(() => {
  const done = today.value.on_time + today.value.late + today.value.failed
  return done ? Math.round((today.value.on_time / done) * 100) : 100
})

// Contract table: expand-to-dispatch drawer + client-side sort on any column.
const expandedContract = ref<number | null>(null)
function toggleContract(id: number) {
  const opening = expandedContract.value !== id
  expandedContract.value = opening ? id : null
  // Opening a job? Pre-pick the best rig so the player can just hit GO.
  if (opening) {
    const c = contracts.value.find((x) => x.id === id)
    if (c) autoSelect(c)
  }
}

// Pick the strongest sensible rig for a contract: the tightest-fitting truck
// with the most fuel/best condition, the smallest trailer that carries it,
// and the most skilled available driver — so one tap on GO dispatches it.
// A vehicle we could actually dispatch this job with right now: either it
// self-hauls, or it needs a trailer AND a compatible trailer is available.
function dispatchableNow(v: Vehicle, c: Contract): boolean {
  return !v.model?.needs_trailer || compatibleTrailers(c).length > 0
}
function bestVehicle(c: Contract): Vehicle | undefined {
  return [...compatibleVehicles(c)].sort((a, b) => {
    // Prefer a truck we can dispatch immediately over a tractor with no trailer.
    const da = dispatchableNow(a, c) ? 0 : 1, db = dispatchableNow(b, c) ? 0 : 1
    if (da !== db) return da - db
    const capA = a.model?.capacity_weight ?? 0, capB = b.model?.capacity_weight ?? 0
    if (capA !== capB) return capA - capB // conserve big trucks — smallest that fits
    const fuel = (b.fuel_pct ?? 100) - (a.fuel_pct ?? 100)
    if (fuel) return fuel
    return (b.condition ?? 100) - (a.condition ?? 100)
  })[0]
}
function bestTrailer(c: Contract): Trailer | undefined {
  return [...compatibleTrailers(c)].sort(
    (a, b) => (a.model?.capacity_weight ?? 0) - (b.model?.capacity_weight ?? 0),
  )[0]
}
function bestDriver(c: Contract): Driver | undefined {
  return [...compatibleDrivers(c)].sort((a, b) => {
    const skill = (b.skill ?? 0) - (a.skill ?? 0)
    if (skill) return skill
    return (b.morale ?? 0) - (a.morale ?? 0) || (a.fatigue ?? 0) - (b.fatigue ?? 0)
  })[0]
}
function autoSelect(c: Contract) {
  if (!selection.value[c.id]) selection.value[c.id] = { vehicle_id: null, trailer_id: null, driver_id: null }
  const sel = selection.value[c.id]
  if (!sel.vehicle_id) sel.vehicle_id = bestVehicle(c)?.id ?? null
  if (!sel.driver_id) sel.driver_id = bestDriver(c)?.id ?? null
  // Trailer only matters when the chosen tractor needs one.
  if (!sel.trailer_id && needsTrailer(c)) sel.trailer_id = bestTrailer(c)?.id ?? null
}

type CSortKey = 'cargo' | 'from' | 'to' | 'status' | 'dist' | 'load' | 'eta' | 'value' | 'profit' | 'permin' | 'diff'
// Estimated travel time (seconds/minutes) for a lane, mirroring etaText.
function etaSeconds(km: number): number {
  return Math.max(20, Math.round((km / KM_PER_MIN) * 60))
}
// The real efficiency metric: profit earned per minute the truck is busy.
function profitPerMin(c: Contract): number {
  return costBreakdown(c).profit / (etaSeconds(c.distance_km ?? 0) / 60)
}
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
  cSort.value = { k, dir: (k === 'value' || k === 'profit' || k === 'permin') ? 'desc' : 'asc' }
  // Fetch the matching slice from the server, then client-sort it.
  filters.value.sort = (k === 'value' || k === 'profit' || k === 'permin') ? 'payout' : k === 'diff' ? 'difficulty' : 'distance_km'
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
    case 'permin': return profitPerMin(c)
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
// What actually renders — optionally narrowed to one-tap-GO jobs only.
const visibleContracts = computed(() =>
  goOnly.value ? sortedContracts.value.filter((c) => readyToGo(c)) : sortedContracts.value,
)
// How many jobs on the board have a one-tap GO, and how many I could actually
// dispatch right now (capped by free drivers and idle trucks).
const goReadyCount = computed(() => sortedContracts.value.filter((c) => readyToGo(c)).length)
const canGoNow = computed(() => Math.min(goReadyCount.value, driverSummary.value.free, fleetStatus.value.idle))

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
// Ready to roll: a dispatchable rig exists (truck at origin + trailer if the
// tractor needs one + a driver) — so we can show a one-tap GO on the row.
function readyToGo(c: Contract): boolean {
  const v = bestVehicle(c)
  return !!v && dispatchableNow(v, c) && !!bestDriver(c)
}
// One tap on the row: refresh the fleet (so availability is current, not the
// 15s-stale poll), auto-pick the best rig from fresh data, then dispatch. If a
// truck/driver just became busy, open the drawer instead of failing.
async function quickDispatch(c: Contract) {
  dispatching.value = c.id
  await loadFleet().catch(() => {})
  selection.value[c.id] = { vehicle_id: null, trailer_id: null, driver_id: null }
  autoSelect(c)
  if (!canDispatch(c)) {
    dispatching.value = null
    expandedContract.value = c.id
    return
  }
  await dispatchNow(c)
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
    // The one-tap GO relied on possibly-stale availability — refresh the fleet
    // so a now-busy truck/driver stops showing GO, and open the drawer so the
    // player can pick another rig.
    await loadFleet().catch(() => {})
    expandedContract.value = c.id
  } finally {
    dispatching.value = null
  }
}

// Persist the player's chosen filters/sort so the board opens the same way
// next time. Saved locally on this device.
const FILTER_STORE_KEY = 'transoria:contract-filters'
const savedFilters = ref(false)
function saveFilters() {
  try {
    localStorage.setItem(FILTER_STORE_KEY, JSON.stringify({
      origin_city_id: filters.value.origin_city_id,
      commodity_id: filters.value.commodity_id,
      sort: dropdownSort.value,
      hqOnly: hqOnly.value,
      backhaulOnly: backhaulOnly.value,
      haulableOnly: haulableOnly.value,
      goOnly: goOnly.value,
    }))
    savedFilters.value = true
    toast.success('Filters saved — they’ll load automatically next time.')
    window.setTimeout(() => { savedFilters.value = false }, 2000)
  } catch { toast.error('Could not save filters on this device.') }
}
function loadSavedFilters(): boolean {
  try {
    const raw = localStorage.getItem(FILTER_STORE_KEY)
    if (!raw) return false
    const s = JSON.parse(raw)
    filters.value.origin_city_id = s.origin_city_id ?? ''
    filters.value.commodity_id = s.commodity_id ?? ''
    dropdownSort.value = s.sort ?? 'eta'
    hqOnly.value = !!s.hqOnly
    backhaulOnly.value = s.backhaulOnly ?? true
    haulableOnly.value = s.haulableOnly ?? true
    goOnly.value = !!s.goOnly
    // Mirror the sort into cSort + the server sort param.
    cSort.value = { k: dropdownSort.value, dir: (['value', 'profit', 'permin'].includes(dropdownSort.value)) ? 'desc' : 'asc' }
    filters.value.sort = (['value', 'profit', 'permin'].includes(dropdownSort.value)) ? 'payout'
      : dropdownSort.value === 'diff' ? 'difficulty' : 'distance_km'
    return true
  } catch { return false }
}

onMounted(async () => {
  loadSavedFilters()
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
    </div>

    <!-- Filters — collapsible on mobile (tap to show/hide), always open on
         desktop. Body is a 2-col grid on mobile, flex row on ≥sm via
         `sm:contents` (the wrappers dissolve). -->
    <div class="glass p-3 sm:p-4">
      <!-- Show/hide the whole filter panel at will, on any screen. -->
      <button type="button"
        class="w-full flex items-center justify-between gap-2"
        :class="filtersOpen ? 'mb-3' : ''"
        @click="filtersOpen = !filtersOpen">
        <span class="text-sm font-semibold flex items-center gap-2">
          🔍 Filters
          <span v-if="activeFilterCount" class="chip bg-brand/15 text-brand-soft">{{ activeFilterCount }}</span>
        </span>
        <span class="text-xs text-brand-soft">{{ filtersOpen ? 'Hide ▲' : 'Show ▼' }}</span>
      </button>

      <div :class="filtersOpen ? 'flex' : 'hidden'"
        class="flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:gap-3 sm:items-end">
      <div class="grid grid-cols-2 gap-2 sm:contents">
        <div class="sm:flex-1 sm:min-w-[160px]">
          <label class="stat-label">Origin</label>
          <select v-model="filters.origin_city_id" class="input mt-0.5 sm:mt-1" @change="hqOnly = String(filters.origin_city_id) === String(hqCityId ?? ''); load()">
            <option value="">Any city</option>
            <option v-for="c in sortedCities" :key="c.id" :value="c.id">{{ c.name }}</option>
          </select>
        </div>
        <div class="sm:flex-1 sm:min-w-[160px]">
          <label class="stat-label">Commodity</label>
          <select v-model="filters.commodity_id" class="input mt-0.5 sm:mt-1" @change="load()">
            <option value="">Any cargo</option>
            <option v-for="k in game.commodities" :key="k.id" :value="k.id">{{ k.name }}</option>
          </select>
        </div>
        <div class="col-span-2 flex items-end gap-2 sm:contents">
          <div class="flex-1 sm:min-w-[140px]">
            <label class="stat-label">Sort by</label>
            <select v-model="dropdownSort" class="input mt-0.5 sm:mt-1" @change="applyDropdownSort">
              <option value="eta">Soonest ETA</option>
              <option value="value">Highest payout</option>
              <option value="profit">Best profit</option>
              <option value="permin">Best ₹/min</option>
              <option value="diff">Easiest</option>
            </select>
          </div>
          <button class="btn-ghost shrink-0" @click="load()">↻ Refresh</button>
          <button class="btn-ghost shrink-0" :class="savedFilters ? '!text-gain !border-gain/40' : ''" @click="saveFilters">
            {{ savedFilters ? '✓ Saved' : '💾 Save' }}
          </button>
        </div>
      </div>
      <div class="flex flex-wrap gap-x-4 gap-y-1.5 sm:contents">
        <label v-if="hqCityId" class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none sm:ml-auto"
          title="Only jobs leaving your HQ — where your new trucks are parked.">
          <input type="checkbox" v-model="hqOnly" class="accent-brand h-4 w-4" @change="toggleHq" />
          🏭 From my HQ
        </label>
        <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none"
          :class="hqCityId ? '' : 'sm:ml-auto'"
          title="Jobs starting in a city where one of your trucks is parked — or heading right now — so it never runs back empty.">
          <input type="checkbox" v-model="backhaulOnly" class="accent-brand h-4 w-4" @change="load()" />
          🚚 Backhaul from my trucks
        </label>
        <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none">
          <input type="checkbox" v-model="haulableOnly" class="accent-brand h-4 w-4" @change="load()" />
          Only what my fleet can haul
        </label>
        <label class="flex items-center gap-2 text-xs text-slate-300 cursor-pointer select-none"
          title="Show only jobs you can dispatch in one tap with GO.">
          <input type="checkbox" v-model="goOnly" class="accent-brand h-4 w-4" />
          ⚡ Ready to GO only
        </label>
      </div>
      </div>
    </div>

    <!-- Alert (all screens): free vehicles & trailers that need service. Tap to
         expand and fix each in place — no scrolling to another panel. -->
    <div v-if="vehiclesNeedingFix.length || trailersNeedingFix.length" class="glass !p-3 ring-1 ring-gold/40">
      <div class="w-full flex items-center justify-between gap-2">
        <button type="button" class="text-sm text-gold text-left flex-1 min-w-0" @click="serviceOpen = !serviceOpen">
          🛠 {{ vehiclesNeedingFix.length }} vehicle<template v-if="trailersNeedingFix.length"> · {{ trailersNeedingFix.length }} trailer</template>(s) need service
          <span class="font-mono">· {{ credits(allFixTotal) }}</span>
        </button>
        <button type="button" class="btn-primary !py-1 !px-3 text-[11px] shrink-0"
          :disabled="bulkFixing || servingVeh !== null" @click="fixAllVehicles">
          {{ bulkFixing ? 'Fixing…' : '🔧 Fix all' }}
        </button>
        <button type="button" class="text-xs text-brand-soft shrink-0 px-1" @click="serviceOpen = !serviceOpen">
          {{ serviceOpen ? '▲' : '▼' }}
        </button>
      </div>
      <div v-if="serviceOpen" class="mt-2 pt-2 border-t border-white/10 divide-y divide-white/5">
        <div v-for="v in vehiclesNeedingFix" :key="v.id" class="py-2">
          <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-medium truncate">
              <span class="font-mono text-brand-soft mr-1">{{ fleetTag(v.fleet_no) }}</span>{{ v.nickname || v.model?.name }}
            </p>
            <span class="text-[11px] text-slate-400 shrink-0">📍 {{ v.city?.name || '—' }}</span>
          </div>
          <div class="flex items-center gap-2 text-[10px] text-slate-400 mt-0.5">
            <span>🔧 {{ Math.round(v.condition ?? 100) }}%</span>
            <span>🛢 {{ Math.round(v.oil_level ?? 100) }}%</span>
            <span>🔋 {{ Math.round(v.battery ?? 100) }}%</span>
            <span>⛽ {{ Math.round(v.fuel_pct ?? 100) }}%</span>
          </div>
          <div class="flex flex-wrap items-center gap-1 mt-1">
            <button v-for="need in vehicleNeeds(v)" :key="need"
              class="chip bg-gold/15 text-gold hover:bg-gold/25 text-[10px] capitalize disabled:opacity-40"
              :disabled="servingVeh === v.id" @click="fixNeed(v, need)">
              {{ NEED_ICON[need] }} {{ servingVeh === v.id ? '…' : need }}<span v-if="needCost(v, need)" class="font-mono ml-1 normal-case">{{ credits(needCost(v, need)) }}</span>
            </button>
            <button type="button"
              class="chip bg-brand/20 text-brand-soft hover:bg-brand/30 text-[10px] ml-auto disabled:opacity-40"
              :disabled="servingVeh === v.id || bulkFixing" @click="fixAllForVehicle(v)">
              {{ servingVeh === v.id ? 'Fixing…' : '🔧 Fix all' }}<span class="font-mono ml-1">{{ credits(vehicleFixTotal(v)) }}</span>
            </button>
          </div>
        </div>
        <!-- Trailers that need a repair, right in the same alert. -->
        <div v-for="t in trailersNeedingFix" :key="'t'+t.id" class="py-2">
          <div class="flex items-center justify-between gap-2">
            <p class="text-xs font-medium truncate">🚚 {{ t.nickname || t.model?.name }}</p>
            <span class="text-[11px] shrink-0" :class="'text-loss'">🔧 {{ Math.round(t.condition ?? 100) }}%</span>
          </div>
          <div class="flex items-center gap-1 mt-1">
            <span class="text-[11px] text-slate-400">📍 {{ t.city?.name || '—' }}</span>
            <button type="button"
              class="chip bg-gold/15 text-gold hover:bg-gold/25 text-[10px] ml-auto disabled:opacity-40"
              :disabled="servingTrailer === t.id || bulkFixing" @click="fixTrailer(t)">
              🔧 {{ servingTrailer === t.id ? 'Repairing…' : 'Repair' }}<span v-if="t.repair_cost" class="font-mono ml-1">{{ credits(t.repair_cost) }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Mobile: compact On-the-Road summary, right above the contracts.
         Just the four headline numbers — no route list, keeps it tiny. -->
    <div v-if="onTheRoad.length" class="lg:hidden glass !p-3">
      <div class="flex items-center justify-between gap-2">
        <span class="text-sm font-semibold flex items-center gap-2">
          On the Road <span class="chip bg-brand/15 text-brand-soft">{{ onTheRoad.length }}</span>
          <span class="text-[10px] font-normal text-gain">✅ {{ deliveredToday }} today</span>
        </span>
        <span class="font-mono text-xs" :class="roadTotals.profit >= 0 ? 'text-gain' : 'text-loss'">
          {{ credits(roadTotals.profit) }} profit
        </span>
      </div>
      <div class="grid grid-cols-2 gap-x-3 gap-y-1 mt-2.5 text-[11px]">
        <div class="flex items-center justify-between">
          <span class="text-slate-400">Value</span>
          <span class="font-mono text-gold font-semibold">{{ credits(roadTotals.value) }}</span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-400">Shortest</span>
          <span class="font-mono text-gain font-semibold">⏱ {{ minTimeLeft }}</span>
        </div>
        <div class="flex items-center justify-between">
          <span class="text-slate-400">Longest</span>
          <span class="font-mono text-slate-200 font-semibold">⏱ {{ maxTimeLeft }}</span>
        </div>
        <div class="flex items-center justify-between col-span-2 border-t border-white/10 pt-1">
          <span class="text-slate-400">Cash after these land</span>
          <span class="font-mono text-brand-soft font-semibold">{{ credits(projectedCash) }}</span>
        </div>
        <div class="flex items-center justify-between col-span-2">
          <span class="text-slate-400">Drivers</span>
          <span class="font-mono font-semibold">
            <span class="text-gain">{{ driverSummary.free }} free</span> ·
            <span class="text-brand-soft">{{ driverSummary.busy }} busy</span> ·
            <span class="text-slate-400">{{ driverSummary.resting }} rest</span>
          </span>
        </div>
        <div class="flex items-center justify-between col-span-2">
          <span class="text-slate-400">Trucks</span>
          <span class="font-mono"><span class="text-gain">{{ fleetStatus.idle }} idle</span> · <span class="text-brand-soft">{{ fleetStatus.enRoute }} out</span> · <span class="text-loss">{{ fleetStatus.maint }} shop</span> · {{ fleetStatus.util }}% util</span>
        </div>
        <div v-if="today.revenue" class="flex items-center justify-between col-span-2">
          <span class="text-slate-400">Revenue today</span>
          <span class="font-mono text-gold font-semibold">{{ credits(today.revenue) }}</span>
        </div>
        <div class="flex items-center justify-between col-span-2">
          <span class="text-slate-400">+ on-road value</span>
          <span class="font-mono text-gain font-semibold">{{ credits(today.revenue + roadTotals.value) }}</span>
        </div>
        <div v-if="atRiskCount || lowFuelCount" class="flex items-center justify-between col-span-2">
          <span class="text-loss">🚨 Alerts</span>
          <span class="font-mono text-loss font-semibold">
            <template v-if="atRiskCount">⚠️ {{ atRiskCount }} late-risk</template>
            <template v-if="atRiskCount && lowFuelCount"> · </template>
            <template v-if="lowFuelCount">⛽ {{ lowFuelCount }} low fuel</template>
          </span>
        </div>
      </div>

      <!-- Everything else, tucked behind a tap so the card stays small. -->
      <details class="mt-2 border-t border-white/10 pt-1.5">
        <summary class="text-[11px] text-brand-soft cursor-pointer list-none">More stats ▾</summary>
        <div class="grid grid-cols-2 gap-x-3 gap-y-1 mt-2 text-[11px]">
          <div class="flex items-center justify-between"><span class="text-slate-400">Costs</span><span class="font-mono text-loss">−{{ credits(roadCosts) }}</span></div>
          <div class="flex items-center justify-between"><span class="text-slate-400">Margin</span><span class="font-mono text-slate-200">{{ profitMargin }}%</span></div>
          <div class="flex items-center justify-between"><span class="text-slate-400">Avg/delivery</span><span class="font-mono text-slate-200">{{ credits(avgProfit) }}</span></div>
          <div class="flex items-center justify-between"><span class="text-slate-400">Avg left</span><span class="font-mono text-slate-200">⏱ {{ avgTimeLeft }}</span></div>
          <div class="flex items-center justify-between"><span class="text-slate-400">On road</span><span class="font-mono text-slate-200">{{ num(totalKmOnRoad) }}km</span></div>
          <div class="flex items-center justify-between"><span class="text-slate-400">Tonnage</span><span class="font-mono text-slate-200">{{ num(totalTonnageOnRoad, 1) }}t</span></div>
          <div class="flex items-center justify-between col-span-2"><span class="text-slate-400">Trailers</span><span class="font-mono text-slate-200"><span class="text-gain">{{ trailerStatus.free }} free</span> · {{ trailerStatus.inUse }} in use</span></div>
          <div class="flex items-center justify-between col-span-2"><span class="text-slate-400">Delivered today</span><span class="font-mono"><span class="text-gain">{{ today.on_time }} ok</span> · <span class="text-gold">{{ today.late }} late</span> · <span class="text-loss">{{ today.failed }} fail</span> ({{ onTimePct }}%)</span></div>
          <div v-if="today.best_route" class="flex items-center justify-between col-span-2"><span class="text-slate-400">Best lane</span><span class="font-mono text-slate-200 truncate ml-2">{{ today.best_route.label }} · {{ credits(today.best_route.amount) }}</span></div>
          <div v-if="topCommodity" class="flex items-center justify-between col-span-2"><span class="text-slate-400">Top cargo</span><span class="font-mono text-slate-200">{{ topCommodity.name }} ×{{ topCommodity.count }}</span></div>
        </div>
      </details>
    </div>

    <!-- At-a-glance board counts, right above the contracts. -->
    <div v-if="!loading && contracts.length" class="glass !p-2.5 grid grid-cols-3 divide-x divide-white/10 text-center">
      <div>
        <p class="text-[10px] uppercase tracking-wider text-slate-500">On board</p>
        <p class="font-bold text-sm">{{ contracts.length }}</p>
      </div>
      <div>
        <p class="text-[10px] uppercase tracking-wider text-slate-500">⚡ GO-ready</p>
        <p class="font-bold text-sm text-brand-soft">{{ goReadyCount }}</p>
      </div>
      <div>
        <p class="text-[10px] uppercase tracking-wider text-slate-500">Can GO now</p>
        <p class="font-bold text-sm text-gain">{{ canGoNow }}</p>
      </div>
    </div>

    <div v-if="loading" class="grid place-items-center h-64 text-slate-500">Loading market…</div>

    <!-- Compact, sortable contract table. Click a row's GO to dispatch. -->
    <div v-else-if="visibleContracts.length">
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
          <template v-for="c in visibleContracts" :key="c.id">
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
                <div class="text-[10px] text-brand-soft font-normal">{{ credits(profitPerMin(c)) }}/min</div>
              </td>
              <td class="px-2 text-center"><DifficultyStars :value="c.difficulty" /></td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <template v-if="expandedContract === c.id">
                  <button class="btn-ghost !py-1 !px-3 text-[11px]" @click.stop="toggleContract(c.id)">Close</button>
                </template>
                <template v-else-if="readyToGo(c)">
                  <span class="inline-flex items-center gap-1">
                    <button class="btn-primary !py-1 !px-3 text-[11px]" :disabled="dispatching === c.id" @click.stop="quickDispatch(c)">
                      {{ dispatching === c.id ? '…' : 'GO →' }}
                    </button>
                    <button class="btn-ghost !py-1 !px-2 text-[11px]" title="Choose vehicle/driver" @click.stop="toggleContract(c.id)">⚙</button>
                  </span>
                </template>
                <template v-else>
                  <button class="btn-ghost !py-1 !px-3 text-[11px]" @click.stop="toggleContract(c.id)">Dispatch →</button>
                </template>
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
        <div v-for="c in visibleContracts" :key="c.id" class="glass !p-3"
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
                <span class="text-[10px] text-brand-soft ml-1">· {{ credits(profitPerMin(c)) }}/min</span>
              </span>
              <!-- Ready jobs get a one-tap GO right here; others expand to choose. -->
              <span v-if="expandedContract === c.id" class="text-brand-soft font-semibold text-[11px]">Close ▲</span>
              <span v-else-if="readyToGo(c)" class="inline-flex items-center gap-1.5 shrink-0">
                <button class="btn-ghost !py-1 !px-2 text-[11px]" title="Choose vehicle/driver" @click.stop="toggleContract(c.id)">⚙</button>
                <button class="btn-primary !py-1 !px-4 text-[11px]" :disabled="dispatching === c.id" @click.stop="quickDispatch(c)">{{ dispatching === c.id ? '…' : 'GO →' }}</button>
              </span>
              <span v-else class="text-brand-soft font-semibold text-[11px]">Dispatch →</span>
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
      <p v-if="goOnly">No jobs are ready for one-tap GO right now.</p>
      <p v-else-if="haulableOnly">No open contracts fit an available truck right now.</p>
      <p v-else>No contracts match those filters. Try widening your search or advancing the world.</p>
      <p v-if="goOnly" class="text-xs text-slate-500 mt-2">
        Untick “⚡ Ready to GO only” to see jobs you can set up manually.
      </p>
      <p v-else-if="haulableOnly" class="text-xs text-slate-500 mt-2">
        Free up or buy a bigger truck, or untick “Only what my fleet can haul” to see everything.
      </p>
    </div>
   </div>

   <!-- Live fleet side panel -->
   <aside class="lg:w-96 shrink-0 space-y-4 lg:sticky lg:top-20">
     <!-- On the road: route + time left, soonest first (desktop; mobile uses
          the compact summary above the contracts instead). -->
     <div class="hidden lg:block glass p-4">
       <h3 class="font-semibold text-sm mb-2 flex items-center justify-between gap-2">
         <span>On the Road <span class="chip bg-brand/15 text-brand-soft ml-1">{{ onTheRoad.length }}</span></span>
         <span class="text-[11px] font-normal text-gain">✅ {{ deliveredToday }} done today</span>
       </h3>

       <!-- Full command-centre readout: money, time, cargo, fleet, today. -->
       <div v-if="onTheRoad.length" class="rounded-lg bg-white/5 p-2.5 space-y-1 mb-3 [&_.row]:flex [&_.row]:items-center [&_.row]:justify-between [&_.row]:text-xs [&_.sep]:border-t [&_.sep]:border-white/10 [&_.sep]:pt-1 [&_.sep]:mt-1">
         <p class="stat-label !text-[9px] text-brand-soft">💰 Money</p>
         <div class="row"><span class="text-slate-400">Total value</span><span class="font-mono text-gold font-semibold">{{ credits(roadTotals.value) }}</span></div>
         <div class="row"><span class="text-slate-400">Est. profit</span><span class="font-mono font-semibold" :class="roadTotals.profit >= 0 ? 'text-gain' : 'text-loss'">{{ credits(roadTotals.profit) }}</span></div>
         <div class="row"><span class="text-slate-400">Costs (fuel+tolls+tax)</span><span class="font-mono text-loss">−{{ credits(roadCosts) }}</span></div>
         <div class="row"><span class="text-slate-400">Avg profit / delivery</span><span class="font-mono text-slate-200">{{ credits(avgProfit) }}</span></div>
         <div class="row"><span class="text-slate-400">Profit margin</span><span class="font-mono text-slate-200">{{ profitMargin }}%</span></div>
         <div class="row"><span class="text-slate-400">Cash after these land</span><span class="font-mono text-brand-soft font-semibold">{{ credits(projectedCash) }}</span></div>

         <p class="stat-label !text-[9px] text-brand-soft sep">⏱ Time</p>
         <div class="row"><span class="text-slate-400">Shortest / Longest</span><span class="font-mono text-slate-200"><span class="text-gain">{{ minTimeLeft }}</span> · {{ maxTimeLeft }}</span></div>
         <div class="row"><span class="text-slate-400">Average left</span><span class="font-mono text-slate-200">⏱ {{ avgTimeLeft }}</span></div>
         <div class="row"><span class="text-slate-400">Arriving &lt; 1 min</span><span class="font-mono" :class="arrivingSoon ? 'text-gain font-semibold' : 'text-slate-400'">{{ arrivingSoon }}</span></div>

         <p class="stat-label !text-[9px] text-brand-soft sep">📦 Cargo</p>
         <div class="row"><span class="text-slate-400">On the road</span><span class="font-mono text-slate-200">{{ num(totalKmOnRoad) }} km · {{ num(totalTonnageOnRoad, 1) }}t</span></div>
         <div class="row" v-if="topCommodity"><span class="text-slate-400">Top cargo</span><span class="font-mono text-slate-200">{{ topCommodity.name }} ×{{ topCommodity.count }}</span></div>

         <p class="stat-label !text-[9px] text-brand-soft sep">🚛 Fleet</p>
         <div class="row"><span class="text-slate-400">Drivers</span><span class="font-mono"><span class="text-gain">{{ driverSummary.free }} free</span> · <span class="text-brand-soft">{{ driverSummary.busy }} busy</span> · <span class="text-slate-400">{{ driverSummary.resting }} rest</span></span></div>
         <div class="row"><span class="text-slate-400">Trucks</span><span class="font-mono"><span class="text-gain">{{ fleetStatus.idle }} idle</span> · <span class="text-brand-soft">{{ fleetStatus.enRoute }} out</span> · <span class="text-loss">{{ fleetStatus.maint }} shop</span></span></div>
         <div class="row"><span class="text-slate-400">Trailers</span><span class="font-mono text-slate-200"><span class="text-gain">{{ trailerStatus.free }} free</span> · {{ trailerStatus.inUse }} in use</span></div>
         <div class="row"><span class="text-slate-400">Utilization</span><span class="font-mono font-semibold" :class="fleetStatus.util >= 80 ? 'text-gain' : 'text-slate-200'">{{ fleetStatus.util }}%</span></div>

         <p class="stat-label !text-[9px] text-brand-soft sep">📅 Today</p>
         <div class="row"><span class="text-slate-400">Revenue</span><span class="font-mono text-gold font-semibold">{{ credits(today.revenue) }}</span></div>
         <div class="row"><span class="text-slate-400">+ on-road value</span><span class="font-mono text-gain font-semibold">{{ credits(today.revenue + roadTotals.value) }}</span></div>
         <div class="row"><span class="text-slate-400">Delivered</span><span class="font-mono"><span class="text-gain">{{ today.on_time }} on-time</span> · <span class="text-gold">{{ today.late }} late</span> · <span class="text-loss">{{ today.failed }} failed</span></span></div>
         <div class="row"><span class="text-slate-400">On-time rate</span><span class="font-mono font-semibold" :class="onTimePct >= 90 ? 'text-gain' : onTimePct >= 70 ? 'text-gold' : 'text-loss'">{{ onTimePct }}%</span></div>
         <div class="row" v-if="today.best_route"><span class="text-slate-400">Best lane</span><span class="font-mono text-slate-200 truncate ml-2">{{ today.best_route.label }} · {{ credits(today.best_route.amount) }}</span></div>

         <template v-if="atRiskCount || lowFuelCount">
           <p class="stat-label !text-[9px] text-loss sep">🚨 Alerts</p>
           <div class="row" v-if="atRiskCount"><span class="text-slate-400">Will miss deadline</span><span class="font-mono text-loss font-semibold">⚠️ {{ atRiskCount }}</span></div>
           <div class="row" v-if="lowFuelCount"><span class="text-slate-400">Low fuel en route</span><span class="font-mono text-loss font-semibold">⛽ {{ lowFuelCount }}</span></div>
         </template>
       </div>

       <div v-if="onTheRoad.length" class="divide-y divide-white/5">
         <div v-for="s in onTheRoad" :key="s.id" class="py-2">
           <p class="text-xs font-medium">{{ s.contract?.origin?.name }} → {{ s.contract?.destination?.name }}</p>
           <p class="font-mono text-[11px] mt-0.5" :class="etaLeft(s) === 'arriving…' ? 'text-gain' : 'text-brand-soft'">
             ⏱ {{ etaLeft(s) }} left
           </p>
         </div>
       </div>
       <p v-else class="text-xs text-slate-500">Nothing en route right now.</p>
     </div>

     <!-- Free vehicles and where they're parked -->
     <div id="free-vehicles" class="glass p-4 scroll-mt-4">
       <h3 class="font-semibold text-sm mb-2">
         Free Vehicles <span class="chip bg-gain/15 text-gain ml-1">{{ freeVehicles.length }}</span>
       </h3>
       <div v-if="freeVehicles.length" class="divide-y divide-white/5">
         <div v-for="v in freeVehicles" :key="v.id" class="py-2">
           <p class="text-xs font-medium"><span class="font-mono text-brand-soft mr-1">{{ fleetTag(v.fleet_no) }}</span>{{ v.nickname || v.model?.name }}</p>
           <p class="text-[11px] text-slate-400 mt-0.5">📍 {{ v.city?.name || '—' }}</p>
           <div v-if="vehicleNeeds(v).length" class="flex flex-wrap items-center gap-1 mt-1">
             <span class="text-[10px] text-gold">🛠 fix:</span>
             <button v-for="need in vehicleNeeds(v)" :key="need"
               class="chip bg-gold/15 text-gold hover:bg-gold/25 text-[10px] capitalize disabled:opacity-40"
               :disabled="servingVeh === v.id" @click="fixNeed(v, need)">
               {{ NEED_ICON[need] }} {{ need }}<span v-if="needCost(v, need)" class="font-mono ml-1 normal-case">{{ credits(needCost(v, need)) }}</span>
             </button>
           </div>
         </div>
       </div>
       <p v-else class="text-xs text-slate-500">All trucks are out on the road.</p>
     </div>

     <!-- Free trailers and where they're parked, with one-click repair. -->
     <div class="glass p-4">
       <h3 class="font-semibold text-sm mb-2">
         Free Trailers <span class="chip bg-gain/15 text-gain ml-1">{{ freeTrailers.length }}</span>
       </h3>
       <div v-if="freeTrailers.length" class="divide-y divide-white/5">
         <div v-for="t in freeTrailers" :key="t.id" class="py-2">
           <div class="flex items-center justify-between gap-2">
             <p class="text-xs font-medium truncate">{{ t.nickname || t.model?.name }}</p>
             <span class="text-[10px] font-mono shrink-0" :class="trailerNeedsRepair(t) ? 'text-loss' : 'text-slate-400'">{{ Math.round(t.condition ?? 100) }}%</span>
           </div>
           <p class="text-[11px] text-slate-400 mt-0.5">📍 {{ t.city?.name || '—' }}</p>
           <div v-if="trailerNeedsRepair(t)" class="mt-1">
             <button
               class="chip bg-gold/15 text-gold hover:bg-gold/25 text-[10px] disabled:opacity-40"
               :disabled="servingTrailer === t.id" @click="fixTrailer(t)">
               🔧 {{ servingTrailer === t.id ? 'Repairing…' : 'Repair' }}<span v-if="t.repair_cost" class="font-mono ml-1">{{ credits(t.repair_cost) }}</span>
             </button>
           </div>
         </div>
       </div>
       <p v-else class="text-xs text-slate-500">No free trailers right now.</p>
     </div>
   </aside>
  </div>
</template>
