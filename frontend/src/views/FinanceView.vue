<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { credits } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

const state = ref<any>(null)
const borrowAmount = ref(50000)
const repay = ref<Record<number, number>>({})
const busy = ref(false)

async function load() {
  const { data } = await api.get('/finance')
  state.value = data
}

async function borrow() {
  busy.value = true
  try {
    await api.post('/finance/borrow', { amount: Math.round(borrowAmount.value * 100) })
    toast.success('Loan disbursed.')
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) } finally { busy.value = false }
}

async function doRepay(loan: any) {
  const amt = Math.round((repay.value[loan.id] || 0) * 100)
  if (amt < 1) return toast.error('Enter an amount.')
  try {
    await api.post(`/finance/loans/${loan.id}/repay`, { amount: amt })
    toast.success('Repayment applied.')
    await load(); game.refreshDashboard().catch(() => {})
  } catch (e) { toast.error(apiError(e)) }
}

onMounted(() => load().catch((e) => toast.error(apiError(e))))
</script>

<template>
  <div v-if="state" class="space-y-5">
    <div>
      <h1 class="text-2xl font-bold">Finance</h1>
      <p class="text-slate-400 text-sm">Borrow to expand faster — but interest accrues every tick until you repay.</p>
    </div>

    <div class="grid sm:grid-cols-3 gap-3">
      <div class="glass p-4"><p class="stat-label">Cash</p><p class="text-xl font-bold font-mono text-gold">{{ credits(state.cash) }}</p></div>
      <div class="glass p-4"><p class="stat-label">Total Debt</p><p class="text-xl font-bold font-mono text-loss">{{ credits(state.debt) }}</p></div>
      <div class="glass p-4"><p class="stat-label">Borrow Limit</p><p class="text-xl font-bold font-mono">{{ credits(state.borrow_limit, { compact: true }) }}</p></div>
    </div>

    <!-- Borrow -->
    <div class="glass p-4">
      <h2 class="font-semibold mb-3">Take a loan</h2>
      <div class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[200px]">
          <label class="stat-label">Amount (₡)</label>
          <input v-model.number="borrowAmount" type="number" min="500" step="500" class="input mt-1" />
        </div>
        <button class="btn-primary" :disabled="busy" @click="borrow">Borrow</button>
      </div>
      <p class="text-[11px] text-slate-500 mt-2">Interest ≈ {{ (state.interest_per_tick * 100).toFixed(2) }}% per tick. Min loan {{ credits(state.min_loan) }}.</p>
    </div>

    <!-- Active loans -->
    <div class="glass p-4">
      <h2 class="font-semibold mb-3">Active loans</h2>
      <div v-if="state.loans.length" class="space-y-2">
        <div v-for="l in state.loans" :key="l.id" class="flex flex-wrap items-center gap-3 bg-ink-900/50 rounded-lg px-3 py-2">
          <div class="flex-1 min-w-[140px]">
            <p class="text-sm">Balance <span class="font-mono text-loss">{{ credits(l.balance) }}</span></p>
            <p class="text-[11px] text-slate-500">Principal {{ credits(l.principal) }}</p>
          </div>
          <input v-model.number="repay[l.id]" type="number" min="1" placeholder="₡ to repay" class="input !py-1.5 text-xs w-36" />
          <button class="btn-ghost !py-1.5 text-xs" @click="doRepay(l)">Repay</button>
        </div>
      </div>
      <p v-else class="text-sm text-slate-500">No active loans. You're debt-free. 🎉</p>
    </div>
  </div>
  <div v-else class="grid place-items-center h-64 text-slate-500">Loading…</div>
</template>
