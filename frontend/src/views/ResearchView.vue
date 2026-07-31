<script setup lang="ts">
import { onMounted, ref, computed } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'

const game = useGameStore()
const toast = useToastStore()

interface Node {
  key: string; name: string; branch: string; cost: number; requires: string[]
  bonus: Record<string, number>; unlocked: boolean; available: boolean; affordable: boolean
}
const nodes = ref<Node[]>([])
const points = ref(0)
const unlocking = ref<string | null>(null)

const branches = computed(() => {
  const groups: Record<string, Node[]> = {}
  for (const n of nodes.value) (groups[n.branch] ??= []).push(n)
  return groups
})

const BRANCH_META: Record<string, { label: string; icon: string; color: string }> = {
  efficiency: { label: 'Efficiency', icon: '⚙', color: '#38bdf8' },
  capability: { label: 'Capability', icon: '✦', color: '#a78bfa' },
  business: { label: 'Business', icon: '₡', color: '#fbbf24' },
}

function bonusLabel(bonus: Record<string, number>): string {
  return Object.entries(bonus)
    .map(([k, v]) => `${(v > 0 ? '+' : '') + Math.round(v * 100)}% ${k.replace(/_/g, ' ')}`)
    .join(' · ')
}

async function load() {
  const { data } = await api.get('/research')
  nodes.value = data.nodes
  points.value = data.research_points
}

async function unlock(n: Node) {
  unlocking.value = n.key
  try {
    const { data } = await api.post(`/research/${n.key}/unlock`)
    toast.success(data.message)
    await load()
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    unlocking.value = null
  }
}

onMounted(() => load().catch((e) => toast.error(apiError(e))))
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Research &amp; Development</h1>
        <p class="text-slate-400 text-sm">Spend research points earned from levelling up to unlock permanent edges.</p>
      </div>
      <div class="glass px-4 py-2">
        <span class="stat-label">Research Points</span>
        <span class="ml-2 font-mono font-bold text-brand-glow text-lg">{{ points }}</span>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div v-for="(list, branch) in branches" :key="branch" class="space-y-3">
        <div class="flex items-center gap-2">
          <span class="text-lg" :style="{ color: BRANCH_META[branch]?.color }">{{ BRANCH_META[branch]?.icon }}</span>
          <h2 class="font-semibold">{{ BRANCH_META[branch]?.label }}</h2>
        </div>

        <div v-for="n in list" :key="n.key" class="glass p-4"
          :class="n.unlocked ? 'border-brand/40' : n.available ? 'glass-hover' : 'opacity-70'">
          <div class="flex items-start justify-between gap-2">
            <div>
              <p class="font-semibold text-sm">{{ n.name }}</p>
              <p class="text-[11px] text-brand-soft mt-0.5">{{ bonusLabel(n.bonus) }}</p>
            </div>
            <span v-if="n.unlocked" class="chip bg-gain/20 text-gain">✓ Owned</span>
          </div>

          <div v-if="n.requires.length" class="text-[10px] text-slate-500 mt-2">
            Requires: {{ n.requires.join(', ') }}
          </div>

          <div v-if="!n.unlocked" class="flex items-center justify-between mt-3">
            <span class="font-mono text-sm" :class="n.affordable ? 'text-brand-glow' : 'text-slate-500'">{{ n.cost }} RP</span>
            <button class="btn-primary !py-1.5" :disabled="!n.available || !n.affordable || unlocking === n.key" @click="unlock(n)">
              {{ unlocking === n.key ? '…' : n.available ? 'Unlock' : 'Locked' }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
