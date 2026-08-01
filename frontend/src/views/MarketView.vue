<script setup lang="ts">
import { onMounted, ref, computed, watch } from 'vue'
import { Line } from 'vue-chartjs'
import {
  Chart as ChartJS, LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler,
} from 'chart.js'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { num, cur } from '../utils/format'

ChartJS.register(LineElement, PointElement, LinearScale, CategoryScale, Tooltip, Filler)

const game = useGameStore()
const auth = useAuthStore()
const toast = useToastStore()

interface Market { city_id: number; city: string; region: string; price: number; base_price: number; demand_index: number; delta_pct: number }
const markets = ref<Market[]>([])
const commodityId = ref<number | null>(null)
const basePrice = ref(0)
const season = ref<{ name: string; demand: Record<string, number> } | null>(null)
const historyCity = ref<number | null>(null)
const series = ref<{ price: number; at: string }[]>([])

const maxPrice = computed(() => Math.max(1, ...markets.value.map((m) => m.price)))
const bestBuy = computed(() => markets.value.reduce((a, b) => (b.price < a.price ? b : a), markets.value[0]))
const bestSell = computed(() => markets.value.reduce((a, b) => (b.price > a.price ? b : a), markets.value[0]))

async function loadMarket() {
  if (!commodityId.value) return
  try {
    const { data } = await api.get('/market', { params: { commodity_id: commodityId.value, country: auth.company?.country } })
    markets.value = data.markets
    basePrice.value = data.commodity.base_price
    season.value = data.season ?? null
    historyCity.value = bestSell.value?.city_id ?? markets.value[0]?.city_id ?? null
    await loadHistory()
  } catch (e) {
    toast.error(apiError(e))
  }
}

async function loadHistory() {
  if (!commodityId.value || !historyCity.value) return
  const { data } = await api.get('/market/history', {
    params: { commodity_id: commodityId.value, city_id: historyCity.value },
  })
  series.value = data.data
}

watch(historyCity, loadHistory)

const chartData = computed(() => ({
  labels: series.value.map((_, i) => i),
  datasets: [
    {
      data: series.value.map((s) => s.price),
      borderColor: '#38bdf8',
      backgroundColor: 'rgba(56,189,248,0.12)',
      fill: true,
      tension: 0.35,
      pointRadius: 0,
      borderWidth: 2,
    },
  ],
}))
const chartOptions: any = {
  responsive: true, maintainAspectRatio: false,
  plugins: { tooltip: { callbacks: { label: (c: { raw: unknown }) => cur() + c.raw } } },
  scales: {
    x: { display: false },
    y: { grid: { color: 'rgba(255,255,255,0.05)' }, ticks: { color: '#64748b', font: { size: 10 } } },
  },
}

onMounted(async () => {
  await game.loadReference().catch(() => {})
  commodityId.value = game.commodities[0]?.id ?? null
  await loadMarket()
})
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Markets</h1>
        <p class="text-slate-400 text-sm">Where a commodity is cheap vs. dear. Buy low, haul, sell high.</p>
      </div>
      <select v-model="commodityId" class="input max-w-xs" @change="loadMarket">
        <option v-for="k in game.commodities" :key="k.id" :value="k.id">{{ k.name }}</option>
      </select>
    </div>

    <!-- Seasonal demand banner -->
    <div v-if="season" class="glass p-3 flex items-center gap-3 border-l-2 border-gold/50">
      <span class="text-xl">🗓️</span>
      <div class="text-sm">
        <span class="font-semibold text-gold">{{ season.name }}</span>
        <span class="text-slate-400"> — demand shift: </span>
        <span v-for="(mult, cat) in season.demand" :key="cat" class="mr-2 text-[12px]">
          <span class="capitalize">{{ cat }}</span>
          <span :class="mult >= 1 ? 'text-gain' : 'text-loss'"> {{ mult >= 1 ? '+' : '' }}{{ Math.round((mult - 1) * 100) }}%</span>
        </span>
      </div>
    </div>

    <div class="grid lg:grid-cols-3 gap-4">
      <div class="glass p-4">
        <p class="stat-label">Best place to buy</p>
        <p class="text-lg font-semibold mt-1">{{ bestBuy?.city }}</p>
        <p class="font-mono text-gain">{{ cur() }}{{ num(bestBuy?.price ?? 0, 2) }}</p>
      </div>
      <div class="glass p-4">
        <p class="stat-label">Best place to sell</p>
        <p class="text-lg font-semibold mt-1">{{ bestSell?.city }}</p>
        <p class="font-mono text-gold">{{ cur() }}{{ num(bestSell?.price ?? 0, 2) }}</p>
      </div>
      <div class="glass p-4">
        <p class="stat-label">Arbitrage spread</p>
        <p class="text-lg font-semibold mt-1">{{ cur() }}{{ num((bestSell?.price ?? 0) - (bestBuy?.price ?? 0), 2) }} / unit</p>
        <p class="text-[11px] text-slate-400">Base price {{ cur() }}{{ num(basePrice, 2) }}</p>
      </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-4">
      <!-- Price by city -->
      <div class="glass p-5">
        <h2 class="font-semibold mb-3">Local prices by city</h2>
        <div class="space-y-2">
          <button v-for="m in markets" :key="m.city_id"
            class="w-full text-left group" @click="historyCity = m.city_id">
            <div class="flex justify-between text-[11px] mb-0.5">
              <span :class="historyCity === m.city_id ? 'text-brand font-semibold' : 'text-slate-300'">{{ m.city }}</span>
              <span class="font-mono" :class="m.delta_pct >= 0 ? 'text-gain' : 'text-loss'">
                {{ cur() }}{{ num(m.price, 2) }} ({{ m.delta_pct >= 0 ? '+' : '' }}{{ m.delta_pct }}%)
              </span>
            </div>
            <div class="h-2 rounded-full bg-ink-700 overflow-hidden">
              <div class="h-full bg-gradient-to-r from-brand-deep to-brand-glow group-hover:opacity-90"
                :style="{ width: (m.price / maxPrice) * 100 + '%' }" />
            </div>
          </button>
        </div>
      </div>

      <!-- History -->
      <div class="glass p-5">
        <h2 class="font-semibold mb-1">Price history</h2>
        <p class="text-[11px] text-slate-400 mb-3">{{ markets.find((m) => m.city_id === historyCity)?.city }} · last {{ series.length }} ticks</p>
        <div class="h-56">
          <Line v-if="series.length > 1" :data="chartData" :options="chartOptions" />
          <p v-else class="grid place-items-center h-full text-sm text-slate-500">
            Not enough history yet — advance the world a few times.
          </p>
        </div>
      </div>
    </div>
  </div>
</template>
