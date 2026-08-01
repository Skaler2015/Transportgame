import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client'
import { useAuthStore } from './auth'
import { useToastStore } from './toast'
import { credits } from '../utils/format'
import type { City, Commodity, Dashboard } from '../types'

/**
 * Holds shared world reference data (cities, commodities) and the live
 * dashboard payload, refreshed on a polling loop while the app is open.
 * Cities are scoped to the player's country.
 */
export const useGameStore = defineStore('game', () => {
  const cities = ref<City[]>([])
  const commodities = ref<Commodity[]>([])
  const citiesCountry = ref<string | null>(null)
  const dashboard = ref<Dashboard | null>(null)
  const loadingDashboard = ref(false)

  function currentCountry(): string {
    return useAuthStore().company?.country ?? 'IN'
  }

  async function loadReference() {
    const country = currentCountry()
    // Reload cities if never loaded or the player's country changed.
    if (citiesCountry.value === country && commodities.value.length) return

    const reqs: Promise<any>[] = [api.get('/world/cities', { params: { country } })]
    if (!commodities.value.length) reqs.push(api.get('/world/commodities'))
    const [c, k] = await Promise.all(reqs)

    cities.value = c.data.data
    citiesCountry.value = country
    if (k) commodities.value = k.data.data
  }

  /** Force a cities refresh (e.g. after changing country). */
  async function reloadCities() {
    citiesCountry.value = null
    await loadReference()
  }

  async function refreshDashboard() {
    loadingDashboard.value = true
    try {
      const { data } = await api.get('/dashboard')
      dashboard.value = data
      // Celebrate anything just unlocked (server returns each unlock once).
      const unlocked = data.unlocked_achievements ?? []
      if (unlocked.length) {
        const toast = useToastStore()
        for (const a of unlocked) {
          const reward = a.reward_cash ? ` · +${credits(a.reward_cash)}` : ''
          toast.success(`🏆 Achievement: ${a.name}${reward}`)
        }
      }
    } finally {
      loadingDashboard.value = false
    }
  }

  /** Dev helper: advance the world one tick, then refresh. */
  async function advanceTick() {
    await api.post('/dev/tick')
    await refreshDashboard()
  }

  function cityById(id?: number): City | undefined {
    return id ? cities.value.find((c) => c.id === id) : undefined
  }

  return {
    cities, commodities, dashboard, loadingDashboard, reloadCities,
    loadReference, refreshDashboard, advanceTick, cityById,
  }
})
