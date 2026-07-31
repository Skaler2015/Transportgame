<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { Vehicle, VehicleModel } from '../types'
import { credits, num } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

const vehicles = ref<Vehicle[]>([])
const catalog = ref<VehicleModel[]>([])
const tab = ref<'fleet' | 'dealership'>('fleet')
const buying = ref<number | null>(null)

async function load() {
  const [f, d] = await Promise.all([api.get('/fleet'), api.get('/dealership')])
  vehicles.value = f.data.data
  catalog.value = d.data.data
}

async function buy(m: VehicleModel) {
  buying.value = m.id
  try {
    await api.post(`/dealership/${m.id}/buy`)
    toast.success(`Purchased ${m.name}.`)
    await load()
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    buying.value = null
  }
}

const working = ref<number | null>(null)

async function repair(v: Vehicle) {
  working.value = v.id
  try {
    const { data } = await api.post(`/vehicles/${v.id}/repair`)
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
const powertrainIcon: Record<string, string> = { diesel: '⛽', electric: '⚡', hydrogen: '💧' }

function barColor(v: number) {
  return v > 60 ? 'bg-gain' : v > 30 ? 'bg-gold' : 'bg-loss'
}

onMounted(() => load().catch((e) => toast.error(apiError(e))))
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Fleet &amp; Dealership</h1>
        <p class="text-slate-400 text-sm">Maintain your trucks and expand the garage.</p>
      </div>
      <div class="flex gap-2 p-1 rounded-xl bg-ink-900/70">
        <button class="px-4 py-1.5 rounded-lg text-sm font-semibold transition"
          :class="tab === 'fleet' ? 'bg-brand text-ink-950' : 'text-slate-400'" @click="tab = 'fleet'">
          My Fleet ({{ vehicles.length }})
        </button>
        <button class="px-4 py-1.5 rounded-lg text-sm font-semibold transition"
          :class="tab === 'dealership' ? 'bg-brand text-ink-950' : 'text-slate-400'" @click="tab = 'dealership'">
          Dealership
        </button>
      </div>
    </div>

    <!-- Fleet -->
    <div v-if="tab === 'fleet'" class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="v in vehicles" :key="v.id" class="glass p-4">
        <div class="flex items-center justify-between">
          <div class="flex items-center gap-2">
            <span class="h-3 w-3 rounded-sm" :style="{ background: v.livery_color }" />
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
        </div>

        <div class="grid grid-cols-3 gap-2 mt-3 text-center text-[11px]">
          <div><p class="stat-label">Cap</p><p class="font-mono">{{ num((v as any).effective_capacity_weight ?? v.model?.capacity_weight ?? 0, 1) }}t</p></div>
          <div><p class="stat-label">Speed</p><p class="font-mono">{{ v.model?.top_speed }}</p></div>
          <div><p class="stat-label">Class</p><p class="font-mono capitalize">{{ v.model?.class }}</p></div>
        </div>

        <!-- Service & upgrades -->
        <div class="mt-3 pt-3 border-t border-white/5">
          <div class="flex items-center justify-between mb-2">
            <span class="stat-label">Upgrades ({{ (v as any).upgrade_slots_used ?? 0 }}/{{ v.model?.upgrade_slots ?? 0 }})</span>
            <button class="btn-ghost !py-1 !px-2 text-[11px]" :disabled="working === v.id || v.status === 'en_route'" @click="repair(v)">🔧 Service</button>
          </div>
          <div class="grid grid-cols-3 gap-1.5">
            <button class="btn-ghost !py-1 text-[11px] flex-col" :disabled="working === v.id" @click="upgrade(v, 'engine')">
              ⚙ Engine <span class="text-brand-soft">L{{ (v as any).engine_level ?? 0 }}</span>
            </button>
            <button class="btn-ghost !py-1 text-[11px]" :disabled="working === v.id" @click="upgrade(v, 'tires')">
              ◍ Tyres <span class="text-brand-soft">L{{ (v as any).tires_level ?? 0 }}</span>
            </button>
            <button class="btn-ghost !py-1 text-[11px]" :disabled="working === v.id" @click="upgrade(v, 'trailer')">
              ▤ Trailer <span class="text-brand-soft">L{{ (v as any).trailer_level ?? 0 }}</span>
            </button>
          </div>
        </div>
      </div>
      <div v-if="!vehicles.length" class="glass p-8 text-center text-slate-400 col-span-full">No vehicles yet.</div>
    </div>

    <!-- Dealership -->
    <div v-else class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="m in catalog" :key="m.id" class="glass p-4 flex flex-col" :class="m.locked ? 'opacity-60' : 'glass-hover'">
        <div class="flex items-start justify-between">
          <div>
            <p class="font-semibold text-sm">{{ m.name }}</p>
            <p class="text-[11px] text-slate-400">{{ m.brand }} · {{ powertrainIcon[m.powertrain] }} {{ m.powertrain }}</p>
          </div>
          <span class="chip bg-white/5 text-slate-300 capitalize">{{ m.class }}</span>
        </div>

        <div class="grid grid-cols-2 gap-2 mt-3 text-[11px]">
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Capacity</p><p class="font-mono">{{ num(m.capacity_weight, 1) }}t / {{ num(m.capacity_volume, 0) }}m³</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Top speed</p><p class="font-mono">{{ m.top_speed }} km/h</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Economy</p><p class="font-mono">{{ m.fuel_economy > 0 ? m.fuel_economy + ' L/km' : 'electric' }}</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-1.5"><p class="stat-label">Handling</p><p class="font-mono">{{ [m.can_reefer && '❄', m.can_tanker && '⬢', m.can_hazmat && '☣'].filter(Boolean).join(' ') || '—' }}</p></div>
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
  </div>
</template>
