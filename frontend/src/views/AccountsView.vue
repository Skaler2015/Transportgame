<script setup lang="ts">
import { onMounted, ref, watch } from 'vue'
import { api, apiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import { credits } from '../utils/format'

const toast = useToastStore()

const acc = ref<any>(null)
const period = ref<'7d' | '30d' | 'all'>('all')
const ledger = ref<any[]>([])
const ledgerMeta = ref<any>(null)
const category = ref('')
const page = ref(1)

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

watch(period, loadSummary)
watch([category, page], loadLedger)

onMounted(() => { loadSummary(); loadLedger() })
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
