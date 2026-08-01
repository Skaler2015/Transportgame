<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed, watch } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { useClock } from '../composables/useClock'
import type { Contract, Vehicle, Trailer, Driver, Shipment } from '../types'
import { credits, num } from '../utils/format'
import CommodityBadge from '../components/CommodityBadge.vue'

const game = useGameStore()
const toast = useToastStore()
const now = useClock(1000)

const mine = ref<Contract[]>([])
const vehicles = ref<Vehicle[]>([])
const trailers = ref<Trailer[]>([])
const drivers = ref<Driver[]>([])
const shipments = ref<Shipment[]>([])
const selection = ref<Record<number, { vehicle_id: number | null; trailer_id: number | null; driver_id: number | null }>>({})
const dispatching = ref<number | null>(null)
const refuelling = ref<number | null>(null)
let poll: number | undefined

const MODE_ICON: Record<string, string> = { road: '🚚', rail: '🚆', sea: '🚢', air: '✈️' }

const KM_PER_MIN = 225
function etaText(km: number): string {
  const secs = Math.max(20, Math.round((km / KM_PER_MIN) * 60))
  if (secs < 60) return `~${secs}s`
  const m = Math.floor(secs / 60)
  const s = secs % 60
  return s ? `~${m}m ${s}s` : `~${m}m`
}

