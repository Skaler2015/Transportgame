<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

interface Mission {
  id: number; period: string; metric: string; title: string; description: string
  target: number; progress: number; percent: number; reward_cash: number; reward_xp: number; status: string
}
const missions = ref<Mission[]>([])
const busy = ref<number | null>(null)

const daily = computed(() => missions.value.filter((m) => m.period === 'daily'))
const weekly = computed(() => missions.value.filter((m) => m.period === 'weekly'))

async function load() {
  const { data } = await api.get('/missions')
  missions.value = data.data
}

async function claim(m: Mission) {
  busy.value = m.id
  try {
    const { data } = await api.post(`/missions/${m.id}/claim`)
    toast.success(data.message)
    await load()
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

onMounted(() => load().catch((e) => toast.error(apiError(e))))
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold">Missions</h1>
      <p class="text-slate-400 text-sm">Complete objectives for bonus Credits &amp; XP. They refresh daily and weekly.</p>
    </div>

    <section v-for="group in [{ label: 'Daily', list: daily }, { label: 'Weekly', list: weekly }]" :key="group.label" class="space-y-3">
      <h2 class="font-semibold">{{ group.label }}</h2>
      <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div v-for="m in group.list" :key="m.id" class="glass p-4">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="font-semibold text-sm">{{ m.title }}</p>
              <p class="text-[11px] text-slate-400">{{ m.description }}</p>
            </div>
            <span v-if="m.status === 'completed'" class="chip bg-gain/20 text-gain">Ready</span>
          </div>

          <div class="mt-3">
            <div class="flex justify-between text-[11px] mb-1">
              <span class="text-slate-400">{{ num(m.progress) }} / {{ num(m.target) }}</span>
              <span class="font-mono">{{ m.percent }}%</span>
            </div>
            <div class="h-2 rounded-full bg-ink-700 overflow-hidden">
              <div class="h-full bg-gradient-to-r from-brand-deep to-brand-glow" :style="{ width: m.percent + '%' }" />
            </div>
          </div>

          <div class="flex items-center justify-between mt-3">
            <p class="text-[11px]">
              <span class="text-gold font-mono">{{ credits(m.reward_cash) }}</span>
              <span class="text-brand-soft font-mono ml-2">+{{ m.reward_xp }} XP</span>
            </p>
            <button class="btn-primary !py-1.5" :disabled="m.status !== 'completed' || busy === m.id" @click="claim(m)">
              {{ busy === m.id ? '…' : m.status === 'completed' ? 'Claim' : 'In progress' }}
            </button>
          </div>
        </div>
        <div v-if="!group.list.length" class="glass p-6 text-center text-slate-500 text-sm col-span-full">No {{ group.label.toLowerCase() }} missions right now.</div>
      </div>
    </section>
  </div>
</template>
