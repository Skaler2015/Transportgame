<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { Driver } from '../types'
import { credits, num } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()
const drivers = ref<Driver[]>([])
const hiring = ref(false)
const working = ref<number | null>(null)
const costs = ref({ hire: 3500_00, train: 0, licence: 0, vacation: 0, licence_days: 30 })

async function load() {
  const { data } = await api.get('/drivers')
  drivers.value = data.data
  if (data.costs) costs.value = data.costs
}

async function action(d: Driver, type: 'train' | 'licence' | 'vacation') {
  working.value = d.id
  try {
    const { data } = await api.post(`/drivers/${d.id}/action`, { action: type })
    toast.success(data.message)
    await load()
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    working.value = null
  }
}

async function hire() {
  hiring.value = true
  try {
    const { data } = await api.post('/drivers/hire')
    toast.success(`Hired ${data.data.name}.`)
    await load()
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    hiring.value = false
  }
}

const statusChip: Record<string, string> = {
  available: 'bg-gain/20 text-gain', driving: 'bg-brand/20 text-brand-soft', resting: 'bg-gold/20 text-gold',
}
// Colour a 0–100 stat (green good → red poor); pass invert for fatigue.
function metricText(v: number, invert = false): string {
  const good = invert ? 100 - v : v
  return good > 60 ? 'text-gain' : good > 30 ? 'text-gold' : 'text-loss'
}

