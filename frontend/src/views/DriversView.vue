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

async function load() {
  const { data } = await api.get('/drivers')
  drivers.value = data.data
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
      <button class="btn-primary" :disabled="hiring" @click="hire">＋ Hire Driver ({{ credits(3500_00) }})</button>
    </div>

    <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="d in drivers" :key="d.id" class="glass p-4">
        <div class="flex items-center gap-3">
          <div class="h-10 w-10 rounded-full bg-gradient-to-br from-ink-600 to-ink-700 flex items-center justify-center font-bold">
            {{ d.name.charAt(0) }}
          </div>
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-sm truncate">{{ d.name }}</p>
            <p class="text-[11px] text-slate-400">{{ num(d.shipments_done) }} runs · {{ credits(d.salary) }}/cycle</p>
          </div>
          <span class="chip capitalize" :class="statusChip[d.status]">{{ d.status }}</span>
        </div>

        <div class="mt-3 space-y-2">
          <div v-for="stat in [
            { label: 'Skill', val: d.skill, invert: false },
            { label: 'Morale', val: d.morale, invert: false },
            { label: 'Fatigue', val: d.fatigue, invert: true },
            { label: 'Loyalty', val: d.loyalty, invert: false },
          ]" :key="stat.label">
            <div class="flex justify-between text-[11px] mb-1"><span class="text-slate-400">{{ stat.label }}</span><span class="font-mono">{{ stat.val }}</span></div>
            <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden">
              <div class="h-full" :class="bar(stat.val, stat.invert)" :style="{ width: stat.val + '%' }" />
            </div>
          </div>
        </div>

        <div v-if="d.hazmat_licence" class="mt-3">
          <span class="chip bg-loss/15 text-loss">☣ Hazmat certified</span>
        </div>
      </div>
      <div v-if="!drivers.length" class="glass p-8 text-center text-slate-400 col-span-full">No crew yet.</div>
    </div>
  </div>
</template>
