<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, apiError } from '../api/client'
import { useToastStore } from '../stores/toast'

interface Insight {
  icon: string
  title: string
  detail: string
  action?: { label: string; to: string }
}

const toast = useToastStore()
const router = useRouter()
const insights = ref<Insight[]>([])
const loading = ref(false)

async function load() {
  loading.value = true
  try {
    const { data } = await api.get('/advisor')
    insights.value = data.data
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-5 max-w-3xl">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">AI Advisor</h1>
        <p class="text-slate-400 text-sm">Your on-staff analyst reads the market and your fleet, then tells you the smart next move.</p>
      </div>
      <button class="btn-ghost" :disabled="loading" @click="load">↻ Re-analyse</button>
    </div>

    <div v-if="loading" class="grid place-items-center h-48 text-slate-500">Crunching the numbers…</div>

    <div v-else class="space-y-3">
      <div v-for="(i, idx) in insights" :key="idx" class="glass p-4 flex gap-4 items-start">
        <div class="h-11 w-11 rounded-xl bg-gradient-to-br from-brand/25 to-brand-glow/15 grid place-items-center text-2xl shrink-0">
          {{ i.icon }}
        </div>
        <div class="min-w-0 flex-1">
          <p class="font-semibold">{{ i.title }}</p>
          <p class="text-sm text-slate-300 leading-relaxed mt-0.5">{{ i.detail }}</p>
        </div>
        <button
          v-if="i.action"
          class="btn-primary !py-1.5 !px-4 shrink-0 self-center"
          @click="router.push(i.action.to)"
        >{{ i.action.label }} →</button>
      </div>
    </div>
  </div>
</template>
