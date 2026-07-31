<script setup lang="ts">
import { onMounted, onUnmounted, ref, computed } from 'vue'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { useClock } from '../composables/useClock'
import type { Contract, Vehicle, Driver, Shipment } from '../types'
import { credits, num } from '../utils/format'
import CommodityBadge from '../components/CommodityBadge.vue'
import ShipmentRow from '../components/ShipmentRow.vue'

const game = useGameStore()
const toast = useToastStore()
const now = useClock(1000)

const mine = ref<Contract[]>([])
const vehicles = ref<Vehicle[]>([])
const drivers = ref<Driver[]>([])
const shipments = ref<Shipment[]>([])
const selection = ref<Record<number, { vehicle_id: number | null; driver_id: number | null }>>({})
const dispatching = ref<number | null>(null)
let poll: number | undefined

async function loadAll() {
  const [m, f, d, s] = await Promise.all([
    api.get('/contracts/mine'),
    api.get('/fleet'),
    api.get('/drivers'),
    api.get('/shipments'),
  ])
  mine.value = m.data.data.filter((c: Contract) => c.status === 'accepted')
  vehicles.value = f.data.data
  drivers.value = d.data.data
  shipments.value = s.data.data
  for (const c of mine.value) {
    if (!selection.value[c.id]) selection.value[c.id] = { vehicle_id: null, driver_id: null }
  }
}

function compatibleVehicles(c: Contract): Vehicle[] {
  const com = c.commodity
  if (!com) return []
  return vehicles.value.filter((v) => {
    const m = v.model
    if (!v.available || !m) return false
    if (com.requires_reefer && !m.can_reefer) return false
    if (com.requires_tanker && !m.can_tanker) return false
    if (com.is_hazardous && !m.can_hazmat) return false
    return m.capacity_weight >= (c.total_weight ?? 0) && m.capacity_volume >= (c.total_volume ?? 0)
  })
}

function compatibleDrivers(c: Contract): Driver[] {
  return drivers.value.filter((d) => d.available && (!c.commodity?.is_hazardous || d.hazmat_licence))
}

const activeShipments = computed(() => shipments.value.filter((s) => s.status === 'en_route'))
const pastShipments = computed(() => shipments.value.filter((s) => s.status !== 'en_route').slice(0, 8))

async function dispatch(c: Contract) {
  const sel = selection.value[c.id]
  if (!sel?.vehicle_id || !sel?.driver_id) {
    toast.error('Pick a vehicle and a driver first.')
    return
  }
  dispatching.value = c.id
  try {
    await api.post('/shipments/dispatch', {
      contract_id: c.id,
      vehicle_id: sel.vehicle_id,
      driver_id: sel.driver_id,
    })
    toast.success('Dispatched! Truck is rolling.')
    await loadAll()
    game.refreshDashboard().catch(() => {})
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    dispatching.value = null
  }
}

const statusStyle: Record<string, string> = {
  delivered: 'text-gain', late: 'text-gold', failed: 'text-loss',
}

onMounted(async () => {
  await game.loadReference().catch(() => {})
  await loadAll().catch((e) => toast.error(apiError(e)))
  poll = window.setInterval(() => loadAll().catch(() => {}), 8000)
})
onUnmounted(() => clearInterval(poll))
</script>

<template>
  <div class="space-y-6">
    <div>
      <h1 class="text-2xl font-bold">Operations</h1>
      <p class="text-slate-400 text-sm">Dispatch claimed contracts and track every truck on the road.</p>
    </div>

    <!-- Ready to dispatch -->
    <section class="space-y-3">
      <h2 class="font-semibold">Ready to Dispatch
        <span class="chip bg-brand/15 text-brand-soft ml-1">{{ mine.length }}</span>
      </h2>

      <div v-if="mine.length" class="grid lg:grid-cols-2 gap-3">
        <div v-for="c in mine" :key="c.id" class="glass p-4">
          <div class="flex items-center justify-between">
            <CommodityBadge :commodity="c.commodity" size="md" />
            <p class="font-mono text-gold text-sm font-semibold">{{ credits(c.payout) }}</p>
          </div>
          <p class="text-sm font-semibold mt-2">{{ c.origin?.name }} <span class="text-brand">→</span> {{ c.destination?.name }}</p>
          <p class="text-[11px] text-slate-400">{{ num(c.distance_km) }} km · {{ num(c.total_weight ?? 0, 1) }} t · {{ num(c.total_volume ?? 0, 1) }} m³</p>

          <div class="grid grid-cols-2 gap-2 mt-3">
            <select v-model="selection[c.id].vehicle_id" class="input !py-1.5 text-xs">
              <option :value="null" disabled>Choose vehicle…</option>
              <option v-for="v in compatibleVehicles(c)" :key="v.id" :value="v.id">
                {{ v.model?.name }} ({{ num(v.condition) }}%)
              </option>
            </select>
            <select v-model="selection[c.id].driver_id" class="input !py-1.5 text-xs">
              <option :value="null" disabled>Choose driver…</option>
              <option v-for="d in compatibleDrivers(c)" :key="d.id" :value="d.id">
                {{ d.name }} (skill {{ d.skill }})
              </option>
            </select>
          </div>

          <div class="flex items-center justify-between mt-3">
            <p class="text-[11px] text-slate-500">
              <span v-if="!compatibleVehicles(c).length" class="text-loss">No compatible idle vehicle — buy or free one up.</span>
              <span v-else-if="!compatibleDrivers(c).length" class="text-loss">No available driver — hire or rest crew.</span>
              <span v-else>Deadline in your favour if you leave soon.</span>
            </p>
            <button class="btn-primary !py-1.5" :disabled="dispatching === c.id" @click="dispatch(c)">
              {{ dispatching === c.id ? '…' : 'Dispatch →' }}
            </button>
          </div>
        </div>
      </div>
      <div v-else class="glass p-8 text-center text-slate-400 text-sm">
        No claimed contracts. Head to the <RouterLink to="/contracts" class="text-brand">Contract Market</RouterLink> to claim one.
      </div>
    </section>

    <!-- Active -->
    <section class="space-y-3">
      <h2 class="font-semibold">On the Road
        <span class="chip bg-brand/15 text-brand-soft ml-1">{{ activeShipments.length }}</span>
      </h2>
      <div v-if="activeShipments.length" class="grid sm:grid-cols-2 gap-3">
        <ShipmentRow v-for="s in activeShipments" :key="s.id" :shipment="s" :now="now" />
      </div>
      <div v-else class="glass p-6 text-center text-slate-500 text-sm">Nothing en route right now.</div>
    </section>

    <!-- History -->
    <section v-if="pastShipments.length" class="space-y-3">
      <h2 class="font-semibold">Recent Deliveries</h2>
      <div class="glass divide-y divide-white/5">
        <div v-for="s in pastShipments" :key="s.id" class="flex items-center justify-between px-4 py-3 text-sm">
          <div class="min-w-0">
            <p class="truncate">{{ s.contract?.origin?.name }} → {{ s.contract?.destination?.name }}</p>
            <p class="text-[11px] text-slate-400 truncate">{{ s.contract?.commodity?.name }} · {{ s.driver?.name }}</p>
          </div>
          <div class="text-right shrink-0">
            <p class="font-semibold uppercase text-xs" :class="statusStyle[s.status]">{{ s.status }}</p>
            <p class="font-mono text-[11px] text-slate-400">{{ credits(s.projected_payout) }}</p>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>
