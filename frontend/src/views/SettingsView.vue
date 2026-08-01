<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'

const auth = useAuthStore()
const game = useGameStore()
const toast = useToastStore()

const countries = ref<{ code: string; name: string }[]>([])
const chosen = ref('')
const busy = ref(false)

onMounted(async () => {
  try {
    const { data } = await api.get('/world/countries')
    countries.value = data.data
  } catch { /* ignore */ }
  chosen.value = auth.company?.country ?? 'IN'
})

async function changeCountry() {
  if (!chosen.value || chosen.value === auth.company?.country) {
    return toast.info('That is already your country.')
  }
  if (!confirm('Relocating moves your whole fleet to a new HQ and cancels pending contracts/shipments. Continue?')) return

  busy.value = true
  try {
    const { data } = await api.post('/company/country', { country: chosen.value })
    toast.success(data.message)
    await auth.fetchMe()
    await game.reloadCities()
    await game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
    chosen.value = auth.company?.country ?? 'IN'
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="space-y-5 max-w-2xl">
    <div>
      <h1 class="text-2xl font-bold">Settings</h1>
      <p class="text-slate-400 text-sm">Manage your company.</p>
    </div>

    <div class="glass p-5">
      <h2 class="font-semibold">Operating country</h2>
      <p class="text-sm text-slate-400 mt-1">
        Your company operates within one country's cities. Changing it relocates your
        entire fleet to a new headquarters there.
      </p>

      <div class="mt-4 flex flex-wrap items-end gap-3">
        <div class="flex-1 min-w-[200px]">
          <label class="stat-label">Country</label>
          <select v-model="chosen" class="input mt-1">
            <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
          </select>
        </div>
        <button class="btn-primary" :disabled="busy || chosen === auth.company?.country" @click="changeCountry">
          {{ busy ? 'Relocating…' : 'Relocate' }}
        </button>
      </div>

      <p class="text-[11px] text-loss mt-3">
        ⚠ Relocating cancels any active deliveries and claimed contracts, and frees your trucks at the new HQ.
      </p>
    </div>

    <div class="glass p-5">
      <h2 class="font-semibold mb-2">Company</h2>
      <div class="grid grid-cols-2 gap-3 text-sm">
        <div><p class="stat-label">Name</p><p>{{ auth.company?.name }}</p></div>
        <div><p class="stat-label">Country</p><p>{{ auth.company?.country_name || auth.company?.country }}</p></div>
        <div><p class="stat-label">HQ</p><p>{{ auth.company?.headquarters?.name || '—' }}</p></div>
        <div><p class="stat-label">Level</p><p>{{ auth.company?.level }}</p></div>
      </div>
    </div>
  </div>
</template>
