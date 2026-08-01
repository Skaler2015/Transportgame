<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { Vehicle, VehicleModel, Trailer, TrailerModel } from '../types'
import { credits, num, fleetTag } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

const vehicles = ref<Vehicle[]>([])
const catalog = ref<VehicleModel[]>([])
const trailers = ref<Trailer[]>([])
const trailerCatalog = ref<TrailerModel[]>([])
const tab = ref<'fleet' | 'trailers' | 'shopVehicles' | 'shopTrailers'>('fleet')
const buying = ref<number | null>(null)
const working = ref<number | null>(null)

async function load() {
  // Load each section independently so one failing endpoint (e.g. trailers,
  // before a deploy has migrated) never blanks the whole page.
  const [f, d, t, td] = await Promise.allSettled([
    api.get('/fleet'),
    api.get('/dealership'),
    api.get('/trailers'),
    api.get('/trailers/dealership'),
  ])
  if (f.status === 'fulfilled') vehicles.value = f.value.data.data
  if (d.status === 'fulfilled') catalog.value = d.value.data.data
  if (t.status === 'fulfilled') trailers.value = t.value.data.data
  if (td.status === 'fulfilled') trailerCatalog.value = td.value.data.data

  // Surface the core failures (fleet / vehicle dealership) but stay quiet if
  // only the newer trailer endpoints are unavailable.
  void loadEstimate()

  if (f.status === 'rejected') throw f.reason
  if (d.status === 'rejected') throw d.reason
}

