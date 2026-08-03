<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'

const toast = useToastStore()
const tab = ref<'overview' | 'players' | 'world' | 'live'>('overview')

// Overview
const stats = ref<Record<string, number>>({})
const STAT_CARDS: { key: string; label: string; money?: boolean; accent?: string }[] = [
  { key: 'players', label: 'Players' },
  { key: 'companies', label: 'Companies' },
  { key: 'ai_companies', label: 'AI rivals', accent: 'text-brand-soft' },
  { key: 'banned', label: 'Banned', accent: 'text-loss' },
  { key: 'vehicles', label: 'Vehicles' },
  { key: 'active_shipments', label: 'On the road' },
  { key: 'open_contracts', label: 'Open contracts' },
  { key: 'active_events', label: 'Active events', accent: 'text-gold' },
  { key: 'deliveries', label: 'Deliveries (all-time)' },
  { key: 'player_cash', label: 'Player cash', money: true, accent: 'text-gain' },
  { key: 'world_revenue', label: 'World revenue', money: true },
  { key: 'regions', label: 'Regions' },
]

// Players
interface PUser { id: number; name: string; email: string; is_admin: boolean; banned_at: string | null; created_at: string; company: { name: string; cash: number; level: number; reputation: number } | null }
const users = ref<PUser[]>([])
const q = ref('')
const busyUser = ref<number | null>(null)

// World
interface Ev { id: number; type: string; title: string; severity: string; region: string | null; ends_at: string }
const activeEvents = ref<Ev[]>([])
const catalog = ref<{ type: string; title: string; severity: string }[]>([])
const regions = ref<string[]>([])
const spawnType = ref('')
const spawnRegion = ref('')
const spawnHours = ref(6)
const fuelRegion = ref('')
const fuelDelta = ref(10)
const busyWorld = ref(false)

// Live
const live = ref<{ shipments: any[]; news: any[]; events: number }>({ shipments: [], news: [], events: 0 })

async function loadOverview() {
  try { stats.value = (await api.get('/admin/dashboard')).data } catch (e) { toast.error(apiError(e)) }
}
async function loadPlayers() {
  try { users.value = (await api.get('/admin/users', { params: { q: q.value } })).data.data } catch (e) { toast.error(apiError(e)) }
}
async function loadWorld() {
  try {
    const { data } = await api.get('/admin/events')
    activeEvents.value = data.active; catalog.value = data.catalog; regions.value = data.regions
    if (!spawnType.value) spawnType.value = catalog.value[0]?.type ?? ''
  } catch (e) { toast.error(apiError(e)) }
}
async function loadLive() {
  try { live.value = (await api.get('/admin/live')).data } catch (e) { toast.error(apiError(e)) }
}

function open(t: typeof tab.value) {
  tab.value = t
  if (t === 'overview') loadOverview()
  if (t === 'players') loadPlayers()
  if (t === 'world') loadWorld()
  if (t === 'live') loadLive()
}

async function toggleBan(u: PUser) {
  busyUser.value = u.id
  try { await api.post(`/admin/users/${u.id}/ban`); await loadPlayers() }
  catch (e) { toast.error(apiError(e)) } finally { busyUser.value = null }
}
async function toggleAdmin(u: PUser) {
  busyUser.value = u.id
  try { await api.post(`/admin/users/${u.id}/admin`); await loadPlayers() }
  catch (e) { toast.error(apiError(e)) } finally { busyUser.value = null }
}

async function spawnEvent() {
  busyWorld.value = true
  try {
    await api.post('/admin/events', { type: spawnType.value, region: spawnRegion.value || null, hours: spawnHours.value })
    toast.success('Event unleashed on the world.'); await loadWorld()
  } catch (e) { toast.error(apiError(e)) } finally { busyWorld.value = false }
}
async function endEvent(e: Ev) {
  try { await api.post(`/admin/events/${e.id}/end`); await loadWorld() } catch (err) { toast.error(apiError(err)) }
}
async function nudgeFuel() {
  busyWorld.value = true
  try {
    const { data } = await api.post('/admin/economy/fuel', { region: fuelRegion.value || null, delta_pct: fuelDelta.value })
    toast.success(`Fuel prices adjusted in ${data.affected} cities.`)
  } catch (e) { toast.error(apiError(e)) } finally { busyWorld.value = false }
}
async function forceTick() {
  busyWorld.value = true
  try { await api.post('/admin/tick'); toast.success('World advanced one tick.'); await loadWorld() }
  catch (e) { toast.error(apiError(e)) } finally { busyWorld.value = false }
}

