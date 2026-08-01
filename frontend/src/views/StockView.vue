<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { Stock } from '../types'
import { credits, num, cur } from '../utils/format'

ChartJS.register(ArcElement, Tooltip, Legend)

const game = useGameStore()
const toast = useToastStore()
const stocks = ref<Stock[]>([])
const portfolioValue = ref(0)
const invested = ref(0)
const dividends = ref(0)
const tip = ref<any>(null)
const loading = ref(false)
const qty = ref<Record<number, number>>({})
const trading = ref<number | null>(null)
let poll: number | undefined

const sortKey = ref<'name' | 'change_pct' | 'dividend_yield' | 'share_price' | 'sector'>('change_pct')
const sortDir = ref<'asc' | 'desc'>('desc')
const sectorFilter = ref('')
const view = ref<'all' | 'gainers' | 'losers' | 'held'>('all')

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const [{ data }, adv] = await Promise.all([
      api.get('/stocks'),
      api.get('/advisor').catch(() => ({ data: { data: [] } })),
    ])
    stocks.value = data.data
    portfolioValue.value = data.portfolio_value
    invested.value = data.invested ?? 0
    dividends.value = data.dividends_earned ?? 0
    tip.value = (adv.data.data || []).find((i: any) => i.icon === '📉' || i.icon === '📈') ?? null
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

// --- Portfolio ---------------------------------------------------------------
const cash = computed(() => game.dashboard?.company?.cash ?? 0)
const unrealized = computed(() => portfolioValue.value - invested.value)
const unrealizedPct = computed(() => invested.value > 0 ? (unrealized.value / invested.value) * 100 : 0)
const held = computed(() => stocks.value.filter((s) => s.shares_held > 0))

const PALETTE = ['#38bdf8', '#a78bfa', '#f472b6', '#fbbf24', '#34d399', '#fb7185', '#22d3ee', '#f59e0b']
const allocData = computed(() => ({
  labels: held.value.map((s) => s.name),
  datasets: [{ data: held.value.map((s) => s.position_value), backgroundColor: held.value.map((_, i) => PALETTE[i % PALETTE.length]), borderColor: '#0b1120', borderWidth: 2, hoverOffset: 5 }],
}))
const allocOptions: any = {
  responsive: true, maintainAspectRatio: false, cutout: '58%',
  plugins: { legend: { display: false }, tooltip: { callbacks: { label: (c: any) => `${c.label}: ${credits(Math.round(Number(c.raw) * 100))}` } } },
}

// --- Market ------------------------------------------------------------------
const marketIndex = computed(() => stocks.value.length
  ? +(stocks.value.reduce((a, s) => a + s.change_pct, 0) / stocks.value.length).toFixed(2) : 0)
const topGainer = computed(() => [...stocks.value].sort((a, b) => b.change_pct - a.change_pct)[0])
const topLoser = computed(() => [...stocks.value].sort((a, b) => a.change_pct - b.change_pct)[0])
const sectors = computed(() => [...new Set(stocks.value.map((s) => s.sector))].sort())

const shown = computed(() => {
  let list = [...stocks.value]
  if (sectorFilter.value) list = list.filter((s) => s.sector === sectorFilter.value)
  if (view.value === 'gainers') list = list.filter((s) => s.change_pct > 0)
  else if (view.value === 'losers') list = list.filter((s) => s.change_pct < 0)
  else if (view.value === 'held') list = list.filter((s) => s.shares_held > 0)
  list.sort((a, b) => {
    const k = sortKey.value
    const av: any = a[k], bv: any = b[k]
    const cmp = typeof av === 'string' ? av.localeCompare(bv) : av - bv
    return sortDir.value === 'asc' ? cmp : -cmp
  })
  return list
})
function setSort(k: typeof sortKey.value) {
  if (sortKey.value === k) sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  else { sortKey.value = k; sortDir.value = k === 'name' || k === 'sector' ? 'asc' : 'desc' }
}

// --- Trade helpers -----------------------------------------------------------
function maxShares(s: Stock): number {
  return Math.max(0, Math.floor((cash.value / 100) / s.share_price))
}
function setMax(s: Stock) { qty.value[s.id] = maxShares(s) }
function estCost(s: Stock): number {
  return Math.round((qty.value[s.id] || 0) * s.share_price * 100)
}
function pnl(s: Stock): number {
  return Math.round((s.share_price - s.avg_cost) * s.shares_held * 100)
}

