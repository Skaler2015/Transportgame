<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { Vehicle, VehicleModel, Trailer, TrailerModel } from '../types'
import { credits, num } from '../utils/format'

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

function barColor(v: number) {
  return v > 60 ? 'bg-gain' : v > 30 ? 'bg-gold' : 'bg-loss'
}
function trailerTags(m?: TrailerModel): string {
  if (!m) return ''
  return [m.can_reefer && '❄ reefer', m.can_tanker && '⬢ tanker', m.can_hazmat && '☣ hazmat'].filter(Boolean).join(' · ') || 'general'
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

    <!-- Fleet -->
    <div v-if="tab === 'fleet'" class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="v in vehicles" :key="v.id" class="glass p-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span>{{ MODE_ICON[v.model?.mode ?? 'road'] }}</span>
            <p class="font-semibold text-sm">{{ v.model?.name }}</p>
          </div>
          <span class="chip capitalize" :class="statusChip[v.status]">{{ v.status.replace('_', ' ') }}</span>
        </div>
        <p class="text-[11px] text-slate-400 mt-0.5">{{ v.nickname || v.model?.brand }} · {{ v.city?.name }} · {{ num(v.odometer) }} km</p>

        <div class="mt-3 space-y-2">
          <div>
            <div class="flex justify-between text-[11px] mb-1"><span class="text-slate-400">Condition</span><span class="font-mono">{{ num(v.condition) }}%</span></div>
            <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden"><div class="h-full" :class="barColor(v.condition)" :style="{ width: v.condition + '%' }" /></div>
          </div>
          <div>
            <div class="flex justify-between text-[11px] mb-1"><span class="text-slate-400">Tire wear</span><span class="font-mono">{{ num(v.tire_wear) }}%</span></div>
            <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden"><div class="h-full" :class="barColor(100 - v.tire_wear)" :style="{ width: v.tire_wear + '%' }" /></div>
          </div>
          <div v-if="(v.fuel_capacity ?? 0) > 0">
            <div class="flex justify-between text-[11px] mb-1">
              <span class="text-slate-400">Fuel</span>
              <span class="font-mono">{{ Math.round(v.fuel) }} / {{ Math.round(v.fuel_capacity ?? 0) }} L</span>
            </div>
            <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden"><div class="h-full" :class="barColor(v.fuel_pct ?? 100)" :style="{ width: Math.min(100, v.fuel_pct ?? 100) + '%' }" /></div>
          </div>
        </div>

        <div class="grid grid-cols-3 gap-2 mt-3 text-center text-[11px]">
          <div><p class="stat-label">Cap</p><p class="font-mono">{{ num((v as any).effective_capacity_weight ?? v.model?.capacity_weight ?? 0, 1) }}t</p></div>
          <div><p class="stat-label">Speed</p><p class="font-mono">{{ v.model?.top_speed }}</p></div>
          <div><p class="stat-label">Rig</p><p class="font-mono">{{ v.model?.needs_trailer ? 'tractor' : 'rigid' }}</p></div>
        </div>

        <!-- Service, fuel & upgrades -->
        <div class="mt-3 pt-3 border-t border-white/5">
          <div class="flex items-center justify-between mb-2 gap-2">
            <span class="stat-label">Upgrades ({{ (v as any).upgrade_slots_used ?? 0 }}/{{ v.model?.upgrade_slots ?? 0 }})</span>
            <div class="flex gap-1.5">
              <button v-if="(v.fuel_capacity ?? 0) > 0" class="btn-ghost !py-1 !px-2 text-[11px]" :disabled="working === v.id || v.status === 'en_route'" @click="refuel(v)">⛽ Refuel</button>
              <button class="btn-ghost !py-1 !px-2 text-[11px]" :disabled="working === v.id || v.status === 'en_route'" @click="repair(v)">🔧 Service</button>
            </div>
          </div>
          <div class="grid grid-cols-3 gap-1.5">
            <button class="btn-ghost !py-1 text-[11px] flex-col" :disabled="working === v.id" @click="upgrade(v, 'engine')">
              ⚙ Engine <span class="text-brand-soft">L{{ (v as any).engine_level ?? 0 }}</span>
            </button>
            <button class="btn-ghost !py-1 text-[11px]" :disabled="working === v.id" @click="upgrade(v, 'tires')">
              ◍ Tyres <span class="text-brand-soft">L{{ (v as any).tires_level ?? 0 }}</span>
            </button>
            <button class="btn-ghost !py-1 text-[11px]" :disabled="working === v.id" @click="upgrade(v, 'trailer')">
              ▤ Rig <span class="text-brand-soft">L{{ (v as any).trailer_level ?? 0 }}</span>
            </button>
          </div>
        </div>
      </div>
      <div v-if="!vehicles.length" class="glass p-8 text-center text-slate-400 col-span-full">No vehicles yet.</div>
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
          <span class="chip bg-white/5 text-slate-300 capitalize">{{ m.mode }}</span>
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
