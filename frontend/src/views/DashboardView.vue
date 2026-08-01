<script setup lang="ts">
import { computed } from 'vue'
import { storeToRefs } from 'pinia'
import { useGameStore } from '../stores/game'
import { useClock } from '../composables/useClock'
import { credits, num, cur } from '../utils/format'
import StatTile from '../components/StatTile.vue'
import ShipmentRow from '../components/ShipmentRow.vue'
import PnlDonut from '../components/PnlDonut.vue'

const game = useGameStore()
const { dashboard } = storeToRefs(game)
const now = useClock(1000)

const c = computed(() => dashboard.value?.company)
const netProfit = computed(() => {
  const p = dashboard.value?.pnl
  if (!p) return 0
  return Object.values(p).reduce((a, b) => a + b, 0)
})

const CATEGORY_LABEL: Record<string, string> = {
  revenue: 'Revenue', fuel: 'Fuel', wages: 'Wages', purchase: 'Purchases',
  upkeep: 'Upkeep', penalty: 'Penalties', loan: 'Loans', interest: 'Interest', research: 'R&D',
}
</script>

<template>
  <div v-if="dashboard" class="space-y-6">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Command Center</h1>
        <p class="text-slate-400 text-sm">Live operations for {{ c?.name }}.</p>
      </div>
      <div class="flex gap-2">
        <RouterLink to="/contracts" class="btn-primary">Find Contracts →</RouterLink>
      </div>
    </div>

    <!-- KPI row -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 sm:gap-4">
      <StatTile label="Company Value" :value="credits(c?.value ?? 0, { compact: true })" accent="brand" icon="◈"
        :sub="`Level ${c?.level} · ${num(c?.research_points ?? 0)} RP`" />
      <StatTile label="Cash on Hand" :value="credits(c?.cash ?? 0)" accent="gold" :icon="cur()"
        :sub="`Debt ${credits(c?.debt ?? 0)}`" />
      <StatTile label="Net P&L" :value="credits(netProfit, { sign: true, compact: true })"
        :accent="netProfit >= 0 ? 'gain' : 'loss'" icon="↭"
        :sub="`${num(c?.shipments_completed ?? 0)} delivered · ${num(c?.shipments_failed ?? 0)} failed`" />
      <StatTile label="Reputation" :value="num(c?.reputation ?? 0) + ' / 1000'" accent="brand" icon="★"
        :sub="`Fleet of ${dashboard.fleet_summary.total}`" />
    </div>

    <div class="grid lg:grid-cols-3 gap-6">
      <!-- Active shipments -->
      <section class="lg:col-span-2 space-y-3">
        <div class="flex items-center justify-between">
          <h2 class="font-semibold">Live Deliveries
            <span class="chip bg-brand/15 text-brand-soft ml-1">{{ dashboard.active_shipments.length }}</span>
          </h2>
          <RouterLink to="/operations" class="text-xs text-brand hover:underline">Operations →</RouterLink>
        </div>

        <div v-if="dashboard.active_shipments.length" class="grid sm:grid-cols-2 gap-3">
          <ShipmentRow v-for="s in dashboard.active_shipments" :key="s.id" :shipment="s" :now="now" />
        </div>
        <div v-else class="glass p-8 text-center text-slate-400 text-sm">
          No active deliveries. Accept a contract and dispatch a truck to start earning.
        </div>

        <!-- Fleet strip -->
        <div class="grid grid-cols-3 gap-3">
          <div class="glass p-4 text-center">
            <p class="text-2xl font-bold text-gain">{{ dashboard.fleet_summary.idle }}</p>
            <p class="stat-label mt-1">Idle</p>
          </div>
          <div class="glass p-4 text-center">
            <p class="text-2xl font-bold text-brand">{{ dashboard.fleet_summary.en_route }}</p>
            <p class="stat-label mt-1">En Route</p>
          </div>
          <div class="glass p-4 text-center">
            <p class="text-2xl font-bold text-loss">{{ dashboard.fleet_summary.maintenance }}</p>
            <p class="stat-label mt-1">Maintenance</p>
          </div>
        </div>
      </section>

      <!-- Right column: P&L + news + ledger -->
      <section class="space-y-6">
        <div class="glass p-5">
          <h2 class="font-semibold mb-3">Profit &amp; Loss</h2>
          <PnlDonut :pnl="dashboard.pnl" />
          <div class="mt-4 space-y-1.5">
            <div v-for="(val, key) in dashboard.pnl" :key="key" class="flex justify-between text-sm">
              <span class="text-slate-400">{{ CATEGORY_LABEL[key] || key }}</span>
              <span class="font-mono" :class="val >= 0 ? 'text-gain' : 'text-loss'">{{ credits(val, { sign: true }) }}</span>
            </div>
          </div>
        </div>

        <div class="glass p-5">
          <h2 class="font-semibold mb-3">World News</h2>
          <div v-if="dashboard.world_news.length" class="space-y-3">
            <div v-for="n in dashboard.world_news" :key="n.id" class="flex gap-3">
              <span class="h-2 w-2 rounded-full mt-1.5 shrink-0 animate-pulse-dot"
                :class="{ 'bg-loss': n.severity === 'critical', 'bg-gold': n.severity === 'major', 'bg-brand': n.severity === 'minor' }" />
              <div>
                <p class="text-sm font-medium leading-tight">{{ n.title }}</p>
                <p class="text-xs text-slate-400 leading-snug">{{ n.description }}</p>
              </div>
            </div>
          </div>
          <p v-else class="text-sm text-slate-500">Calm across the belt. No active events.</p>
        </div>
      </section>
    </div>
  </div>

  <div v-else class="grid place-items-center h-96 text-slate-500">Loading command center…</div>
</template>
