<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import { useAuthStore } from '../stores/auth'
import type { NewsItem } from '../types'

const toast = useToastStore()
const auth = useAuthStore()
const items = ref<NewsItem[]>([])
const loading = ref(false)
let poll: number | undefined

async function load(silent = false) {
  if (!silent) loading.value = true
  try {
    const { data } = await api.get('/news')
    items.value = data.data
  } catch (e) {
    if (!silent) toast.error(apiError(e))
  } finally {
    loading.value = false
  }
}

const SEV: Record<string, string> = {
  info: 'border-brand/40 text-brand-soft',
  warning: 'border-gold/50 text-gold',
  critical: 'border-loss/50 text-loss',
}

function timeAgo(iso: string): string {
  const s = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 1000))
  if (s < 60) return 'just now'
  if (s < 3600) return `${Math.floor(s / 60)}m ago`
  if (s < 86400) return `${Math.floor(s / 3600)}h ago`
  return `${Math.floor(s / 86400)}d ago`
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
        <h1 class="text-2xl font-bold">News Wire</h1>
        <p class="text-slate-400 text-sm">
          Live headlines shaping {{ auth.company?.country_name || 'your country' }}'s logistics market.
        </p>
      </div>
      <button class="btn-ghost" @click="load()">↻ Refresh</button>
    </div>

    <div v-if="loading" class="grid place-items-center h-48 text-slate-500">Loading the wire…</div>

    <div v-else-if="!items.length" class="glass p-10 text-center text-slate-400">
      Quiet on the wire right now. Headlines appear as world events and markets move.
    </div>

    <div v-else class="space-y-2.5">
      <article
        v-for="n in items"
        :key="n.id"
        class="glass p-4 flex gap-3 border-l-2"
        :class="SEV[n.severity] || SEV.info"
      >
        <div class="text-2xl leading-none shrink-0">{{ n.icon }}</div>
        <div class="min-w-0 flex-1">
          <div class="flex items-center justify-between gap-2">
            <p class="font-semibold text-sm leading-snug">{{ n.headline }}</p>
            <span class="text-[10px] text-slate-500 shrink-0">{{ timeAgo(n.occurred_at) }}</span>
          </div>
          <p v-if="n.body" class="text-[12px] text-slate-400 mt-1 leading-relaxed">{{ n.body }}</p>
          <span class="chip bg-white/5 text-slate-400 mt-2 capitalize">{{ n.category }}</span>
        </div>
      </article>
    </div>
  </div>
</template>
