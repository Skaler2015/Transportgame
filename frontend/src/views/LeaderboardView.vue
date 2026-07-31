<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'

const auth = useAuthStore()
const toast = useToastStore()

interface Row {
  rank: number; name: string; logo_color: string; level: number
  reputation: number; value: number; shipments_completed: number; fleet_size: number
}
const rows = ref<Row[]>([])

onMounted(async () => {
  try {
    const { data } = await api.get('/world/leaderboard')
    rows.value = data.data
  } catch (e) {
    toast.error(apiError(e))
  }
})
</script>

<template>
  <div class="space-y-5">
    <div>
      <h1 class="text-2xl font-bold">Leaderboard</h1>
      <p class="text-slate-400 text-sm">The most reputable logistics empires across Transoria.</p>
    </div>

    <div class="glass overflow-hidden">
      <table class="w-full text-sm">
        <thead>
          <tr class="text-left text-slate-400 border-b border-white/10">
            <th class="px-4 py-3 font-semibold">#</th>
            <th class="px-4 py-3 font-semibold">Company</th>
            <th class="px-4 py-3 font-semibold text-right">Reputation</th>
            <th class="px-4 py-3 font-semibold text-right hidden sm:table-cell">Value</th>
            <th class="px-4 py-3 font-semibold text-right hidden md:table-cell">Delivered</th>
            <th class="px-4 py-3 font-semibold text-right hidden md:table-cell">Fleet</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="r in rows" :key="r.rank"
            class="border-b border-white/5 transition"
            :class="r.name === auth.company?.name ? 'bg-brand/10' : 'hover:bg-white/5'">
            <td class="px-4 py-3 font-mono">
              <span v-if="r.rank <= 3" class="text-lg">{{ ['🥇', '🥈', '🥉'][r.rank - 1] }}</span>
              <span v-else class="text-slate-500">{{ r.rank }}</span>
            </td>
            <td class="px-4 py-3">
              <div class="flex items-center gap-2">
                <span class="h-6 w-6 rounded-md flex items-center justify-center text-[11px] font-bold text-ink-950"
                  :style="{ background: r.logo_color }">{{ r.name.charAt(0) }}</span>
                <span class="font-medium">{{ r.name }}</span>
                <span class="chip bg-white/5 text-slate-400">Lv {{ r.level }}</span>
              </div>
            </td>
            <td class="px-4 py-3 text-right font-mono text-brand-soft">{{ num(r.reputation) }}</td>
            <td class="px-4 py-3 text-right font-mono hidden sm:table-cell">{{ credits(r.value, { compact: true }) }}</td>
            <td class="px-4 py-3 text-right font-mono hidden md:table-cell">{{ num(r.shipments_completed) }}</td>
            <td class="px-4 py-3 text-right font-mono hidden md:table-cell">{{ r.fleet_size }}</td>
          </tr>
        </tbody>
      </table>
      <div v-if="!rows.length" class="p-8 text-center text-slate-400">No companies ranked yet.</div>
    </div>
  </div>
</template>