// ---- Sortable table --------------------------------------------------------
type SortKey = 'name' | 'rank' | 'age' | 'runs' | 'status' | 'skill' | 'health' | 'morale' | 'fatigue' | 'rain' | 'eco'
const sortField = ref<SortKey>('skill')
const sortDir = ref<'asc' | 'desc'>('desc')
function setSort(k: SortKey) {
  if (sortField.value === k) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  else { sortField.value = k; sortDir.value = ['name', 'status', 'rank'].includes(k) ? 'asc' : 'desc' }
}
function arrow(k: SortKey): string {
  return sortField.value !== k ? '' : (sortDir.value === 'asc' ? ' ▲' : ' ▼')
}
function sortVal(d: Driver, k: SortKey): number | string {
  switch (k) {
    case 'name': return (d.name || '').toLowerCase()
    case 'rank': return (d.rank || '').toLowerCase()
    case 'age': return d.age ?? 0
    case 'runs': return d.shipments_done ?? 0
    case 'status': return d.status
    case 'skill': return d.skill ?? 0
    case 'health': return d.health ?? 100
    case 'morale': return d.morale ?? 0
    case 'fatigue': return d.fatigue ?? 0
    case 'rain': return d.rain_skill ?? 0
    default: return d.eco_skill ?? 0
  }
}
const sortedDrivers = computed(() => {
  const arr = [...drivers.value]
  arr.sort((a, b) => {
    const av = sortVal(a, sortField.value), bv = sortVal(b, sortField.value)
    const cmp = typeof av === 'string' ? av.localeCompare(bv as string) : (av as number) - (bv as number)
    return sortDir.value === 'asc' ? cmp : -cmp
  })
  return arr
})

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
        <h1 class="text-2xl font-bold">Crew</h1>
        <p class="text-slate-400 text-sm">Your drivers. Skill lifts speed &amp; safety; fatigue does the opposite.</p>
      </div>
      <button class="btn-primary" :disabled="hiring" @click="hire">＋ Hire Driver ({{ credits(costs.hire) }})</button>
    </div>

    <div v-if="!drivers.length" class="glass p-8 text-center text-slate-400">No crew yet.</div>

    <!-- Desktop: sortable table (md and up) -->
    <div v-else class="hidden md:block glass !p-0 overflow-hidden">
      <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[1000px] border-collapse">
        <thead class="text-[10px] uppercase tracking-wider text-slate-400 select-none bg-ink-900/80 backdrop-blur sticky top-0 z-10">
          <tr class="border-b border-white/10 [&>th]:cursor-pointer [&>th:hover]:text-brand-soft [&>th]:transition">
            <th class="text-left px-3 py-3 font-semibold" @click="setSort('name')">Driver{{ arrow('name') }}</th>
            <th class="text-left px-2" @click="setSort('rank')">Rank{{ arrow('rank') }}</th>
            <th class="text-right px-2" @click="setSort('age')">Age{{ arrow('age') }}</th>
            <th class="text-right px-2" @click="setSort('runs')">Runs{{ arrow('runs') }}</th>
            <th class="text-left px-2" @click="setSort('status')">Status{{ arrow('status') }}</th>
            <th class="text-right px-2" @click="setSort('skill')">Skill{{ arrow('skill') }}</th>
            <th class="text-right px-2" @click="setSort('health')">Health{{ arrow('health') }}</th>
            <th class="text-right px-2" @click="setSort('morale')">Morale{{ arrow('morale') }}</th>
            <th class="text-right px-2" @click="setSort('fatigue')">Fatigue{{ arrow('fatigue') }}</th>
            <th class="text-right px-2" @click="setSort('rain')" title="Wet-weather driving skill">Rain{{ arrow('rain') }}</th>
            <th class="text-right px-2" @click="setSort('eco')" title="Fuel-economy skill">Eco{{ arrow('eco') }}</th>
            <th class="text-center px-2 !cursor-default">Licence</th>
            <th class="text-right px-3 !cursor-default"></th>
          </tr>
        </thead>
        <tbody>
          <template v-for="d in sortedDrivers" :key="d.id">
            <tr class="border-b border-white/5 transition hover:bg-brand/[0.06] odd:bg-white/[0.015] cursor-pointer"
              :class="!d.is_licensed ? 'bg-loss/5' : ''" @click="toggleExpand(d.id)">
              <td class="px-3 py-2.5">
                <div class="flex items-center gap-2.5 min-w-0">
                  <div class="h-8 w-8 rounded-full bg-gradient-to-br from-ink-600 to-ink-700 flex items-center justify-center font-bold text-xs shrink-0">{{ d.name.charAt(0) }}</div>
                  <p class="font-semibold truncate">{{ d.name }}</p>
                </div>
              </td>
              <td class="px-2 text-brand-soft">{{ d.rank || 'Rookie' }}</td>
              <td class="px-2 text-right font-mono text-slate-300">{{ d.age ?? '—' }}</td>
              <td class="px-2 text-right font-mono text-slate-300">{{ num(d.shipments_done) }}</td>
              <td class="px-2"><span class="chip capitalize text-[10px]" :class="statusChip[d.status]">{{ d.status }}</span></td>
              <td class="px-2 text-right font-mono" :class="metricText(d.skill)">{{ d.skill }}</td>
              <td class="px-2 text-right font-mono" :class="metricText(d.health ?? 100)">{{ d.health ?? 100 }}</td>
              <td class="px-2 text-right font-mono" :class="metricText(d.morale)">{{ d.morale }}</td>
              <td class="px-2 text-right font-mono" :class="metricText(d.fatigue, true)">{{ d.fatigue }}</td>
              <td class="px-2 text-right font-mono text-slate-300">{{ d.rain_skill ?? 0 }}</td>
              <td class="px-2 text-right font-mono text-slate-300">{{ d.eco_skill ?? 0 }}</td>
              <td class="px-2 text-center whitespace-nowrap">
                <span :class="d.is_licensed ? 'text-gain' : 'text-loss'">{{ d.is_licensed ? '📋 OK' : '⚠ exp' }}</span>
                <span v-if="d.hazmat_licence" title="Hazmat" class="text-loss ml-0.5">☣</span>
              </td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button class="btn-ghost !py-1 !px-2 text-[11px]" @click.stop="toggleExpand(d.id)">{{ expandedId === d.id ? '×' : 'Manage' }}</button>
              </td>
            </tr>
            <!-- Actions drawer -->
            <tr v-if="expandedId === d.id" class="bg-ink-900/50 border-b border-white/5">
              <td colspan="13" class="px-3 py-3">
                <div class="flex flex-wrap items-center gap-2">
                  <button class="btn-ghost !py-1.5 !px-3 text-[11px]" :disabled="working === d.id" @click="action(d, 'train')" title="+ skill & specialties">🎓 Train · {{ credits(costs.train) }}</button>
                  <button class="btn-ghost !py-1.5 !px-3 text-[11px]" :class="!d.is_licensed && 'ring-1 ring-loss/50'" :disabled="working === d.id" @click="action(d, 'licence')" :title="`Renew for ${costs.licence_days} days`">📋 Licence · {{ credits(costs.licence) }}</button>
                  <button class="btn-ghost !py-1.5 !px-3 text-[11px]" :disabled="working === d.id || d.status === 'driving'" @click="action(d, 'vacation')" title="Rest: restore health & fatigue">🏖 Rest · {{ credits(costs.vacation) }}</button>
                  <span v-if="d.status === 'driving'" class="text-[11px] text-slate-500 ml-1">On a run — can rest once back.</span>
                </div>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
      </div>
    </div>

    <!-- Mobile: compact card list -->
    <div v-if="drivers.length" class="md:hidden space-y-2">
      <div v-for="d in sortedDrivers" :key="d.id" class="glass !p-3" :class="!d.is_licensed ? 'ring-1 ring-loss/40' : ''">
        <div class="cursor-pointer" @click="toggleExpand(d.id)">
          <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
              <div class="h-8 w-8 rounded-full bg-gradient-to-br from-ink-600 to-ink-700 flex items-center justify-center font-bold text-xs shrink-0">{{ d.name.charAt(0) }}</div>
              <div class="min-w-0">
                <p class="font-semibold text-sm truncate">{{ d.name }}</p>
                <p class="text-[10px] text-slate-500 truncate">{{ d.rank || 'Rookie' }} · age {{ d.age ?? '—' }} · {{ num(d.shipments_done) }} runs</p>
              </div>
            </div>
            <span class="chip capitalize text-[10px] shrink-0" :class="statusChip[d.status]">{{ d.status }}</span>
          </div>
          <div class="grid grid-cols-4 gap-1 mt-2 text-center text-[11px]">
            <div><p class="stat-label">Skill</p><p class="font-mono" :class="metricText(d.skill)">{{ d.skill }}</p></div>
            <div><p class="stat-label">Health</p><p class="font-mono" :class="metricText(d.health ?? 100)">{{ d.health ?? 100 }}</p></div>
            <div><p class="stat-label">Morale</p><p class="font-mono" :class="metricText(d.morale)">{{ d.morale }}</p></div>
            <div><p class="stat-label">Fatigue</p><p class="font-mono" :class="metricText(d.fatigue, true)">{{ d.fatigue }}</p></div>
          </div>
          <div class="flex items-center gap-1.5 mt-2">
            <span class="chip text-[9px]" :class="d.is_licensed ? 'bg-gain/20 text-gain' : 'bg-loss/20 text-loss'">{{ d.is_licensed ? '📋 Licensed' : '⚠ Expired' }}</span>
            <span v-if="d.hazmat_licence" class="chip text-[9px] bg-loss/15 text-loss">☣ Hazmat</span>
            <span class="text-[10px] text-slate-500 ml-auto">🌧{{ d.rain_skill ?? 0 }} · ⛽{{ d.eco_skill ?? 0 }}</span>
          </div>
        </div>
        <div v-if="expandedId === d.id" class="grid grid-cols-3 gap-1.5 mt-2 pt-2 border-t border-white/10">
          <button class="btn-ghost !py-1 !flex-col gap-0.5 leading-tight" :disabled="working === d.id" @click="action(d, 'train')">
            <span class="text-[10px]">🎓 Train</span><span class="text-[9px] font-mono text-slate-400">{{ credits(costs.train) }}</span>
          </button>
          <button class="btn-ghost !py-1 !flex-col gap-0.5 leading-tight" :class="!d.is_licensed && 'ring-1 ring-loss/50'" :disabled="working === d.id" @click="action(d, 'licence')">
            <span class="text-[10px]">📋 Licence</span><span class="text-[9px] font-mono text-slate-400">{{ credits(costs.licence) }}</span>
          </button>
          <button class="btn-ghost !py-1 !flex-col gap-0.5 leading-tight" :disabled="working === d.id || d.status === 'driving'" @click="action(d, 'vacation')">
            <span class="text-[10px]">🏖 Rest</span><span class="text-[9px] font-mono text-slate-400">{{ credits(costs.vacation) }}</span>
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
