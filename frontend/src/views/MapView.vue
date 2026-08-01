<script setup lang="ts">
import { onMounted, onUnmounted, ref, shallowRef } from 'vue'
import L from 'leaflet'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { Shipment } from '../types'
import { credits, WEATHER_ICON, cur } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

const mapEl = ref<HTMLElement | null>(null)
const map = shallowRef<L.Map>()
const truckLayer = shallowRef<L.LayerGroup>()
const routeLayer = shallowRef<L.LayerGroup>()
const shipments = ref<Shipment[]>([])
const activeCount = ref(0)

const REGION_COLOR: Record<string, string> = {
  Coreland: '#38bdf8', Ironvale: '#f59e0b', Marisands: '#22d3ee',
  Sunbelt: '#84cc16', Northreach: '#a78bfa',
}

let animId: number | undefined
let poll: number | undefined

function truckIcon(color: string) {
  return L.divIcon({
    className: '',
    html: `<div style="width:14px;height:14px;border-radius:4px;background:${color};box-shadow:0 0 10px ${color};border:2px solid #0b1120"></div>`,
    iconSize: [14, 14],
    iconAnchor: [7, 7],
  })
}

function drawCities() {
  if (!map.value) return
  for (const c of game.cities) {
    const color = REGION_COLOR[c.region] ?? '#94a3b8'
    L.circleMarker([c.lat, c.lng], {
      radius: 5 + Math.min(6, c.population / 1_500_000),
      color,
      weight: 2,
      fillColor: color,
      fillOpacity: 0.35,
    })
      .bindTooltip(
        `<b>${c.name}</b><br>${c.region} · pop ${(c.population / 1e6).toFixed(1)}M<br>${WEATHER_ICON[c.weather] || ''} fuel ${cur()}${c.fuel_price}`,
        { direction: 'top' },
      )
      .addTo(map.value)
  }
}

function redrawRoutes() {
  if (!map.value) return
  routeLayer.value?.clearLayers()
  for (const s of shipments.value) {
    const o = s.contract?.origin
    const d = s.contract?.destination
    if (!o || !d) continue
    L.polyline(
      [
        [o.lat, o.lng],
        [d.lat, d.lng],
      ],
      { color: '#38bdf8', weight: 1.5, opacity: 0.35, dashArray: '6 8' },
    ).addTo(routeLayer.value!)
  }
}

function animateTrucks() {
  if (!map.value || !truckLayer.value) return
  truckLayer.value.clearLayers()
  const nowMs = Date.now()
  for (const s of shipments.value) {
    const o = s.contract?.origin
    const d = s.contract?.destination
    if (!o || !d || !s.departed_at || !s.eta_at) continue
    const start = new Date(s.departed_at).getTime()
    const end = new Date(s.eta_at).getTime()
    const t = Math.max(0, Math.min(1, (nowMs - start) / Math.max(1, end - start)))
    const lat = o.lat + (d.lat - o.lat) * t
    const lng = o.lng + (d.lng - o.lng) * t
    L.marker([lat, lng], { icon: truckIcon('#fbbf24') })
      .bindTooltip(
        `${o.name} → ${d.name}<br>${s.contract?.commodity?.name} · ${credits(s.projected_payout)}<br>${Math.round(t * 100)}%`,
        { direction: 'top' },
      )
      .addTo(truckLayer.value!)
  }
  animId = requestAnimationFrame(animateTrucks)
}

async function loadShipments() {
  try {
    const { data } = await api.get('/shipments')
    shipments.value = data.data.filter((s: Shipment) => s.status === 'en_route')
    activeCount.value = shipments.value.length
    redrawRoutes()
  } catch (e) {
    toast.error(apiError(e))
  }
}

onMounted(async () => {
  await game.loadReference().catch(() => {})
  if (!mapEl.value) return

  const m = L.map(mapEl.value, { zoomControl: true, attributionControl: false, minZoom: 4, maxZoom: 9 })
  L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', {
    subdomains: 'abcd',
  }).addTo(m)
  map.value = m
  routeLayer.value = L.layerGroup().addTo(m)
  truckLayer.value = L.layerGroup().addTo(m)

  drawCities()
  if (game.cities.length) {
    const bounds = L.latLngBounds(game.cities.map((c) => [c.lat, c.lng]))
    m.fitBounds(bounds.pad(0.2))
  }

  await loadShipments()
  animateTrucks()
  poll = window.setInterval(loadShipments, 8000)
})

onUnmounted(() => {
  if (animId) cancelAnimationFrame(animId)
  clearInterval(poll)
  map.value?.remove()
})
</script>

<template>
  <div class="space-y-4">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Live Map</h1>
        <p class="text-slate-400 text-sm">The Transoria belt in real time — {{ activeCount }} of your trucks rolling.</p>
      </div>
      <div class="flex flex-wrap gap-3 text-[11px]">
        <span v-for="(color, region) in REGION_COLOR" :key="region" class="flex items-center gap-1.5">
          <span class="h-2.5 w-2.5 rounded-full" :style="{ background: color }" />{{ region }}
        </span>
      </div>
    </div>

    <div ref="mapEl" class="glass !p-0 overflow-hidden h-[70vh] w-full" style="background:#0b1120" />
  </div>
</template>

<style>
.leaflet-container { background: #0b1120; font-family: inherit; }
.leaflet-tooltip {
  background: #111a2e; border: 1px solid rgba(255, 255, 255, 0.12); color: #e2e8f0;
  border-radius: 8px; font-size: 11px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
}
.leaflet-tooltip::before { display: none; }
</style>