// --- Sparkline (inline SVG) --------------------------------------------------
function spark(hist: number[]): string {
  if (!hist || hist.length < 2) return ''
  const w = 90, h = 26
  const min = Math.min(...hist), max = Math.max(...hist)
  const range = max - min || 1
  return hist.map((p, i) => {
    const x = (i / (hist.length - 1)) * w
    const y = h - ((p - min) / range) * h
    return `${x.toFixed(1)},${y.toFixed(1)}`
  }).join(' ')
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
    </div>

    <!-- Portfolio dashboard -->
    <div class="grid lg:grid-cols-4 gap-3">
      <div class="lg:col-span-3 grid grid-cols-2 sm:grid-cols-4 gap-3">
        <div class="glass !p-3"><p class="stat-label">Portfolio value</p><p class="font-mono font-semibold text-gold text-lg">{{ credits(portfolioValue) }}</p></div>
        <div class="glass !p-3"><p class="stat-label">Invested</p><p class="font-mono font-semibold text-lg">{{ credits(invested) }}</p></div>
        <div class="glass !p-3"><p class="stat-label">Unrealized P/L</p><p class="font-mono font-semibold text-lg" :class="unrealized >= 0 ? 'text-gain' : 'text-loss'">{{ credits(unrealized, { sign: true }) }} <span class="text-xs">({{ unrealizedPct >= 0 ? '+' : '' }}{{ unrealizedPct.toFixed(1) }}%)</span></p></div>
        <div class="glass !p-3"><p class="stat-label">Dividends earned</p><p class="font-mono font-semibold text-gain text-lg">{{ credits(dividends) }}</p></div>
        <!-- Market strip -->
        <div class="glass !p-3 col-span-2 sm:col-span-4 flex flex-wrap items-center gap-x-6 gap-y-1 text-sm">
          <span>Market: <span class="font-mono font-semibold" :class="marketIndex >= 0 ? 'text-gain' : 'text-loss'">{{ marketIndex >= 0 ? '▲' : '▼' }} {{ Math.abs(marketIndex) }}%</span></span>
          <span v-if="topGainer" class="text-slate-400">Top gainer: <span class="text-gain">{{ topGainer.name }} +{{ topGainer.change_pct }}%</span></span>
          <span v-if="topLoser" class="text-slate-400">Top loser: <span class="text-loss">{{ topLoser.name }} {{ topLoser.change_pct }}%</span></span>
          <RouterLink v-if="tip" to="/advisor" class="ml-auto text-brand hover:underline text-xs">{{ tip.icon }} {{ tip.title }} →</RouterLink>
        </div>
      </div>
      <!-- Allocation pie -->
      <div class="glass p-4">
        <p class="stat-label mb-1">Allocation</p>
        <div class="h-36"><Doughnut v-if="held.length" :data="allocData" :options="allocOptions" /><p v-else class="text-center text-slate-500 text-xs py-12">No holdings yet.</p></div>
      </div>
    </div>

    <!-- Holdings P/L -->
    <div v-if="held.length" class="glass !p-0 overflow-x-auto">
      <table class="w-full text-sm min-w-[560px]">
        <thead class="text-[10px] uppercase tracking-wider text-slate-400 border-b border-white/10">
          <tr><th class="text-left px-3 py-2.5">Holding</th><th class="text-right px-2">Shares</th><th class="text-right px-2">Avg cost</th><th class="text-right px-2">Price</th><th class="text-right px-2">Value</th><th class="text-right px-3">P/L</th></tr>
        </thead>
        <tbody>
          <tr v-for="s in held" :key="s.id" class="border-b border-white/5 hover:bg-white/5">
            <td class="px-3 py-2 font-medium">{{ s.name }}</td>
            <td class="px-2 text-right font-mono">{{ num(s.shares_held) }}</td>
            <td class="px-2 text-right font-mono text-slate-400">{{ cur() }}{{ num(s.avg_cost, 2) }}</td>
            <td class="px-2 text-right font-mono">{{ cur() }}{{ num(s.share_price, 2) }}</td>
            <td class="px-2 text-right font-mono text-gold">{{ credits(Math.round(s.position_value * 100)) }}</td>
            <td class="px-3 text-right font-mono font-semibold" :class="pnl(s) >= 0 ? 'text-gain' : 'text-loss'">{{ credits(pnl(s), { sign: true }) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Filters -->
    <div class="glass p-3 flex flex-wrap items-center gap-2">
      <div class="flex gap-1 p-1 rounded-xl bg-ink-900/70 text-xs">
        <button v-for="v in (['all','gainers','losers','held'] as const)" :key="v"
          class="px-3 py-1.5 rounded-lg font-semibold capitalize transition" :class="view === v ? 'bg-brand text-ink-950' : 'text-slate-400'" @click="view = v">{{ v }}</button>
      </div>
      <select v-model="sectorFilter" class="input !py-1.5 text-xs max-w-[160px]">
        <option value="">All sectors</option>
        <option v-for="s in sectors" :key="s" :value="s">{{ s }}</option>
      </select>
      <div class="ml-auto flex gap-1 text-xs text-slate-400 items-center">
        <span>Sort:</span>
        <button v-for="k in (['change_pct','dividend_yield','share_price','name'] as const)" :key="k"
          class="px-2 py-1 rounded-lg hover:text-brand-soft" :class="sortKey === k ? 'text-brand-soft font-semibold' : ''" @click="setSort(k)">
          {{ ({ change_pct: 'Change', dividend_yield: 'Dividend', share_price: 'Price', name: 'Name' } as any)[k] }}{{ sortKey === k ? (sortDir === 'asc' ? ' ▲' : ' ▼') : '' }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="grid place-items-center h-48 text-slate-500">Loading the exchange…</div>

    <div v-else class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
      <div v-for="s in shown" :key="s.id" class="glass p-4" :class="s.shares_held ? 'ring-1 ring-brand/20' : ''">
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="font-semibold text-sm truncate">{{ s.name }}</p>
            <p class="text-[11px] text-slate-400">{{ s.sector }} · {{ (s.dividend_yield * 100).toFixed(1) }}% div</p>
          </div>
          <div class="text-right shrink-0">
            <p class="font-mono font-semibold">{{ cur() }}{{ num(s.share_price, 2) }}</p>
            <p class="text-[11px] font-mono" :class="s.change_pct >= 0 ? 'text-gain' : 'text-loss'">{{ s.change_pct >= 0 ? '▲' : '▼' }} {{ Math.abs(s.change_pct) }}%</p>
          </div>
        </div>

        <!-- Sparkline -->
        <svg v-if="spark(s.history)" viewBox="0 0 90 26" class="w-full h-7 mt-2" preserveAspectRatio="none">
          <polyline :points="spark(s.history)" fill="none" :stroke="s.change_pct >= 0 ? '#34d399' : '#fb7185'" stroke-width="1.5" vector-effect="non-scaling-stroke" />
        </svg>
        <div v-else class="h-7 mt-2 grid place-items-center text-[10px] text-slate-600">building price history…</div>

        <div v-if="s.shares_held" class="mt-2 text-[11px] bg-ink-900/50 rounded-lg px-3 py-1.5 flex justify-between">
          <span class="text-slate-400">{{ num(s.shares_held) }} @ {{ cur() }}{{ num(s.avg_cost, 2) }}</span>
          <span class="font-mono" :class="pnl(s) >= 0 ? 'text-gain' : 'text-loss'">{{ credits(pnl(s), { sign: true }) }}</span>
        </div>

        <div class="mt-3 space-y-1.5">
          <div class="flex items-center gap-1.5">
            <input v-model.number="qty[s.id]" type="number" min="1" class="input !py-1.5 text-xs flex-1" />
            <button class="btn-ghost !py-1.5 !px-2 text-[10px]" @click="setMax(s)">Max</button>
            <button v-for="a in [10, 50]" :key="a" class="btn-ghost !py-1.5 !px-2 text-[10px]" @click="qty[s.id] = a">{{ a }}</button>
          </div>
          <p class="text-[10px] text-slate-500">≈ {{ credits(estCost(s)) }} for {{ num(qty[s.id] || 0) }} shares</p>
          <div class="grid grid-cols-2 gap-1.5">
            <button class="btn-ghost !py-1.5 text-xs" :disabled="trading === s.id || estCost(s) > cash" @click="trade(s, 'buy')">Buy</button>
            <button class="btn-primary !py-1.5 text-xs" :disabled="trading === s.id || !s.shares_held" @click="trade(s, 'sell')">Sell</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