const SEV_CLASS: Record<string, string> = { minor: 'text-gold', major: 'text-orange-400', critical: 'text-loss', info: 'text-brand-soft' }

onMounted(loadOverview)
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-2xl font-bold flex items-center gap-2">🛡️ Admin Control Room</h1>
      <p class="text-slate-400 text-sm">Operate the world — economy, events and moderation. Handle with care.</p>
    </div>

    <div class="flex gap-1 glass !p-1 w-fit text-sm">
      <button v-for="t in (['overview','players','world','live'] as const)" :key="t"
        class="px-3 py-1.5 rounded-lg capitalize transition"
        :class="tab === t ? 'bg-brand text-ink-950 font-semibold' : 'text-slate-400 hover:bg-white/5'"
        @click="open(t)">{{ t }}</button>
    </div>

    <!-- OVERVIEW -->
    <div v-if="tab === 'overview'" class="grid grid-cols-2 md:grid-cols-4 gap-3">
      <div v-for="c in STAT_CARDS" :key="c.key" class="glass p-4">
        <p class="stat-label">{{ c.label }}</p>
        <p class="text-xl font-bold" :class="c.accent">
          {{ c.money ? credits(stats[c.key] ?? 0, { compact: true }) : num(stats[c.key] ?? 0) }}
        </p>
      </div>
    </div>

    <!-- PLAYERS -->
    <div v-else-if="tab === 'players'" class="space-y-3">
      <div class="flex gap-2">
        <input v-model="q" class="input flex-1" placeholder="Search name or email…" @keyup.enter="loadPlayers" />
        <button class="btn-ghost" @click="loadPlayers">Search</button>
      </div>
      <div class="glass overflow-hidden">
        <div class="overflow-x-auto">
        <table class="w-full text-sm min-w-[680px]">
          <thead><tr class="text-left text-slate-400 border-b border-white/10">
            <th class="px-3 py-2">Player</th><th class="px-3 py-2">Company</th>
            <th class="px-3 py-2 text-right">Cash</th><th class="px-3 py-2 text-center">Role</th><th class="px-3 py-2 text-right">Actions</th>
          </tr></thead>
          <tbody>
            <tr v-for="u in users" :key="u.id" class="border-b border-white/5" :class="u.banned_at ? 'bg-loss/5' : ''">
              <td class="px-3 py-2">
                <p class="font-medium">{{ u.name }} <span v-if="u.banned_at" class="chip bg-loss/20 text-loss">Banned</span></p>
                <p class="text-[11px] text-slate-500">{{ u.email }}</p>
              </td>
              <td class="px-3 py-2">{{ u.company?.name ?? '—' }}<span v-if="u.company" class="text-slate-500 text-[11px]"> · Lv {{ u.company.level }}</span></td>
              <td class="px-3 py-2 text-right font-mono">{{ u.company ? credits(u.company.cash, { compact: true }) : '—' }}</td>
              <td class="px-3 py-2 text-center"><span v-if="u.is_admin" class="chip bg-brand/20 text-brand-soft">Admin</span><span v-else class="text-slate-600">—</span></td>
              <td class="px-3 py-2 text-right whitespace-nowrap">
                <button class="btn-ghost !py-1 !px-2 text-[11px]" :disabled="busyUser === u.id" @click="toggleAdmin(u)">{{ u.is_admin ? 'Revoke' : 'Make' }} admin</button>
                <button class="btn-ghost !py-1 !px-2 text-[11px] ml-1" :class="u.banned_at ? 'text-gain' : 'text-loss'" :disabled="busyUser === u.id" @click="toggleBan(u)">{{ u.banned_at ? 'Unban' : 'Ban' }}</button>
              </td>
            </tr>
            <tr v-if="!users.length"><td colspan="5" class="p-6 text-center text-slate-500">No players found.</td></tr>
          </tbody>
        </table>
        </div>
      </div>
    </div>

    <!-- WORLD -->
    <div v-else-if="tab === 'world'" class="grid lg:grid-cols-2 gap-4">
      <div class="glass p-5 space-y-3">
        <h2 class="font-semibold">Spawn a world event</h2>
        <div class="grid grid-cols-2 gap-2">
          <select v-model="spawnType" class="input"><option v-for="c in catalog" :key="c.type" :value="c.type">{{ c.title }}</option></select>
          <select v-model="spawnRegion" class="input"><option value="">Continental (all)</option><option v-for="r in regions" :key="r" :value="r">{{ r }}</option></select>
        </div>
        <label class="stat-label">Duration: {{ spawnHours }}h</label>
        <input v-model.number="spawnHours" type="range" min="1" max="48" class="w-full" />
        <button class="btn-primary w-full" :disabled="busyWorld" @click="spawnEvent">Unleash event</button>

        <div class="border-t border-white/10 pt-3 space-y-2">
          <h3 class="font-semibold text-sm">Fuel market</h3>
          <div class="grid grid-cols-2 gap-2">
            <select v-model="fuelRegion" class="input"><option value="">All regions</option><option v-for="r in regions" :key="r" :value="r">{{ r }}</option></select>
            <input v-model.number="fuelDelta" type="number" class="input" min="-50" max="100" />
          </div>
          <button class="btn-ghost w-full" :disabled="busyWorld" @click="nudgeFuel">Adjust fuel by {{ fuelDelta }}%</button>
        </div>

        <button class="btn-ghost w-full" :disabled="busyWorld" @click="forceTick">⏭ Advance world one tick</button>
      </div>

      <div class="glass p-5">
        <h2 class="font-semibold mb-3">Active events ({{ activeEvents.length }})</h2>
        <div class="space-y-2">
          <div v-for="e in activeEvents" :key="e.id" class="flex items-center justify-between glass !bg-white/5 !p-2.5">
            <div>
              <p class="text-sm font-medium" :class="SEV_CLASS[e.severity]">{{ e.title }}</p>
              <p class="text-[11px] text-slate-500">{{ e.region || 'Continental' }} · {{ e.severity }}</p>
            </div>
            <button class="btn-ghost !py-1 !px-2 text-[11px] text-loss" @click="endEvent(e)">End</button>
          </div>
          <p v-if="!activeEvents.length" class="text-sm text-slate-500">No active events. The world is calm.</p>
        </div>
      </div>
    </div>

    <!-- LIVE -->
    <div v-else class="grid lg:grid-cols-2 gap-4">
      <div class="glass p-5">
        <div class="flex items-center justify-between mb-3"><h2 class="font-semibold">Trucks on the road</h2><button class="btn-ghost !py-1 text-xs" @click="loadLive">↻</button></div>
        <div class="space-y-1.5 max-h-[60vh] overflow-y-auto">
          <div v-for="s in live.shipments" :key="s.id" class="flex justify-between text-sm">
            <span class="truncate">{{ s.lane }}</span><span class="text-slate-500 text-[11px] shrink-0 ml-2">{{ s.company }}</span>
          </div>
          <p v-if="!live.shipments.length" class="text-sm text-slate-500">No active shipments.</p>
        </div>
      </div>
      <div class="glass p-5">
        <h2 class="font-semibold mb-3">Latest world news</h2>
        <div class="space-y-1.5 max-h-[60vh] overflow-y-auto">
          <div v-for="(n, i) in live.news" :key="i" class="text-sm flex gap-2">
            <span>{{ n.icon }}</span><span class="truncate" :class="SEV_CLASS[n.severity]">{{ n.headline }}</span>
          </div>
          <p v-if="!live.news.length" class="text-sm text-slate-500">No news yet.</p>
        </div>
      </div>
    </div>
  </div>
</template>