async function buy(m: VehicleModel) {
  buying.value = m.id
  try {
    await api.post(`/dealership/${m.id}/buy`)
    toast.success(`Purchased ${m.name}.`)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { buying.value = null }
}

async function buyTrailer(m: TrailerModel) {
  buying.value = m.id + 100000 // keep key space distinct from vehicles
  try {
    await api.post(`/trailers/dealership/${m.id}/buy`)
    toast.success(`Purchased ${m.name}.`)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { buying.value = null }
}

async function repair(v: Vehicle) {
  working.value = v.id
  try {
    const { data } = await api.post(`/vehicles/${v.id}/repair`)
    toast.success(data.message)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { working.value = null }
}

async function refuel(v: Vehicle) {
  working.value = v.id
  try {
    await api.post(`/vehicles/${v.id}/refuel`)
    toast.success(`Refuelled ${v.nickname || v.model?.name}.`)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { working.value = null }
}

const busyUpkeep = ref<'service' | 'fuel' | null>(null)
const upkeepEst = ref<{ service: { count: number; total: number }; fuel: { count: number; total: number } }>(
  { service: { count: 0, total: 0 }, fuel: { count: 0, total: 0 } },
)
async function loadEstimate() {
  try {
    const { data } = await api.get('/fleet/service-estimate')
    upkeepEst.value = data
  } catch { /* estimate is best-effort */ }
}
async function serviceAll() {
  busyUpkeep.value = 'service'
  try {
    const { data } = await api.post('/fleet/service-all')
    toast.success(data.message)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { busyUpkeep.value = null }
}
async function fuelAll() {
  busyUpkeep.value = 'fuel'
  try {
    const { data } = await api.post('/fleet/refuel-all')
    toast.success(data.message)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { busyUpkeep.value = null }
}

async function fullService(v: Vehicle) {
  working.value = v.id
  try {
    const { data } = await api.post(`/vehicles/${v.id}/full-service`)
    toast.success(data.message)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { working.value = null }
}

async function service(v: Vehicle, type: 'oil' | 'battery' | 'insurance' | 'registration') {
  working.value = v.id
  try {
    const { data } = await api.post(`/vehicles/${v.id}/service`, { type })
    toast.success(data.message)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { working.value = null }
}

async function upgrade(v: Vehicle, kind: 'engine' | 'tires' | 'trailer') {
  working.value = v.id
  try {
    const { data } = await api.post(`/vehicles/${v.id}/upgrade`, { kind })
    toast.success(data.message)
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { working.value = null }
}

const statusChip: Record<string, string> = {
  idle: 'bg-gain/20 text-gain', en_route: 'bg-brand/20 text-brand-soft',
  maintenance: 'bg-loss/20 text-loss', assigned: 'bg-gold/20 text-gold',
}
const powertrainIcon: Record<string, string> = { diesel: '⛽', electric: '⚡', hydrogen: '💧', jet: '🛫' }
const MODE_ICON: Record<string, string> = { road: '🚚', rail: '🚆', sea: '🚢', air: '✈️' }
const TRAILER_ICON: Record<string, string> = {
  box: '📦', reefer: '❄️', tanker: '🛢️', flatbed: '🏗️', container: '🚛', car_carrier: '🚗',
}

// How many of each model the company already owns (keyed by model id).
const ownedVehicles = computed(() => {
  const m: Record<number, number> = {}
  for (const v of vehicles.value) {
    const id = v.model?.id
    if (id) m[id] = (m[id] || 0) + 1
  }
  return m
})
const ownedTrailers = computed(() => {
  const m: Record<number, number> = {}
  for (const t of trailers.value) {
    const id = t.model?.id
    if (id) m[id] = (m[id] || 0) + 1
  }
  return m
})

function barColor(v: number) {
  return v > 60 ? 'bg-gain' : v > 30 ? 'bg-gold' : 'bg-loss'
}
function trailerTags(m?: TrailerModel): string {
  if (!m) return ''
  return [m.can_reefer && '❄ reefer', m.can_tanker && '⬢ tanker', m.can_hazmat && '☣ hazmat'].filter(Boolean).join(' · ') || 'general'
}

// What each vehicle needs, so the UI can flag it and highlight the right button.
function needs(v: Vehicle) {
  return {
    repair: (v.condition ?? 100) < 70 || (v.tire_wear ?? 0) > 40,
    oil: (v.oil_level ?? 100) < 40,
    battery: (v.battery ?? 100) < 40,
    insurance: !v.is_insured,
    registration: !v.is_registered,
  }
}
// A single "how badly does this need attention" score for sorting.
function needScore(v: Vehicle): number {
  return (100 - (v.condition ?? 100))
    + (v.tire_wear ?? 0)
    + (100 - (v.oil_level ?? 100)) * 0.8
    + (100 - (v.battery ?? 100)) * 0.8
    + (v.is_insured ? 0 : 60)
    + (v.is_registered ? 0 : 60)
}
// Short human summary of what a vehicle needs, e.g. "repair · oil · insurance".
function needSummary(v: Vehicle): string {
  const n = needs(v)
  return [n.repair && 'repair', n.oil && 'oil', n.battery && 'battery', n.insurance && 'insurance', n.registration && 'registration']
    .filter(Boolean).join(' · ')
}
// ---- Sortable fleet table --------------------------------------------------
type SortKey = 'name' | 'status' | 'location' | 'condition' | 'tire' | 'fuel' | 'oil' | 'battery' | 'need'
const sortField = ref<SortKey>('need')
const sortDir = ref<'asc' | 'desc'>('desc')
function setSort(k: SortKey) {
  if (sortField.value === k) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortField.value = k
    sortDir.value = ['name', 'status', 'location'].includes(k) ? 'asc' : 'desc'
  }
}
function arrow(k: SortKey): string {
  return sortField.value !== k ? '' : (sortDir.value === 'asc' ? ' ▲' : ' ▼')
}
function sortVal(v: Vehicle, k: SortKey): number | string {
  switch (k) {
    case 'name': return (v.nickname || v.model?.name || '').toLowerCase()
    case 'status': return v.status
    case 'location': return (v.city?.name || '').toLowerCase()
    case 'condition': return v.condition ?? 0
    case 'tire': return v.tire_wear ?? 0
    case 'fuel': return v.fuel_pct ?? 100
    case 'oil': return v.oil_level ?? 100
    case 'battery': return v.battery ?? 100
    default: return needScore(v)
  }
}
const tableVehicles = computed(() => {
  const arr = [...vehicles.value]
  arr.sort((a, b) => {
    const av = sortVal(a, sortField.value)
    const bv = sortVal(b, sortField.value)
    const cmp = typeof av === 'string' ? av.localeCompare(bv as string) : (av as number) - (bv as number)
    return sortDir.value === 'asc' ? cmp : -cmp
  })
  return arr
})
// Colour a 0–100 metric (green healthy → red critical).
function metricText(v: number): string {
  return v > 60 ? 'text-gain' : v > 30 ? 'text-gold' : 'text-loss'
}
// Row expand-to-manage (all the per-vehicle actions live in the drawer).
const expandedId = ref<number | null>(null)
function toggleExpand(id: number) {
  expandedId.value = expandedId.value === id ? null : id
}

onMounted(() => load().catch((e) => toast.error(apiError(e))))
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Fleet &amp; Dealership</h1>
        <p class="text-slate-400 text-sm">Trucks, trailers, fuel and multi-modal craft — everything that hauls.</p>
      </div>
      <div class="flex gap-2">
        <button class="btn-ghost !py-1.5" :disabled="busyUpkeep !== null || upkeepEst.service.count === 0" @click="serviceAll">
          <template v-if="busyUpkeep === 'service'">Servicing…</template>
          <template v-else-if="upkeepEst.service.count > 0">🔧 Service All · {{ credits(upkeepEst.service.total) }}</template>
          <template v-else>🔧 Service All</template>
        </button>
        <button class="btn-primary !py-1.5" :disabled="busyUpkeep !== null || upkeepEst.fuel.count === 0" @click="fuelAll">
          <template v-if="busyUpkeep === 'fuel'">Fuelling…</template>
          <template v-else-if="upkeepEst.fuel.count > 0">⛽ Fuel All · {{ credits(upkeepEst.fuel.total) }}</template>
          <template v-else>⛽ Fuel All</template>
        </button>
      </div>
      <div class="flex flex-wrap gap-2 p-1 rounded-xl bg-ink-900/70">
        <button class="px-3 py-1.5 rounded-lg text-xs sm:text-sm font-semibold transition"
          :class="tab === 'fleet' ? 'bg-brand text-ink-950' : 'text-slate-400'" @click="tab = 'fleet'">
          Fleet ({{ vehicles.length }})
        </button>
        <button class="px-3 py-1.5 rounded-lg text-xs sm:text-sm font-semibold transition"
          :class="tab === 'trailers' ? 'bg-brand text-ink-950' : 'text-slate-400'" @click="tab = 'trailers'">
          Trailers ({{ trailers.length }})
        </button>
        <button class="px-3 py-1.5 rounded-lg text-xs sm:text-sm font-semibold transition"
          :class="tab === 'shopVehicles' ? 'bg-brand text-ink-950' : 'text-slate-400'" @click="tab = 'shopVehicles'">
          Buy Vehicle
        </button>
        <button class="px-3 py-1.5 rounded-lg text-xs sm:text-sm font-semibold transition"
          :class="tab === 'shopTrailers' ? 'bg-brand text-ink-950' : 'text-slate-400'" @click="tab = 'shopTrailers'">
          Buy Trailer
        </button>
      </div>
    </div>

    <!-- Fleet — compact, sortable table (click any header to sort) -->
    <div v-if="tab === 'fleet'" class="glass overflow-x-auto">
      <table class="w-full text-sm min-w-[860px]">
        <thead class="text-[11px] uppercase tracking-wide text-slate-400 border-b border-white/10 select-none">
          <tr>
            <th class="text-left px-3 py-2.5 cursor-pointer hover:text-slate-200" @click="setSort('name')">Vehicle{{ arrow('name') }}</th>
            <th class="text-left px-2 cursor-pointer hover:text-slate-200" @click="setSort('location')">Location{{ arrow('location') }}</th>
            <th class="text-left px-2 cursor-pointer hover:text-slate-200" @click="setSort('status')">Status{{ arrow('status') }}</th>
            <th class="text-right px-2 cursor-pointer hover:text-slate-200" @click="setSort('condition')">Cond{{ arrow('condition') }}</th>
            <th class="text-right px-2 cursor-pointer hover:text-slate-200" @click="setSort('tire')">Tyre{{ arrow('tire') }}</th>
            <th class="text-right px-2 cursor-pointer hover:text-slate-200" @click="setSort('fuel')">Fuel{{ arrow('fuel') }}</th>
            <th class="text-right px-2 cursor-pointer hover:text-slate-200" @click="setSort('oil')">Oil{{ arrow('oil') }}</th>
            <th class="text-right px-2 cursor-pointer hover:text-slate-200" @click="setSort('battery')">Batt{{ arrow('battery') }}</th>
            <th class="text-center px-2">Papers</th>
            <th class="text-left px-2 cursor-pointer hover:text-slate-200" @click="setSort('need')">Needs{{ arrow('need') }}</th>
            <th class="text-right px-3">Actions</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="v in tableVehicles" :key="v.id">
            <tr class="border-b border-white/5 hover:bg-white/5 transition"
              :class="needScore(v) > 40 ? 'bg-loss/5' : ''">
              <td class="px-3 py-2">
                <div class="flex items-center gap-2 min-w-0">
                  <span>{{ MODE_ICON[v.model?.mode ?? 'road'] }}</span>
                  <div class="min-w-0">
                    <p class="font-medium truncate">
                      <span class="font-mono text-brand-soft text-[11px] mr-1">{{ fleetTag(v.fleet_no) }}</span>{{ v.nickname || v.model?.name }}
                    </p>
                    <p class="text-[10px] text-slate-500 truncate">{{ v.model?.name }} · {{ num((v as any).effective_capacity_weight ?? v.model?.capacity_weight ?? 0, 1) }}t · {{ v.model?.needs_trailer ? 'tractor' : 'rigid' }}</p>
                  </div>
                </div>
              </td>
              <td class="px-2 text-slate-300 truncate max-w-[110px]">{{ v.city?.name || '—' }}</td>
              <td class="px-2"><span class="chip capitalize text-[10px]" :class="statusChip[v.status]">{{ v.status.replace('_', ' ') }}</span></td>
              <td class="px-2 text-right font-mono" :class="metricText(v.condition ?? 100)">{{ num(v.condition) }}%</td>
              <td class="px-2 text-right font-mono" :class="metricText(100 - (v.tire_wear ?? 0))">{{ num(v.tire_wear) }}%</td>
              <td class="px-2 text-right font-mono" :class="metricText(v.fuel_pct ?? 100)">{{ (v.fuel_capacity ?? 0) > 0 ? Math.round(v.fuel_pct ?? 100) + '%' : '—' }}</td>
              <td class="px-2 text-right font-mono" :class="metricText(v.oil_level ?? 100)">{{ num(v.oil_level ?? 100) }}%</td>
              <td class="px-2 text-right font-mono" :class="metricText(v.battery ?? 100)">{{ num(v.battery ?? 100) }}%</td>
              <td class="px-2 text-center whitespace-nowrap">
                <span :title="v.is_insured ? 'Insured' : 'Uninsured'" :class="v.is_insured ? '' : 'opacity-30'">🛡</span>
                <span :title="v.is_registered ? 'Registered' : 'Unregistered'" :class="v.is_registered ? '' : 'opacity-30'">📋</span>
              </td>
              <td class="px-2 text-[11px]" :class="needScore(v) > 40 ? 'text-loss' : 'text-gold'">{{ needSummary(v) || '—' }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button class="btn-primary !py-1 !px-2 text-[11px]" title="Full service & refuel" :disabled="working === v.id || v.status === 'en_route'" @click="fullService(v)">⚡</button>
                <button class="btn-ghost !py-1 !px-2 text-[11px] ml-1" title="Manage" @click="toggleExpand(v.id)">{{ expandedId === v.id ? '×' : '⋯' }}</button>
              </td>
            </tr>
            <!-- Expandable manage drawer with every action for this vehicle -->
            <tr v-if="expandedId === v.id" class="bg-ink-900/50 border-b border-white/5">
              <td colspan="11" class="px-3 py-3">
                <div class="flex flex-wrap items-center gap-1.5">
                  <span class="stat-label mr-1">Upgrades {{ (v as any).upgrade_slots_used ?? 0 }}/{{ v.model?.upgrade_slots ?? 0 }}</span>
                  <button v-if="(v.fuel_capacity ?? 0) > 0" class="btn-ghost !py-1 !px-2 text-[11px]" :disabled="working === v.id || v.status === 'en_route'" @click="refuel(v)">⛽ Refuel</button>
                  <button class="btn-ghost !py-1 !px-2 text-[11px]" :class="needs(v).repair && 'ring-1 ring-gold/60'" :disabled="working === v.id || v.status === 'en_route'" @click="repair(v)">🔧 Repair</button>
                  <button class="btn-ghost !py-1 !px-2 text-[11px]" :class="needs(v).oil && 'ring-1 ring-gold/60'" :disabled="working === v.id || v.status === 'en_route'" @click="service(v, 'oil')">🛢 Oil</button>
                  <button class="btn-ghost !py-1 !px-2 text-[11px]" :class="needs(v).battery && 'ring-1 ring-gold/60'" :disabled="working === v.id || v.status === 'en_route'" @click="service(v, 'battery')">🔋 Batt</button>
                  <button class="btn-ghost !py-1 !px-2 text-[11px]" :class="!v.is_insured && 'ring-1 ring-loss/50'" :disabled="working === v.id" @click="service(v, 'insurance')">🛡 Insure</button>
                  <button class="btn-ghost !py-1 !px-2 text-[11px]" :class="!v.is_registered && 'ring-1 ring-loss/50'" :disabled="working === v.id" @click="service(v, 'registration')">📋 Reg</button>
                  <span class="w-px h-5 bg-white/10 mx-1" />
                  <button class="btn-ghost !py-1 !px-2 text-[11px]" :disabled="working === v.id" @click="upgrade(v, 'engine')">⚙ Engine L{{ (v as any).engine_level ?? 0 }}</button>
                  <button class="btn-ghost !py-1 !px-2 text-[11px]" :disabled="working === v.id" @click="upgrade(v, 'tires')">◍ Tyres L{{ (v as any).tires_level ?? 0 }}</button>
                  <button class="btn-ghost !py-1 !px-2 text-[11px]" :disabled="working === v.id" @click="upgrade(v, 'trailer')">▤ Rig L{{ (v as any).trailer_level ?? 0 }}</button>
                  <span v-if="v.status === 'en_route'" class="text-[11px] text-slate-500 ml-1">On the road — service when it arrives.</span>
                </div>
              </td>
            </tr>
          </template>
          <tr v-if="!vehicles.length"><td colspan="11" class="p-8 text-center text-slate-400">No vehicles yet.</td></tr>
        </tbody>
      </table>
    </div>

    <!-- Owned trailers -->
    <div v-else-if="tab === 'trailers'" class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="t in trailers" :key="t.id" class="glass p-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span>{{ TRAILER_ICON[t.model?.type ?? 'box'] }}</span>
            <p class="font-semibold text-sm">{{ t.model?.name }}</p>
          </div>
          <span class="chip capitalize" :class="statusChip[t.status]">{{ t.status.replace('_', ' ') }}</span>
        </div>
        <p class="text-[11px] text-slate-400 mt-0.5">{{ t.nickname || (t.model?.type ?? '').replace('_', ' ') }} · {{ t.city?.name }}</p>
        <div class="grid grid-cols-2 gap-2 mt-3 text-[11px]">
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Capacity</p><p class="font-mono">{{ num(t.model?.capacity_weight ?? 0, 0) }}t / {{ num(t.model?.capacity_volume ?? 0, 0) }}m³</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Carries</p><p class="font-mono text-[10px]">{{ trailerTags(t.model) }}</p></div>
        </div>
        <div class="mt-2">
          <div class="flex justify-between text-[11px] mb-1"><span class="text-slate-400">Condition</span><span class="font-mono">{{ num(t.condition) }}%</span></div>
          <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden"><div class="h-full" :class="barColor(t.condition)" :style="{ width: t.condition + '%' }" /></div>
        </div>
      </div>
      <div v-if="!trailers.length" class="glass p-8 text-center text-slate-400 col-span-full">
        No trailers yet. Buy one to haul with your tractors.
      </div>
    </div>

    <!-- Vehicle dealership -->
    <div v-else-if="tab === 'shopVehicles'" class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="m in catalog" :key="m.id" class="glass p-4 flex flex-col" :class="m.locked ? 'opacity-60' : 'glass-hover'">
        <div class="flex items-start justify-between">
          <div>
            <p class="font-semibold text-sm">{{ MODE_ICON[m.mode] }} {{ m.name }}</p>
            <p class="text-[11px] text-slate-400">{{ m.brand }} · {{ powertrainIcon[m.powertrain] }} {{ m.powertrain }} · {{ m.needs_trailer ? 'tractor' : 'rigid' }}</p>
          </div>
          <div class="flex flex-col items-end gap-1">
            <span class="chip bg-white/5 text-slate-300 capitalize">{{ m.mode }}</span>
            <span class="chip" :class="ownedVehicles[m.id] ? 'bg-gain/20 text-gain' : 'bg-white/5 text-slate-500'">
              You own {{ ownedVehicles[m.id] || 0 }}
            </span>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 mt-3 text-[11px]">
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Capacity</p><p class="font-mono">{{ num(m.capacity_weight, 1) }}t / {{ num(m.capacity_volume, 0) }}m³</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Top speed</p><p class="font-mono">{{ m.top_speed }} km/h</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Economy</p><p class="font-mono">{{ m.fuel_economy > 0 ? m.fuel_economy + ' L/km' : 'electric' }}</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Handling</p><p class="font-mono">{{ [m.can_reefer && '❄', m.can_tanker && '⬢', m.can_hazmat && '☣'].filter(Boolean).join(' ') || (m.needs_trailer ? 'via trailer' : '—') }}</p></div>
        </div>

        <div class="mt-auto pt-3 flex items-center justify-between">
          <p class="font-mono font-semibold text-gold">{{ credits(m.price) }}</p>
          <button v-if="m.locked" class="btn-ghost !py-1.5 text-xs" disabled>🔒 Lv {{ m.unlock_level }}</button>
          <button v-else class="btn-primary !py-1.5" :disabled="!m.affordable || buying === m.id" @click="buy(m)">
            {{ buying === m.id ? '…' : m.affordable ? 'Buy' : 'Too pricey' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Trailer dealership -->
    <div v-else class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="m in trailerCatalog" :key="m.id" class="glass p-4 flex flex-col" :class="m.locked ? 'opacity-60' : 'glass-hover'">
        <div class="flex items-start justify-between">
          <div>
            <p class="font-semibold text-sm">{{ TRAILER_ICON[m.type] }} {{ m.name }}</p>
            <p class="text-[11px] text-slate-400 capitalize">{{ m.type.replace('_', ' ') }} trailer</p>
          </div>
          <span class="chip" :class="ownedTrailers[m.id] ? 'bg-gain/20 text-gain' : 'bg-white/5 text-slate-500'">
            You own {{ ownedTrailers[m.id] || 0 }}
          </span>
        </div>

        <div class="grid grid-cols-2 gap-2 mt-3 text-[11px]">
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Capacity</p><p class="font-mono">{{ num(m.capacity_weight, 0) }}t / {{ num(m.capacity_volume, 0) }}m³</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Carries</p><p class="font-mono text-[10px]">{{ trailerTags(m) }}</p></div>
        </div>

        <div class="mt-auto pt-3 flex items-center justify-between">
          <p class="font-mono font-semibold text-gold">{{ credits(m.price) }}</p>
          <button v-if="m.locked" class="btn-ghost !py-1.5 text-xs" disabled>🔒 Lv {{ m.unlock_level }}</button>
          <button v-else class="btn-primary !py-1.5" :disabled="!m.affordable || buying === m.id + 100000" @click="buyTrailer(m)">
            {{ buying === m.id + 100000 ? '…' : m.affordable ? 'Buy' : 'Too pricey' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
