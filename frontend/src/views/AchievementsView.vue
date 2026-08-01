<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import type { Achievement } from '../types'
import { credits, num } from '../utils/format'

const toast = useToastStore()
const items = ref<Achievement[]>([])
const summary = ref({ unlocked: 0, total: 0 })
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/achievements')
    items.value = data.data
    summary.value = data.summary
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    loading.value = false
  }
}

// Group by category, unlocked first within each group.
const grouped = computed(() => {
  const map = new Map<string, Achievement[]>()
  for (const a of items.value) {
    if (!map.has(a.category)) map.set(a.category, [])
    map.get(a.category)!.push(a)
  }
  return [...map.entries()].map(([category, list]) => ({
    category,
    list: [...list].sort((a, b) => Number(b.unlocked) - Number(a.unlocked) || a.threshold - b.threshold),
  }))
})

const pct = computed(() =>
  summary.value.total ? Math.round((summary.value.unlocked / summary.value.total) * 100) : 0,
)

onMounted(load)
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Achievements</h1>
        <p class="text-slate-400 text-sm">Milestones on the road to a logistics empire.</p>
      </div>
      <div class="glass px-4 py-2 text-right">
        <p class="stat-label">Unlocked</p>
        <p class="font-mono font-semibold text-gold">{{ summary.unlocked }} / {{ summary.total }} · {{ pct }}%</p>
      </div>
    </div>

    <div class="h-2 rounded-full bg-ink-700 overflow-hidden">
      <div class="h-full bg-gradient-to-r from-brand to-brand-glow transition-all" :style="{ width: pct + '%' }" />
    </div>

    <div v-if="loading" class="grid place-items-center h-48 text-slate-500">Loading achievements…</div>

    <div v-else class="space-y-6">
      <section v-for="g in grouped" :key="g.category" class="space-y-3">
        <h2 class="font-semibold text-slate-300">{{ g.category }}</h2>
        <div class="grid sm:grid-cols-2 xl:grid-cols-3 gap-3">
          <div
            v-for="a in g.list"
            :key="a.key"
            class="glass p-4 flex gap-3 transition"
            :class="a.unlocked ? 'ring-1 ring-gold/40' : 'opacity-75'"
          >
            <div
              class="h-12 w-12 rounded-xl grid place-items-center text-2xl shrink-0"
              :class="a.unlocked ? 'bg-gold/15' : 'bg-ink-900/60 grayscale'"
            >{{ a.icon }}</div>
            <div class="min-w-0 flex-1">
              <div class="flex items-center justify-between gap-2">
                <p class="font-semibold text-sm truncate">{{ a.name }}</p>
                <span v-if="a.unlocked" class="chip bg-gold/20 text-gold shrink-0">✓</span>
              </div>
              <p class="text-[11px] text-slate-400 leading-snug mt-0.5">{{ a.description }}</p>

              <div v-if="!a.unlocked" class="mt-2">
                <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden">
                  <div class="h-full bg-brand/70" :style="{ width: a.progress_pct + '%' }" />
                </div>
                <p class="text-[10px] text-slate-500 mt-1">{{ num(a.progress) }} / {{ num(a.threshold) }}</p>
              </div>

              <p class="text-[10px] mt-2" :class="a.unlocked ? 'text-gain' : 'text-slate-500'">
                Reward:
                <span v-if="a.reward_cash">{{ credits(a.reward_cash) }}</span>
                <span v-if="a.reward_cash && a.reward_xp"> · </span>
                <span v-if="a.reward_xp">{{ num(a.reward_xp) }} XP</span>
                <span v-if="!a.reward_cash && !a.reward_xp">bragging rights</span>
              </p>
            </div>
          </div>
        </div>
      </section>
    </div>
  </div>
</template>
