<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import { useRouter } from 'vue-router'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { useClock } from '../composables/useClock'
import { api, apiError } from '../api/client'
import { credits, num, cur } from '../utils/format'
import type { Vehicle, Driver } from '../types'
import StatTile from '../components/StatTile.vue'
import ShipmentRow from '../components/ShipmentRow.vue'
import PnlDonut from '../components/PnlDonut.vue'

const game = useGameStore()
const toast = useToastStore()
const router = useRouter()
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

// --- Extra command-center data ---------------------------------------------
const fleet = ref<Vehicle[]>([])
const drivers = ref<Driver[]>([])
const missions = ref<any[]>([])
const advisor = ref<any[]>([])
const leaders = ref<any[]>([])
const kpis = ref<any>(null)
const busy = ref<string | null>(null)

async function loadExtras() {
  const [f, d, m, a, l, an] = await Promise.allSettled([
    api.get('/fleet'), api.get('/drivers'), api.get('/missions'),
    api.get('/advisor'), api.get('/world/leaderboard'), api.get('/accounts/analytics', { params: { period: '7d' } }),
  ])
  if (f.status === 'fulfilled') fleet.value = f.value.data.data
  if (d.status === 'fulfilled') drivers.value = d.value.data.data
  if (m.status === 'fulfilled') missions.value = m.value.data.data
  if (a.status === 'fulfilled') advisor.value = a.value.data.data
  if (l.status === 'fulfilled') leaders.value = l.value.data.data
  if (an.status === 'fulfilled') kpis.value = an.value.data.kpis
}
onMounted(loadExtras)

// Level progress.
const xpPct = computed(() => {
  const co: any = c.value
  if (!co || !co.xp_to_next) return 0
  return Math.min(100, Math.round((co.xp / co.xp_to_next) * 100))
})

// Fleet needs.
function needs(v: Vehicle) {
  return {
    repair: (v.condition ?? 100) < 70 || (v.tire_wear ?? 0) > 40,
    lowFuel: (v.fuel_pct ?? 100) < 25 && (v.fuel_capacity ?? 0) > 0,
    service: (v.oil_level ?? 100) < 40 || (v.battery ?? 100) < 40 || !v.is_insured || !v.is_registered,
  }
}
const repairCount = computed(() => fleet.value.filter((v) => needs(v).repair || needs(v).service).length)
const lowFuelCount = computed(() => fleet.value.filter((v) => needs(v).lowFuel).length)
const expiredDrivers = computed(() => drivers.value.filter((d) => !d.is_licensed).length)
const activeMissions = computed(() => missions.value.filter((m) => m.status !== 'claimed').slice(0, 4))
const topTip = computed(() => advisor.value.find((i) => i.action) || advisor.value[0] || null)
const myRank = computed(() => leaders.value.find((r) => r.name === c.value?.name)?.rank ?? null)

// The "needs attention" list, each with a one-click action.
const actions = computed(() => {
  const items: { icon: string; text: string; run: () => void; cls?: string }[] = []
  const idle = dashboard.value?.fleet_summary?.idle ?? 0
  if (idle > 0) items.push({ icon: '🚚', text: `${idle} idle truck(s) with no load`, run: () => router.push('/contracts') })
  if (repairCount.value > 0) items.push({ icon: '🔧', text: `${repairCount.value} truck(s) need servicing`, run: () => serviceAll(), cls: 'text-gold' })
  if (lowFuelCount.value > 0) items.push({ icon: '⛽', text: `${lowFuelCount.value} truck(s) low on fuel`, run: () => fuelAll(), cls: 'text-gold' })
  if (expiredDrivers.value > 0) items.push({ icon: '⚠️', text: `${expiredDrivers.value} driver(s) with expired licence`, run: () => router.push('/drivers'), cls: 'text-loss' })
  if ((c.value?.debt ?? 0) > 0) items.push({ icon: '💳', text: `Outstanding debt ${credits(c.value?.debt ?? 0)}`, run: () => router.push('/finance') })
  return items
})

