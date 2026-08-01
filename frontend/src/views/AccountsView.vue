<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { Line, Doughnut } from 'vue-chartjs'
import {
  Chart as ChartJS, ArcElement, Tooltip, Legend, Filler,
  LineElement, PointElement, CategoryScale, LinearScale,
} from 'chart.js'
import { api, apiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'

ChartJS.register(ArcElement, Tooltip, Legend, Filler, LineElement, PointElement, CategoryScale, LinearScale)

const toast = useToastStore()

const acc = ref<any>(null)
const an = ref<any>(null)
const period = ref<'7d' | '30d' | 'all'>('all')
const ledger = ref<any[]>([])
const ledgerMeta = ref<any>(null)
const category = ref('')
const page = ref(1)

async function loadAnalytics() {
  try {
    const { data } = await api.get('/accounts/analytics', { params: { period: period.value } })
    an.value = data
  } catch { /* analytics is best-effort */ }
}

// --- KPI tiles --------------------------------------------------------------
const kpiTiles = computed(() => {
  const k = an.value?.kpis
  if (!k) return []
  const money = (c: number | null) => c == null ? '—' : credits(c, { compact: true })
  return [
    { label: 'Profit margin', value: `${k.profit_margin}%`, cls: k.profit_margin >= 0 ? 'text-gain' : 'text-loss' },
    { label: 'Cost / km', value: money(k.cost_per_km), cls: 'text-loss' },
    { label: 'Avg profit / delivery', value: money(k.avg_profit_per_delivery), cls: k.avg_profit_per_delivery >= 0 ? 'text-gain' : 'text-loss' },
    { label: 'Revenue / truck', value: money(k.revenue_per_truck), cls: 'text-brand-soft' },
    { label: 'On-time', value: `${k.on_time_pct}%`, cls: 'text-gain' },
    { label: 'Fleet in use', value: `${k.fleet_utilization}%`, cls: 'text-brand-soft' },
    { label: 'Cash runway', value: k.cash_runway_days == null ? '∞' : `${k.cash_runway_days}d`, cls: 'text-gold' },
    { label: 'Debt / equity', value: k.debt_to_equity == null ? '—' : k.debt_to_equity, cls: (k.debt_to_equity ?? 0) > 1 ? 'text-loss' : 'text-slate-200' },
    { label: 'Deliveries', value: num(k.deliveries), cls: 'text-slate-200' },
    { label: 'Distance', value: `${num(k.km_driven)} km`, cls: 'text-slate-200' },
  ]
})

const chartFont = { color: '#94a3b8', font: { size: 10 } }
// --- Trend line chart -------------------------------------------------------
const trendData = computed(() => {
  const t = an.value?.trend ?? []
  const labels = t.map((r: any) => new Date(r.date).toLocaleDateString(undefined, { day: 'numeric', month: 'short' }))
  return {
    labels,
    datasets: [
      { label: 'Revenue', data: t.map((r: any) => r.revenue / 100), borderColor: '#34d399', backgroundColor: 'rgba(52,211,153,.12)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 },
      { label: 'Expense', data: t.map((r: any) => r.expense / 100), borderColor: '#fb7185', backgroundColor: 'rgba(251,113,133,.10)', fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2 },
      { label: 'Net', data: t.map((r: any) => r.net / 100), borderColor: '#38bdf8', backgroundColor: 'transparent', tension: 0.3, pointRadius: 0, borderWidth: 2, borderDash: [4, 3] },
    ],
  }
})
const trendOptions: any = {
  responsive: true, maintainAspectRatio: false, interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { labels: { ...chartFont, boxWidth: 10 }, position: 'top' },
    tooltip: { callbacks: { label: (c: any) => `${c.dataset.label}: ${credits(Number(c.raw) * 100)}` } },
  },
  scales: {
    x: { ticks: chartFont, grid: { color: 'rgba(255,255,255,.04)' } },
    y: { ticks: { ...chartFont, callback: (v: any) => '₹' + num(v) }, grid: { color: 'rgba(255,255,255,.05)' } },
  },
}

// --- Doughnut helpers -------------------------------------------------------
const PALETTE = ['#38bdf8', '#a78bfa', '#f472b6', '#fbbf24', '#34d399', '#fb7185', '#22d3ee', '#f59e0b', '#c084fc', '#4ade80']
function doughnut(items: { label: string; value: number }[]) {
  return {
    labels: items.map((i) => i.label),
    datasets: [{ data: items.map((i) => i.value / 100), backgroundColor: items.map((_, idx) => PALETTE[idx % PALETTE.length]), borderColor: '#0b1120', borderWidth: 2, hoverOffset: 6 }],
  }
}
const doughnutOptions: any = {
  responsive: true, maintainAspectRatio: false, cutout: '58%',
  plugins: {
    legend: { position: 'right', labels: { ...chartFont, boxWidth: 10, padding: 8 } },
    tooltip: { callbacks: { label: (c: any) => `${c.label}: ${credits(Number(c.raw) * 100)}` } },
  },
}
const expenseItems = computed(() =>
  Object.entries(an.value?.expense_breakdown ?? {}).map(([label, value]) => ({ label, value: value as number })))
const commodityItems = computed(() =>
  (an.value?.by_commodity ?? []).map((r: any) => ({ label: r.label, value: r.revenue })))

const CATS = ['revenue', 'fuel', 'wages', 'purchase', 'upkeep', 'penalty', 'loan', 'interest', 'research']
const CAT_LABEL: Record<string, string> = {
  revenue: 'Revenue', fuel: 'Fuel', wages: 'Wages', purchase: 'Purchases',
  upkeep: 'Upkeep', penalty: 'Penalties', loan: 'Loans', interest: 'Interest', research: 'R&D',
}

async function loadSummary() {
  try {
    const { data } = await api.get('/accounts', { params: { period: period.value } })
    acc.value = data
  } catch (e) { toast.error(apiError(e)) }
}

async function loadLedger() {
  try {
    const { data } = await api.get('/accounts/ledger', { params: { category: category.value || undefined, page: page.value } })
    ledger.value = data.data
    ledgerMeta.value = data.meta
  } catch (e) { toast.error(apiError(e)) }
}

watch(period, () => { loadSummary(); loadAnalytics() })
watch([category, page], loadLedger)

onMounted(() => { loadSummary(); loadAnalytics(); loadLedger() })
</script>

<template>
  <div v-if="acc" class="space-y-6">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Accounts</h1>
        <p class="text-slate-400 text-sm">Fully automated books — every figure is journalled from real cash movements.</p>
      </div>
      <div class="flex gap-1 p-1 rounded-xl bg-ink-900/70 text-xs">
        <button v-for="p in (['7d','30d','all'] as const)" :key="p"
          class="px-3 py-1.5 rounded-lg font-semibold transition"
          :class="period === p ? 'bg-brand text-ink-950' : 'text-slate-400'" @click="period = p">
          {{ p === 'all' ? 'All time' : 'Last ' + p }}
        </button>
      </div>
    </div>

    <div class="grid lg:grid-cols-2 gap-6">
      <!-- Profit & Loss -->
      <section class="glass p-5">
        <h2 class="font-semibold mb-3">Profit &amp; Loss <span class="text-slate-500 text-xs">({{ period === 'all' ? 'all time' : 'last ' + period }})</span></h2>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-white/5">
            <tr><td class="py-2 text-slate-300">Revenue</td><td class="py-2 text-right font-mono text-gain">{{ credits(acc.pnl.revenue) }}</td></tr>
            <tr class="text-slate-400 text-xs"><td class="pt-3 pb-1" colspan="2">Operating expenses</td></tr>
            <tr v-for="k in ['fuel','wages','upkeep','penalty']" :key="k">
              <td class="py-1.5 pl-3 text-slate-400">{{ CAT_LABEL[k] }}</td>
              <td class="py-1.5 text-right font-mono text-loss">{{ credits(acc.pnl.operating_expenses[k]) }}</td>
            </tr>
            <tr class="font-semibold"><td class="py-2">Operating profit</td>
              <td class="py-2 text-right font-mono" :class="acc.pnl.operating_profit >= 0 ? 'text-gain' : 'text-loss'">{{ credits(acc.pnl.operating_profit, { sign: true }) }}</td></tr>
            <tr class="text-slate-400 text-xs"><td class="pt-3 pb-1" colspan="2">Capital &amp; other</td></tr>
            <tr><td class="py-1.5 pl-3 text-slate-400">Purchases</td><td class="py-1.5 text-right font-mono text-loss">{{ credits(acc.pnl.capital_expenses.purchase) }}</td></tr>
            <tr><td class="py-1.5 pl-3 text-slate-400">R&amp;D</td><td class="py-1.5 text-right font-mono text-loss">{{ credits(acc.pnl.capital_expenses.research) }}</td></tr>
            <tr><td class="py-1.5 pl-3 text-slate-400">Loan interest</td><td class="py-1.5 text-right font-mono text-loss">{{ credits(acc.pnl.interest) }}</td></tr>
            <tr class="font-bold text-base border-t-2 border-white/10">
              <td class="py-2.5">Net profit</td>
              <td class="py-2.5 text-right font-mono" :class="acc.pnl.net_profit >= 0 ? 'text-gain' : 'text-loss'">{{ credits(acc.pnl.net_profit, { sign: true }) }}</td>
            </tr>
          </tbody>
        </table>
      </section>

      <!-- Balance Sheet -->
      <section class="glass p-5">
        <h2 class="font-semibold mb-3">Balance Sheet <span class="text-slate-500 text-xs">(now)</span></h2>
        <table class="w-full text-sm">
          <tbody class="divide-y divide-white/5">
            <tr class="text-slate-400 text-xs"><td class="pb-1" colspan="2">Assets</td></tr>
            <tr><td class="py-1.5 pl-3 text-slate-400">Cash</td><td class="py-1.5 text-right font-mono text-gold">{{ credits(acc.balance_sheet.assets.cash) }}</td></tr>
            <tr><td class="py-1.5 pl-3 text-slate-400">Fleet value</td><td class="py-1.5 text-right font-mono">{{ credits(acc.balance_sheet.assets.fleet_value) }}</td></tr>
            <tr><td class="py-1.5 pl-3 text-slate-400">Inventory</td><td class="py-1.5 text-right font-mono">{{ credits(acc.balance_sheet.assets.inventory_value) }}</td></tr>
            <tr class="font-semibold"><td class="py-2">Total assets</td><td class="py-2 text-right font-mono">{{ credits(acc.balance_sheet.assets.total) }}</td></tr>
            <tr class="text-slate-400 text-xs"><td class="pt-3 pb-1" colspan="2">Liabilities</td></tr>
            <tr><td class="py-1.5 pl-3 text-slate-400">Loans outstanding</td><td class="py-1.5 text-right font-mono text-loss">{{ credits(acc.balance_sheet.liabilities.loans) }}</td></tr>
            <tr class="font-bold text-base border-t-2 border-white/10">
              <td class="py-2.5">Net worth (equity)</td>
              <td class="py-2.5 text-right font-mono text-brand-soft">{{ credits(acc.balance_sheet.equity) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="grid grid-cols-3 gap-2 mt-4 text-center text-[11px]">
          <div class="rounded-lg bg-ink-900/60 px-2 py-2"><p class="stat-label">Lifetime rev</p><p class="font-mono text-gain">{{ credits(acc.lifetime.revenue, { compact: true }) }}</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-2"><p class="stat-label">Lifetime exp</p><p class="font-mono text-loss">{{ credits(acc.lifetime.expenses, { compact: true }) }}</p></div>
          <div class="rounded-lg bg-ink-900/60 px-2 py-2"><p class="stat-label">Lifetime net</p><p class="font-mono" :class="acc.lifetime.net >= 0 ? 'text-gain' : 'text-loss'">{{ credits(acc.lifetime.net, { compact: true }) }}</p></div>
        </div>
      </section>
    </div>

    <!-- Analytics dashboard -->
    <section v-if="an" class="space-y-4">
      <h2 class="font-semibold">Business Analytics <span class="text-slate-500 text-xs">({{ period === 'all' ? 'last 90 days' : 'last ' + period }})</span></h2>

      <!-- KPI tiles -->
      <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-2">
        <div v-for="t in kpiTiles" :key="t.label" class="glass !p-3">
          <p class="stat-label">{{ t.label }}</p>
          <p class="font-mono font-semibold text-lg leading-tight mt-0.5" :class="t.cls">{{ t.value }}</p>
        </div>
      </div>

      <!-- Trend + expense breakdown -->
      <div class="grid lg:grid-cols-3 gap-4">
        <div class="glass p-4 lg:col-span-2">
          <h3 class="font-semibold text-sm mb-2">Revenue vs Expense vs Net</h3>
          <div class="h-56"><Line v-if="an.trend.length" :data="trendData" :options="trendOptions" /><p v-else class="text-center text-slate-500 text-sm py-16">No activity in this period.</p></div>
        </div>
        <div class="glass p-4">
          <h3 class="font-semibold text-sm mb-2">Where the money goes</h3>
          <div class="h-56"><Doughnut v-if="expenseItems.length" :data="doughnut(expenseItems)" :options="doughnutOptions" /><p v-else class="text-center text-slate-500 text-sm py-16">No expenses yet.</p></div>
        </div>
      </div>

      <!-- Revenue by cargo + top routes + top earners -->
      <div class="grid lg:grid-cols-3 gap-4">
        <div class="glass p-4">
          <h3 class="font-semibold text-sm mb-2">Revenue by cargo</h3>
          <div class="h-52"><Doughnut v-if="commodityItems.length" :data="doughnut(commodityItems)" :options="doughnutOptions" /><p v-else class="text-center text-slate-500 text-sm py-14">No deliveries yet.</p></div>
        </div>
        <div class="glass p-4">
          <h3 class="font-semibold text-sm mb-2">Top routes by revenue</h3>
          <div v-if="an.top_routes.length" class="divide-y divide-white/5">
            <div v-for="r in an.top_routes" :key="r.label" class="flex items-center justify-between py-1.5 text-[12px] gap-2">
              <span class="truncate">{{ r.label }} <span class="text-slate-500">· {{ r.n }}</span></span>
              <span class="font-mono text-gain shrink-0">{{ credits(r.revenue, { compact: true }) }}</span>
            </div>
          </div>
          <p v-else class="text-center text-slate-500 text-sm py-14">No routes yet.</p>
        </div>
        <div class="glass p-4">
          <h3 class="font-semibold text-sm mb-2">Top earners</h3>
          <p class="stat-label mb-1">Trucks</p>
          <div v-if="an.per_vehicle.length" class="divide-y divide-white/5 mb-3">
            <div v-for="r in an.per_vehicle.slice(0, 4)" :key="r.label" class="flex items-center justify-between py-1 text-[12px] gap-2">
              <span class="truncate">{{ r.label }} <span class="text-slate-500">· {{ r.n }}</span></span>
              <span class="font-mono text-gain shrink-0">{{ credits(r.revenue, { compact: true }) }}</span>
            </div>
          </div>
          <p v-else class="text-slate-500 text-xs mb-3">No runs yet.</p>
          <p class="stat-label mb-1">Drivers</p>
          <div v-if="an.per_driver.length" class="divide-y divide-white/5">
            <div v-for="r in an.per_driver.slice(0, 4)" :key="r.label" class="flex items-center justify-between py-1 text-[12px] gap-2">
              <span class="truncate">{{ r.label }} <span class="text-slate-500">· {{ r.n }}</span></span>
              <span class="font-mono text-gain shrink-0">{{ credits(r.revenue, { compact: true }) }}</span>
            </div>
          </div>
          <p v-else class="text-slate-500 text-xs">No runs yet.</p>
        </div>
      </div>
    </section>

    <!-- Transaction journal -->
    <section class="space-y-3">
      <div class="flex items-center justify-between flex-wrap gap-3">
        <h2 class="font-semibold">Transaction Journal</h2>
        <select v-model="category" class="input max-w-[200px] !py-1.5 text-xs" @change="page = 1">
          <option value="">All categories</option>
          <option v-for="c in CATS" :key="c" :value="c">{{ CAT_LABEL[c] }}</option>
        </select>
      </div>

      <div class="glass overflow-hidden">
        <table class="w-full text-sm">
          <thead>
            <tr class="text-left text-slate-400 border-b border-white/10 text-xs">
              <th class="px-4 py-2 font-semibold">When</th>
              <th class="px-4 py-2 font-semibold">Description</th>
              <th class="px-4 py-2 font-semibold">Category</th>
              <th class="px-4 py-2 font-semibold text-right">Amount</th>
              <th class="px-4 py-2 font-semibold text-right hidden sm:table-cell">Balance</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="e in ledger" :key="e.id" class="border-b border-white/5">
              <td class="px-4 py-2 text-[11px] text-slate-400 whitespace-nowrap">{{ new Date(e.occurred_at).toLocaleString() }}</td>
              <td class="px-4 py-2">{{ e.description }}</td>
              <td class="px-4 py-2"><span class="chip bg-white/5 text-slate-400">{{ CAT_LABEL[e.category] || e.category }}</span></td>
              <td class="px-4 py-2 text-right font-mono" :class="e.amount >= 0 ? 'text-gain' : 'text-loss'">{{ credits(e.amount, { sign: true }) }}</td>
              <td class="px-4 py-2 text-right font-mono text-slate-400 hidden sm:table-cell">{{ credits(e.balance_after) }}</td>
            </tr>
          </tbody>
        </table>
        <div v-if="!ledger.length" class="p-6 text-center text-slate-500 text-sm">No transactions yet.</div>
      </div>

      <div v-if="ledgerMeta && ledgerMeta.last_page > 1" class="flex items-center justify-center gap-3">
        <button class="btn-ghost !py-1.5 text-xs" :disabled="page <= 1" @click="page--">← Prev</button>
        <span class="text-xs text-slate-400">Page {{ ledgerMeta.current_page }} / {{ ledgerMeta.last_page }}</span>
        <button class="btn-ghost !py-1.5 text-xs" :disabled="page >= ledgerMeta.last_page" @click="page++">Next →</button>
      </div>
    </section>
  </div>
  <div v-else class="grid place-items-center h-64 text-slate-500">Loading accounts…</div>
</template>
