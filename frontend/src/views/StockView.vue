<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { Stock } from '../types'
import { credits, num, cur } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()
const stocks = ref<Stock[]>([])
const portfolioValue = ref(0)
const loading = ref(false)
const qty = ref<Record<number, number>>({})
const trading = ref<number | null>(null)
let poll: number | undefined

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const { data } = await api.get('/stocks')
    stocks.value = data.data
    portfolioValue.value = data.portfolio_value
    for (const s of stocks.value) if (!qty.value[s.id]) qty.value[s.id] = 10
  } catch (e) {
    if (!silent) toast.error(apiError(e))
  } finally {
    loading.value = false
  }
}

async function trade(s: Stock, action: 'buy' | 'sell') {
  trading.value = s.id
  try {
    const { data } = await api.post(`/stocks/${s.id}/${action}`, { shares: qty.value[s.id] })
    toast.success(data.message)
    await load(true)
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    trading.value = null
  }
}

onMounted(() => {
  load()
  poll = window.setInterval(() => load(true), 20000)
})
onUnmounted(() => clearInterval(poll))
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Stock Exchange</h1>
        <p class="text-slate-400 text-sm">Invest in listed logistics firms. Prices move with the economy; holdings pay dividends.</p>
      </div>
      <div class="glass px-4 py-2 text-right">
        <p class="stat-label">Portfolio value</p>
        <p class="font-mono font-semibold text-gold">{{ credits(portfolioValue) }}</p>
      </div>
    </div>

    <div v-if="loading" class="grid place-items-center h-48 text-slate-500">Loading the exchange…</div>

    <div v-else class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="s in stocks" :key="s.id" class="glass p-4">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="font-semibold text-sm truncate">{{ s.name }}</p>
            <p class="text-[11px] text-slate-400">{{ s.sector }} · {{ (s.dividend_yield * 100).toFixed(1) }}% div</p>
          </div>
          <div class="text-right shrink-0">
            <p class="font-mono font-semibold">{{ cur() }}{{ num(s.share_price, 2) }}</p>
            <p class="text-[11px] font-mono" :class="s.change_pct >= 0 ? 'text-gain' : 'text-loss'">
              {{ s.change_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(s.change_pct) }}%
            </p>
          </div>
        </div>

        <div v-if="s.shares_held" class="mt-2 text-[11px] bg-ink-900/50 rounded-lg px-3 py-1.5 flex justify-between">
          <span class="text-slate-400">{{ num(s.shares_held) }} shares @ {{ cur() }}{{ num(s.avg_cost, 2) }}</span>
          <span class="font-mono">{{ credits(Math.round(s.position_value * 100)) }}</span>
        </div>

        <div class="mt-3 grid grid-cols-[1fr_auto_auto] gap-2 items-center">
          <input v-model.number="qty[s.id]" type="number" min="1" class="input !py-1.5 text-xs" />
          <button class="btn-ghost !py-1.5 text-xs" :disabled="trading === s.id" @click="trade(s, 'buy')">Buy</button>
          <button class="btn-primary !py-1.5 text-xs" :disabled="trading === s.id || !s.shares_held" @click="trade(s, 'sell')">Sell</button>
        </div>
      </div>
    </div>
  </div>
</template>