async function serviceAll() {
  busy.value = 'service'
  try {
    const { data } = await api.post('/fleet/service-all')
    toast.success(data.message)
    await Promise.all([game.refreshDashboard(), loadExtras()])
  } catch (e) { toast.error(apiError(e)) } finally { busy.value = null }
}
async function fuelAll() {
  busy.value = 'fuel'
  try {
    const { data } = await api.post('/fleet/refuel-all')
    toast.success(data.message)
    await Promise.all([game.refreshDashboard(), loadExtras()])
  } catch (e) { toast.error(apiError(e)) } finally { busy.value = null }
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

    <!-- Level progress + quick KPIs (last 7 days) -->
    <div class="glass p-4">
      <div class="flex items-center justify-between text-xs mb-1.5">
        <span class="font-semibold">Level {{ c?.level }} <span v-if="myRank" class="text-gold ml-2">🏆 Rank #{{ myRank }}</span></span>
        <span class="text-slate-400">{{ xpPct }}% to next level</span>
      </div>
      <div class="h-2 rounded-full bg-ink-700 overflow-hidden"><div class="h-full bg-gradient-to-r from-brand to-brand-glow transition-all" :style="{ width: xpPct + '%' }" /></div>
      <div v-if="kpis" class="grid grid-cols-3 sm:grid-cols-5 gap-2 mt-3 text-center">
        <div><p class="stat-label">Profit margin</p><p class="font-mono font-semibold" :class="kpis.profit_margin >= 0 ? 'text-gain' : 'text-loss'">{{ kpis.profit_margin }}%</p></div>
        <div><p class="stat-label">On-time</p><p class="font-mono font-semibold text-gain">{{ kpis.on_time_pct }}%</p></div>
        <div><p class="stat-label">Fleet in use</p><p class="font-mono font-semibold text-brand-soft">{{ kpis.fleet_utilization }}%</p></div>
        <div><p class="stat-label">Avg / delivery</p><p class="font-mono font-semibold" :class="kpis.avg_profit_per_delivery >= 0 ? 'text-gain' : 'text-loss'">{{ credits(kpis.avg_profit_per_delivery, { compact: true }) }}</p></div>
        <div><p class="stat-label">Cash runway</p><p class="font-mono font-semibold text-gold">{{ kpis.cash_runway_days == null ? '∞' : kpis.cash_runway_days + 'd' }}</p></div>
      </div>
    </div>

    <!-- Needs your attention -->
    <div v-if="actions.length" class="glass p-4 ring-1 ring-gold/25">
      <h2 class="font-semibold text-sm mb-2">🔔 Needs your attention</h2>
      <div class="grid sm:grid-cols-2 gap-x-6 gap-y-1.5">
        <div v-for="(a, i) in actions" :key="i" class="flex items-center justify-between gap-2 text-sm">
          <span class="min-w-0 truncate" :class="a.cls"><span class="mr-1">{{ a.icon }}</span>{{ a.text }}</span>
          <button class="btn-ghost !py-1 !px-3 text-[11px] shrink-0" :disabled="busy !== null" @click="a.run()">Fix →</button>
        </div>
      </div>
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

      <!-- Right column: advisor tip, missions, P&L, news -->
      <section class="space-y-6">
        <!-- AI Advisor top tip -->
        <div v-if="topTip" class="glass p-4 ring-1 ring-brand/20">
          <div class="flex items-start gap-3">
            <span class="text-2xl">{{ topTip.icon }}</span>
            <div class="min-w-0">
              <p class="font-semibold text-sm">{{ topTip.title }}</p>
              <p class="text-xs text-slate-400 leading-snug mt-0.5">{{ topTip.detail }}</p>
              <RouterLink v-if="topTip.action" :to="topTip.action.to" class="text-xs text-brand hover:underline mt-1 inline-block">{{ topTip.action.label }} →</RouterLink>
            </div>
          </div>
        </div>

        <!-- Active missions -->
        <div v-if="activeMissions.length" class="glass p-5">
          <div class="flex items-center justify-between mb-3">
            <h2 class="font-semibold">Missions</h2>
            <RouterLink to="/missions" class="text-xs text-brand hover:underline">All →</RouterLink>
          </div>
          <div class="space-y-3">
            <div v-for="m in activeMissions" :key="m.id">
              <div class="flex items-center justify-between text-xs mb-1">
                <span class="truncate">{{ m.title }}</span>
                <span class="shrink-0" :class="m.status === 'completed' ? 'text-gain font-semibold' : 'text-slate-400'">
                  {{ m.status === 'completed' ? 'Ready ✓' : num(m.progress) + '/' + num(m.target) }}
                </span>
              </div>
              <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden">
                <div class="h-full" :class="m.status === 'completed' ? 'bg-gain' : 'bg-brand'" :style="{ width: Math.min(100, (m.progress / Math.max(1, m.target)) * 100) + '%' }" />
              </div>
            </div>
          </div>
        </div>

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
