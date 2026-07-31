<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

const mine = ref<any>(null)
const directory = ref<any[]>([])
const createCost = ref(0)
const form = ref({ name: '', tag: '' })
const contribution = ref(10000)
const busy = ref(false)

async function load() {
  const { data } = await api.get('/guilds')
  mine.value = data.mine
  directory.value = data.directory
  createCost.value = data.create_cost
}

async function act(fn: () => Promise<any>, msg?: string) {
  busy.value = true
  try { const { data } = await fn(); toast.success(msg || data.message); await load(); game.refreshDashboard().catch(() => {}) }
  catch (e) { toast.error(apiError(e)) } finally { busy.value = false }
}

const create = () => act(() => api.post('/guilds', form.value))
const join = (g: any) => act(() => api.post(`/guilds/${g.id}/join`))
const leave = () => act(() => api.post('/guilds/leave'))
const contribute = () => act(() => api.post('/guilds/contribute', { amount: Math.round(contribution.value * 100) }))

onMounted(() => load().catch((e) => toast.error(apiError(e))))
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-2xl font-bold">Guilds</h1>
      <p class="text-slate-400 text-sm">Team up with other companies, pool a treasury, and climb the alliance ranks.</p>
    </div>

    <!-- My guild -->
    <div v-if="mine" class="glass p-5">
      <div class="flex items-center gap-3">
        <div class="h-11 w-11 rounded-xl flex items-center justify-center font-black text-ink-950" :style="{ background: mine.emblem_color }">
          {{ mine.tag.charAt(0) }}
        </div>
        <div class="flex-1">
          <p class="font-bold text-lg">{{ mine.name }} <span class="text-slate-400 text-sm">[{{ mine.tag }}]</span></p>
          <p class="text-[11px] text-slate-400">{{ mine.member_count }} members · {{ num(mine.total_reputation) }} total rep · Treasury {{ credits(mine.treasury, { compact: true }) }}</p>
        </div>
        <button class="btn-danger !py-1.5" :disabled="busy" @click="leave">Leave</button>
      </div>

      <div class="mt-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[160px]">
          <label class="stat-label">Contribute to treasury (₡)</label>
          <input v-model.number="contribution" type="number" min="1" class="input mt-1" />
        </div>
        <button class="btn-ghost" :disabled="busy" @click="contribute">Contribute</button>
      </div>

      <div class="mt-4">
        <p class="stat-label mb-2">Members</p>
        <div class="space-y-1">
          <div v-for="m in mine.members" :key="m.name" class="flex items-center justify-between text-sm bg-ink-900/50 rounded-lg px-3 py-2">
            <span class="flex items-center gap-2">
              <span class="h-2.5 w-2.5 rounded-full" :style="{ background: m.logo_color }" />
              {{ m.name }}
              <span v-if="m.is_owner" class="chip bg-gold/20 text-gold">Owner</span>
            </span>
            <span class="text-[11px] text-slate-400 font-mono">Lv {{ m.level }} · {{ num(m.reputation) }} rep · {{ credits(m.contribution, { compact: true }) }}</span>
          </div>
        </div>
      </div>
    </div>

    <!-- Create -->
    <div v-else class="glass p-5">
      <h2 class="font-semibold mb-3">Found a guild ({{ credits(createCost) }})</h2>
      <div class="flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[180px]">
          <label class="stat-label">Guild name</label>
          <input v-model="form.name" class="input mt-1" placeholder="Iron Road Alliance" maxlength="40" />
        </div>
        <div class="w-28">
          <label class="stat-label">Tag</label>
          <input v-model="form.tag" class="input mt-1 uppercase" placeholder="IRON" maxlength="6" />
        </div>
        <button class="btn-primary" :disabled="busy" @click="create">Found</button>
      </div>
    </div>

    <!-- Directory -->
    <div>
      <h2 class="font-semibold mb-3">Top Guilds</h2>
      <div class="grid md:grid-cols-2 xl:grid-cols-3 gap-3">
        <div v-for="g in directory" :key="g.id" class="glass p-4 flex items-center gap-3">
          <div class="h-9 w-9 rounded-lg flex items-center justify-center font-bold text-ink-950 text-sm shrink-0" :style="{ background: g.emblem_color }">{{ g.tag.charAt(0) }}</div>
          <div class="min-w-0 flex-1">
            <p class="font-semibold text-sm truncate">{{ g.name }} <span class="text-slate-500">[{{ g.tag }}]</span></p>
            <p class="text-[11px] text-slate-400">{{ g.member_count }} members · {{ num(g.total_reputation) }} rep</p>
          </div>
          <button v-if="!mine" class="btn-ghost !py-1.5 text-xs" :disabled="busy" @click="join(g)">Join</button>
        </div>
        <div v-if="!directory.length" class="glass p-6 text-center text-slate-500 col-span-full">No guilds yet — be the first to found one!</div>
      </div>
    </div>
  </div>
</template>
