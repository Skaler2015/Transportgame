<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'

const auth = useAuthStore()
const toast = useToastStore()

interface Row {
  rank: number; name: string; logo_color: string; level: number
  reputation: number; value: number; shipments_completed: number; fleet_size: number
  is_ai: boolean; strategy: string | null; market_share: number
}
interface Meta { total_firms: number; ai_firms: number; world_revenue: number }

const rows = ref<Row[]>([])
const meta = ref<Meta>({ total_firms: 0, ai_firms: 0, world_revenue: 0 })
const loading = ref(true)

// How each rival plays the market — shown as a small badge on AI firms.
const STRATEGY_LABEL: Record<string, string> = {
  expander: 'Expanding',
  undercutter: 'Undercutting',
  premium: 'Premium',
  regional: 'Regional',
}

const myShare = computed(
  () => rows.value.find((r) => r.name === auth.company?.name)?.market_share ?? 0,
)

async function load() {
  try {
    const { data } = await api.get('/world/leaderboard')
    rows.value = data.data
    meta.value = data.meta ?? meta.value
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    loading.value = false
  }
}
onMounted(load)
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-bold">Leaderboard</h1>
        <p class="text-slate-400 text-sm">
          You compete against <span class="text-brand-soft font-semibold">{{ meta.ai_firms }}</span>
          rival firms for freight and market share across Transoria.
        </p>
      </div>
      <button class="btn-ghost !py-1.5 text-xs" @click="load">↻ Refresh</button>
    </div>

    <!-- World summary tiles -->
    <div class="grid grid-cols-3 gap-3">
      <div class="glass p-3">
        <p class="stat-label">Firms in the world</p>
        <p class="text-lg font-bold">{{ num(meta.total_firms) }}</p>
      </div>
      <div class="glass p-3">
        <p class="stat-label">Rival AI companies</p>
        <p class="text-lg font-bold text-brand-soft">{{ num(meta.ai_firms) }}</p>
      </div>
      <div class="glass p-3">
        <p class="stat-label">Your market share</p>
        <p class="text-lg font-bold" :class="myShare > 0 ? 'text-gain' : 'text-slate-400'">
          {{ (myShare * 100).toFixed(1) }}%
        </p>
      </div>
    </div>

    <div class="glass overflow-hidden">
      <div class="overflow-x-auto">
      <table class="w-full text-sm min-w-[620px]">
        <thead>
          <tr class="text-left text-slate-400 border-b border-white/10">
            <th class="px-4 py-3 font-semibold">#</th>
            <th class="px-4 py-3 font-semibold">Company</th>
            <th class="px-4 py-3 font-semibold text-right">Reputation</th>
            <th class="px-4 py-3 font-semibold text-right">Share</th>
            <th class="px-4 py-3 font-semibold text-right hidden sm:table-cell">Value</th>
            <th class="px-4 py-3 font-semibold text-right hidden md:table-cell">Delivered</th>
            <th class="px-4 py-3 font-semibold text-right hidden md:table-cell">Fleet</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.rank"
            class="border-b border-white/5 transition"
            :class="r.name === auth.company?.name ? 'bg-brand/10' : 'hover:bg-white/5'">
            <td class="px-4 py-3 font-mono">
              <span v-if="r.rank <= 3" class="text-lg">{{ ['🥇', '🥈', '🥉'][r.rank - 1] }}</span>
              <span v-else class="text-slate-500">{{ r.rank }}</span>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2 flex-wrap">
                <span class="h-6 w-6 rounded-md flex items-center justify-center text-[11px] font-bold text-ink-950 shrink-0"
                  :style="{ background: r.logo_color }">{{ r.name.charAt(0) }}</span>
                <span class="font-medium">{{ r.name }}</span>
                <span v-if="r.name === auth.company?.name" class="chip bg-brand/20 text-brand-soft">You</span>
                <span v-else-if="r.is_ai" class="chip bg-white/5 text-slate-400" :title="'AI rival · ' + (STRATEGY_LABEL[r.strategy ?? ''] ?? 'AI')">
                  🤖 {{ STRATEGY_LABEL[r.strategy ?? ''] ?? 'AI' }}
                </span>
                <span class="chip bg-white/5 text-slate-400">Lv {{ r.level }}</span>
              </div>
            </td>
            <td class="px-4 py-3 text-right font-mono text-brand-soft">{{ num(r.reputation) }}</td>
            <td class="px-4 py-3 text-right font-mono">{{ (r.market_share * 100).toFixed(1) }}%</td>
            <td class="px-4 py-3 text-right font-mono hidden sm:table-cell">{{ credits(r.value, { compact: true }) }}</td>
            <td class="px-4 py-3 text-right font-mono hidden md:table-cell">{{ num(r.shipments_completed) }}</td>
            <td class="px-4 py-3 text-right font-mono hidden md:table-cell">{{ r.fleet_size }}</td>
          </tr>
        </tbody>
      </table>
      </div>
      <div v-if="loading" class="p-8 text-center text-slate-400">Loading rankings…</div>
      <div v-else-if="!rows.length" class="p-8 text-center text-slate-400">No companies ranked yet.</div>
    </div>
  </div>
</template>
