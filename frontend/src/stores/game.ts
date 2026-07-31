import { defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '../api/client'
import type { City, Commodity, Dashboard } from '../types'

/**
 * Holds shared world reference data (cities, commodities) and the live
 * dashboard payload, refreshed on a polling loop while the app is open.
 */
export const useGameStore = defineStore('game', () => {
  const cities = ref<City[]>([])
  const commodities = ref<Commodity[]>([])
  const dashboard = ref<Dashboard | null>(null)
  const loadingDashboard = ref(false)

  async function loadReference() {
    if (cities.value.length && commodities.value.length) return
    const [c, k] = await Promise.all([
      api.get('/world/cities'),
      api.get('/world/commodities'),
    ])
    cities.value = c.data.data
    commodities.value = k.data.data
  }

  async function refreshDashboard() {
    loadingDashboard.value = true
    try {
      const { data } = await api.get('/dashboard')
      dashboard.value = data
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
    cities, commodities, dashboard, loadingDashboard,
    loadReference, refreshDashboard, advanceTick, cityById,
  }
})
