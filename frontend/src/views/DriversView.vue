<script setup lang="ts">
import { onMounted, ref } from 'vue'
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
function bar(v: number, invert = false) {
  const good = invert ? 100 - v : v
  return good > 60 ? 'bg-gain' : good > 30 ? 'bg-gold' : 'bg-loss'
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

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="d in drivers" :key="d.id" class="glass p-4">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-full bg-gradient-to-br from-ink-600 to-ink-700 flex items-center justify-center font-bold">
            {{ d.name.charAt(0) }}
          </div>
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-sm truncate">{{ d.name }}</p>
            <p class="text-[11px] text-slate-400">
              <span class="text-brand-soft">{{ d.rank || 'Rookie' }}</span> · age {{ d.age ?? '—' }} · {{ num(d.shipments_done) }} runs
            </p>
          </div>
          <span class="chip capitalize" :class="statusChip[d.status]">{{ d.status }}</span>
        </div>

        <div class="mt-3 space-y-2">
          <div v-for="stat in [
            { label: 'Skill', val: d.skill, invert: false },
            { label: 'Health', val: d.health ?? 100, invert: false },
            { label: 'Morale', val: d.morale, invert: false },
            { label: 'Fatigue', val: d.fatigue, invert: true },
          ]" :key="stat.label">
            <div class="flex justify-between text-[11px] mb-1"><span class="text-slate-400">{{ stat.label }}</span><span class="font-mono">{{ stat.val }}</span></div>
            <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden">
              <div class="h-full" :class="bar(stat.val, stat.invert)" :style="{ width: stat.val + '%' }" />
            </div>
          </div>
        </div>

        <div class="grid grid-cols-2 gap-2 mt-3 text-center text-[11px]">
          <div><p class="stat-label">🌧 Rain</p><p class="font-mono">{{ d.rain_skill ?? 0 }}</p></div>
          <div><p class="stat-label">⛽ Eco</p><p class="font-mono">{{ d.eco_skill ?? 0 }}</p></div>
        </div>

        <div class="flex flex-wrap items-center gap-1.5 mt-3">
          <span class="chip" :class="d.is_licensed ? 'bg-gain/20 text-gain' : 'bg-loss/20 text-loss'">
            {{ d.is_licensed ? '📋 Licensed' : '⚠ Licence expired' }}
          </span>
          <span v-if="d.hazmat_licence" class="chip bg-loss/15 text-loss">☣ Hazmat</span>
        </div>

        <!-- HR actions (price shown under each) -->
        <div class="grid grid-cols-3 gap-1.5 mt-3 pt-3 border-t border-white/5">
          <button class="btn-ghost !py-1 !flex-col gap-0.5 leading-tight" :disabled="working === d.id" @click="action(d, 'train')" title="+ skill & specialties">
            <span class="text-[10px]">🎓 Train</span>
            <span class="text-[9px] font-mono text-slate-400">{{ credits(costs.train) }}</span>
          </button>
          <button class="btn-ghost !py-1 !flex-col gap-0.5 leading-tight" :class="!d.is_licensed && 'ring-1 ring-loss/50'" :disabled="working === d.id" @click="action(d, 'licence')" :title="`Renew for ${costs.licence_days} days`">
            <span class="text-[10px]">📋 Licence</span>
            <span class="text-[9px] font-mono text-slate-400">{{ credits(costs.licence) }}</span>
          </button>
          <button class="btn-ghost !py-1 !flex-col gap-0.5 leading-tight" :disabled="working === d.id || d.status === 'driving'" @click="action(d, 'vacation')" title="Rest: restore health & fatigue">
            <span class="text-[10px]">🏖 Rest</span>
            <span class="text-[9px] font-mono text-slate-400">{{ credits(costs.vacation) }}</span>
          </button>
        </div>
      </div>
      <div v-if="!drivers.length" class="glass p-8 text-center text-slate-400 col-span-full">No crew yet.</div>
    </div>
  </div>
</template>