async function loadAll() {
  // Independent loads so a single failing endpoint doesn't blank the board.
  const [m, f, tr, d, s] = await Promise.allSettled([
    api.get('/contracts/mine'),
    api.get('/fleet'),
    api.get('/trailers'),
    api.get('/drivers'),
    api.get('/shipments'),
  ])
  if (m.status === 'fulfilled') mine.value = m.value.data.data.filter((c: Contract) => c.status === 'accepted')
  if (f.status === 'fulfilled') vehicles.value = f.value.data.data
  if (tr.status === 'fulfilled') trailers.value = tr.value.data.data
  if (d.status === 'fulfilled') drivers.value = d.value.data.data
  if (s.status === 'fulfilled') shipments.value = s.value.data.data
  for (const c of mine.value) {
    if (!selection.value[c.id]) selection.value[c.id] = { vehicle_id: null, trailer_id: null, driver_id: null }
  }
  void loadEstimate()
  if (m.status === 'rejected') throw m.reason
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

// ---- compatibility helpers ------------------------------------------------
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
  return vehicles.value.filter((v) => {
    if (!v.available || !v.model) return false
    // Only trucks parked at the origin city can start this job.
    if (v.city?.id !== c.origin?.id) return false
    if (!modeOk(v, c)) return false
    return v.model.needs_trailer ? true : vehicleSelfHauls(v, c)
  })
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
function fuelNeeded(c: Contract): number {
  const v = selectedVehicle(c)
  if (!v?.model) return 0
  return (c.distance_km || 0) * (v.model.fuel_economy || 0)
}
function fuelShort(c: Contract): boolean {
  const v = selectedVehicle(c)
  if (!v) return false
  const need = fuelNeeded(c)
  return need > 0 && v.fuel + 0.001 < need
}

const activeShipments = computed(() => shipments.value.filter((s) => s.status === 'en_route'))
// On the Road, soonest-to-arrive first (least time remaining at the top).
const activeSorted = computed(() =>
  [...activeShipments.value].sort(
    (a, b) => new Date(a.eta_at).getTime() - new Date(b.eta_at).getTime(),
  ),
)
// Time left to a shipment's ETA — recomputed every second via the `now` clock.
function etaLeft(s: Shipment): string {
  const ms = new Date(s.eta_at).getTime() - now.value
  if (ms <= 0) return 'arriving…'
  const secs = Math.round(ms / 1000)
  const m = Math.floor(secs / 60)
  const sec = secs % 60
  return m ? `${m}m ${sec}s` : `${sec}s`
}
const pastShipments = computed(() => shipments.value.filter((s) => s.status !== 'en_route').slice(0, 8))

const busyUpkeep = ref<'service' | 'fuel' | null>(null)
async function serviceAll() {
  busyUpkeep.value = 'service'
  try {
    const { data } = await api.post('/fleet/service-all')
    toast.success(data.message)
    await loadAll()
    game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { busyUpkeep.value = null }
}
async function fuelAll() {
  busyUpkeep.value = 'fuel'
  try {
    const { data } = await api.post('/fleet/refuel-all')
    toast.success(data.message)
    await loadAll()
    game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { busyUpkeep.value = null }
}

async function refuel(c: Contract) {
  const v = selectedVehicle(c)
  if (!v) return
  refuelling.value = c.id
  try {
    await api.post(`/vehicles/${v.id}/refuel`)
    toast.success('Tank filled.')
    await loadAll()
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    refuelling.value = null
  }
}

async function dispatch(c: Contract) {
  const sel = selection.value[c.id]
  if (!sel?.vehicle_id || !sel?.driver_id) {
    toast.error('Pick a vehicle and a driver first.')
    return
  }
  if (needsTrailer(c) && !sel.trailer_id) {
    toast.error('This tractor needs a trailer — attach one.')
    return
  }
  dispatching.value = c.id
  try {
    await api.post('/shipments/dispatch', {
      contract_id: c.id,
      vehicle_id: sel.vehicle_id,
      trailer_id: needsTrailer(c) ? sel.trailer_id : null,
      driver_id: sel.driver_id,
    })
    toast.success('Dispatched! Load is rolling.')
    await loadAll()
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    dispatching.value = null
  }
}

const statusStyle: Record<string, string> = {
  delivered: 'text-gain', late: 'text-gold', failed: 'text-loss',
}

// The instant a shipment's ETA passes, settle it and refresh — no wait for the
// poll: the delivered truck frees up and its next jobs appear automatically.
const arrivedHandled = new Set<number>()
watch(now, () => {
  const due = shipments.value.filter(
    (s) => s.status === 'en_route'
      && new Date(s.eta_at).getTime() <= now.value
      && !arrivedHandled.has(s.id),
  )
  if (!due.length) return
  due.forEach((s) => arrivedHandled.add(s.id))
  loadAll().catch(() => {})
  game.refreshDashboard().catch(() => {})
})

onMounted(async () => {
  await game.loadReference().catch(() => {})
  await loadAll().catch((e) => toast.error(apiError(e)))
  poll = window.setInterval(() => loadAll().catch(() => {}), 8000)
})
onUnmounted(() => clearInterval(poll))
</script>

<template>
  <div class="space-y-6">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Operations — Job Board</h1>
        <p class="text-slate-400 text-sm">Assign a vehicle, a matching trailer, a driver and fuel — then roll.</p>
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

    <!-- Ready to dispatch -->
    <section class="space-y-3">
      <h2 class="font-semibold">Ready to Dispatch
        <span class="chip bg-brand/15 text-brand-soft ml-1">{{ mine.length }}</span>
      </h2>

      <div v-if="mine.length" class="grid lg:grid-cols-2 gap-3">
        <div v-for="c in mine" :key="c.id" class="glass p-4">
          <div class="flex items-center justify-between">
            <CommodityBadge :commodity="c.commodity" size="md" />
            <p class="font-mono text-gold text-sm font-semibold">{{ credits(c.payout) }}</p>
          </div>
          <p class="text-sm font-semibold mt-2">{{ c.origin?.name }} <span class="text-brand">→</span> {{ c.destination?.name }}</p>
          <p class="text-[11px] text-slate-400">
            {{ num(c.distance_km) }} km · {{ num(c.total_weight ?? 0, 1) }} t · ⏱ {{ etaText(c.distance_km) }}
            <span v-if="c.commodity?.requires_reefer" class="chip bg-cyan-500/15 text-cyan-300 ml-1">reefer</span>
            <span v-if="c.commodity?.requires_tanker" class="chip bg-amber-500/15 text-amber-300 ml-1">tanker</span>
            <span v-if="c.commodity?.is_hazardous" class="chip bg-loss/15 text-loss ml-1">hazmat</span>
          </p>

          <!-- Slots -->
          <div class="mt-3 space-y-2">
            <div>
              <label class="stat-label">① Vehicle</label>
              <select v-model="selection[c.id].vehicle_id" class="input !py-1.5 text-xs mt-0.5">
                <option :value="null" disabled>Choose vehicle…</option>
                <option v-for="v in compatibleVehicles(c)" :key="v.id" :value="v.id">
                  {{ MODE_ICON[v.model?.mode ?? 'road'] }} {{ v.nickname || v.model?.name }} · {{ num(v.condition) }}% · fuel {{ Math.round(v.fuel_pct ?? 100) }}%
                </option>
              </select>
            </div>

            <div v-if="needsTrailer(c)">
              <label class="stat-label">② Trailer <span class="text-slate-500">(required)</span></label>
              <select v-model="selection[c.id].trailer_id" class="input !py-1.5 text-xs mt-0.5">
                <option :value="null" disabled>Attach trailer…</option>
                <option v-for="t in compatibleTrailers(c)" :key="t.id" :value="t.id">
                  {{ t.model?.name }} · {{ num(t.model?.capacity_weight ?? 0, 0) }}t · {{ num(t.condition) }}%
                </option>
              </select>
              <p v-if="!compatibleTrailers(c).length" class="text-[11px] text-loss mt-1">
                No suitable trailer free. Buy a <RouterLink to="/fleet" class="underline">{{ c.commodity?.requires_reefer ? 'reefer' : c.commodity?.requires_tanker ? 'tanker' : 'matching' }} trailer</RouterLink>.
              </p>
            </div>

            <div>
              <label class="stat-label">{{ needsTrailer(c) ? '③' : '②' }} Driver</label>
              <select v-model="selection[c.id].driver_id" class="input !py-1.5 text-xs mt-0.5">
                <option :value="null" disabled>Choose driver…</option>
                <option v-for="d in compatibleDrivers(c)" :key="d.id" :value="d.id">
                  {{ d.name }} · skill {{ d.skill }} · fatigue {{ d.fatigue }}
                </option>
              </select>
            </div>
          </div>

          <!-- Fuel gauge for the chosen vehicle -->
          <div v-if="selectedVehicle(c)" class="mt-3">
            <div class="flex items-center justify-between text-[11px]">
              <span class="stat-label">Fuel</span>
              <span :class="fuelShort(c) ? 'text-loss' : 'text-slate-300'">
                {{ Math.round(selectedVehicle(c)!.fuel) }} / {{ Math.round(selectedVehicle(c)!.fuel_capacity ?? 0) }} L
                <span v-if="fuelNeeded(c) > 0">· need ~{{ Math.round(fuelNeeded(c)) }} L</span>
              </span>
            </div>
            <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden mt-1">
              <div class="h-full" :class="fuelShort(c) ? 'bg-loss' : 'bg-brand'" :style="{ width: Math.min(100, selectedVehicle(c)!.fuel_pct ?? 100) + '%' }" />
            </div>
            <button
              v-if="fuelShort(c) && (selectedVehicle(c)!.fuel_capacity ?? 0) > 0"
              class="btn-ghost !py-1 !px-3 text-[11px] mt-2"
              :disabled="refuelling === c.id"
              @click="refuel(c)"
            >
              ⛽ {{ refuelling === c.id ? 'Refuelling…' : 'Refuel now' }}
            </button>
          </div>

          <div class="flex items-center justify-between mt-3">
            <p class="text-[11px] text-slate-500">
              <span v-if="!compatibleVehicles(c).length" class="text-loss">No compatible idle vehicle.</span>
              <span v-else-if="needsTrailer(c) && !compatibleTrailers(c).length" class="text-loss">Needs a matching trailer.</span>
              <span v-else-if="!compatibleDrivers(c).length" class="text-loss">No available driver.</span>
              <span v-else-if="fuelShort(c)" class="text-gold">Low on fuel — refuel before rolling.</span>
              <span v-else class="text-gain">Ready to roll.</span>
            </p>
            <button class="btn-primary !py-1.5" :disabled="dispatching === c.id" @click="dispatch(c)">
              {{ dispatching === c.id ? '…' : 'GO →' }}
            </button>
          </div>
        </div>
      </div>
      <div v-else class="glass p-8 text-center text-slate-400 text-sm">
        No claimed contracts. Head to the <RouterLink to="/contracts" class="text-brand">Contract Market</RouterLink> to claim one.
      </div>
    </section>

    <!-- Active -->
    <section class="space-y-3">
      <h2 class="font-semibold">On the Road
        <span class="chip bg-brand/15 text-brand-soft ml-1">{{ activeShipments.length }}</span>
      </h2>
      <!-- Compact list: only route + time left, soonest arrival first. -->
      <div v-if="activeSorted.length" class="glass divide-y divide-white/5">
        <div v-for="s in activeSorted" :key="s.id" class="flex items-center justify-between px-4 py-3">
          <p class="font-semibold text-sm truncate">
            {{ s.contract?.origin?.name }}
            <span class="text-slate-500 mx-1">→</span>
            {{ s.contract?.destination?.name }}
          </p>
          <p class="font-mono text-sm shrink-0" :class="etaLeft(s) === 'arriving…' ? 'text-gain' : 'text-brand-soft'">
            ⏱ {{ etaLeft(s) }}
          </p>
        </div>
      </div>
      <div v-else class="glass p-6 text-center text-slate-500 text-sm">Nothing en route right now.</div>
    </section>

    <!-- History -->
    <section v-if="pastShipments.length" class="space-y-3">
      <h2 class="font-semibold">Recent Deliveries</h2>
      <div class="glass divide-y divide-white/5">
        <div v-for="s in pastShipments" :key="s.id" class="flex items-center justify-between px-4 py-3 text-sm">
          <div class="min-w-0">
            <p class="truncate">{{ s.contract?.origin?.name }} → {{ s.contract?.destination?.name }}</p>
            <p class="text-[11px] text-slate-400 truncate">{{ s.contract?.commodity?.name }} · {{ s.driver?.name }}</p>
            <p v-if="s.outcome_note && s.status !== 'delivered'" class="text-[11px] mt-0.5 truncate"
              :class="s.status === 'failed' ? 'text-loss' : 'text-gold'">
              {{ s.outcome_note }}
            </p>
          </div>
          <div class="text-right shrink-0">
            <p class="font-semibold uppercase text-xs" :class="statusStyle[s.status]">{{ s.status }}</p>
            <p class="font-mono text-[11px] text-gold">{{ credits(s.projected_payout) }}</p>
            <p v-if="(s.service_cost ?? 0) > 0" class="font-mono text-[10px] text-loss" title="Auto-refuel charged on arrival">
              ⛽ −{{ credits(s.service_cost ?? 0) }}
            </p>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
