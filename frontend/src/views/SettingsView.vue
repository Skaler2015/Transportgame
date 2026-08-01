<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { api, apiError } from '../api/client'
import { useAuthStore } from '../stores/auth'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'

const auth = useAuthStore()
const game = useGameStore()
const toast = useToastStore()
const router = useRouter()

const countries = ref<{ code: string; name: string }[]>([])
const chosen = ref('')
const busy = ref(false)

// Danger zone: reset company.
const showReset = ref(false)
const resetConfirm = ref('')
const resetting = ref(false)

async function resetCompany() {
  if (resetConfirm.value.trim().toUpperCase() !== 'RESET') {
    return toast.error('Type RESET to confirm.')
  }
  resetting.value = true
  try {
    const { data } = await api.post('/company/reset')
    toast.success(data.message || 'Company reset. Fresh start!')
    await auth.fetchMe()
    await game.reloadCities().catch(() => {})
    await game.refreshDashboard().catch(() => {})
    showReset.value = false
    resetConfirm.value = ''
    router.push('/dashboard')
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    resetting.value = false
  }
}

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

    <!-- Danger zone: reset & start fresh -->
    <div class="glass p-5 border border-loss/30">
      <h2 class="font-semibold text-loss">Reset &amp; start fresh</h2>
      <p class="text-sm text-slate-400 mt-1">
        Wipe all progress and begin again from a single starter truck. This clears your
        fleet, trailers, crew, contracts, deliveries, warehouses, loans, research, missions
        and your entire ledger. Your login stays the same. <span class="text-loss">This cannot be undone.</span>
      </p>

      <div v-if="!showReset" class="mt-4">
        <button class="btn-ghost !border-loss/40 !text-loss" @click="showReset = true">
          Reset my company…
        </button>
      </div>

      <div v-else class="mt-4 space-y-3">
        <div>
          <label class="stat-label">Type <span class="text-loss font-semibold">RESET</span> to confirm</label>
          <input v-model="resetConfirm" class="input mt-1" placeholder="RESET" autocomplete="off" />
        </div>
        <div class="flex gap-2">
          <button
            class="btn-primary !bg-loss !text-white"
            :disabled="resetting || resetConfirm.trim().toUpperCase() !== 'RESET'"
            @click="resetCompany"
          >
            {{ resetting ? 'Resetting…' : 'Reset everything' }}
          </button>
          <button class="btn-ghost" :disabled="resetting" @click="showReset = false; resetConfirm = ''">Cancel</button>
        </div>
      </div>
    </div>
  </div>
</template>
