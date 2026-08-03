<script setup lang="ts">
import { computed, onMounted, onUnmounted, reactive, ref, shallowRef } from 'vue'
import L from 'leaflet'
import { api, apiError } from '../api/client'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import type { City, Shipment } from '../types'
import { credits, WEATHER_ICON, cur } from '../utils/format'

const game = useGameStore()
const toast = useToastStore()

const mapEl = ref<HTMLElement | null>(null)
const map = shallowRef<L.Map>()

// One Leaflet layer group per toggleable map layer.
const groups = {
  cities: shallowRef<L.LayerGroup>(),
  routes: shallowRef<L.LayerGroup>(),
  trucks: shallowRef<L.LayerGroup>(),
  warehouses: shallowRef<L.LayerGroup>(),
  weather: shallowRef<L.LayerGroup>(),
  events: shallowRef<L.LayerGroup>(),
}
const baseDark = shallowRef<L.TileLayer>()
const baseSat = shallowRef<L.TileLayer>()

const shipments = ref<Shipment[]>([])
const warehouses = ref<{ id: number; name: string; city: { id: number; name: string } }[]>([])
const events = ref<{ id: number; type: string; title: string; severity: string; region: string | null; city_id: number | null }[]>([])
const activeCount = ref(0)

// Which overlays are on. `traffic` recolours routes by congestion; `satellite`
// swaps the base tiles for imagery.
const layers = reactive({
  cities: true, routes: true, trucks: true, warehouses: true,
  weather: false, traffic: false, events: true, satellite: false,
})
const LAYER_META: { key: keyof typeof layers; label: string; icon: string }[] = [
  { key: 'cities', label: 'Cities', icon: '🏙️' },
  { key: 'routes', label: 'Routes', icon: '🛣️' },
  { key: 'trucks', label: 'Trucks', icon: '🚚' },
  { key: 'warehouses', label: 'Warehouses', icon: '📦' },
  { key: 'weather', label: 'Weather', icon: '🌦️' },
  { key: 'traffic', label: 'Traffic', icon: '🚦' },
  { key: 'events', label: 'Live events', icon: '⚠️' },
  { key: 'satellite', label: 'Satellite', icon: '🛰️' },
]

// Selected city (right-hand inspector) and the routes touching it.
const selectedCity = ref<City | null>(null)
const cityById = computed(() => new Map(game.cities.map((c) => [c.id, c])))
const routesForCity = computed(() => {
  if (!selectedCity.value) return []
  const id = selectedCity.value.id
  return shipments.value.filter((s) => s.contract?.origin?.id === id || s.contract?.destination?.id === id)
})

let animId: number | undefined
let poll: number | undefined

// ---- visual helpers --------------------------------------------------------

function cityRole(c: City): { icon: string; color: string; label: string } {
  if (c.has_port) return { icon: '⚓', color: '#22d3ee', label: 'Port' }
  if (c.has_airport) return { icon: '✈️', color: '#a78bfa', label: 'Airport' }
  if (c.has_rail) return { icon: '🚉', color: '#f472b6', label: 'Rail hub' }
  return { icon: '', color: '#38bdf8', label: 'Inland' }
}
const WEATHER_COLOR: Record<string, string> = {
  rain: '#38bdf8', storm: '#818cf8', snow: '#e2e8f0', fog: '#94a3b8', heat: '#fb923c', flood: '#f43f5e',
}
const SEVERITY_COLOR: Record<string, string> = {
  minor: '#fbbf24', major: '#fb923c', critical: '#f43f5e',
}

// Congestion 0..1 for a lane from the destination's road quality + weather.
function congestion(s: Shipment): number {
  const d = s.contract?.destination
  const road = (d?.road_quality ?? 70) / 100
  const wet = d && ['rain', 'storm', 'snow', 'fog', 'flood'].includes(d.weather) ? 0.25 : 0
  return Math.max(0, Math.min(1, 1 - road + wet))
}
function trafficColor(x: number): string {
  return x < 0.4 ? '#34d399' : x < 0.7 ? '#fbbf24' : '#f43f5e'
}

// ---- draw layers -----------------------------------------------------------

function cityMarker(c: City) {
  const role = cityRole(c)
  const size = 10 + Math.min(12, c.population / 1_200_000)
  const glow = selectedCity.value?.id === c.id
  const html = role.icon
    ? `<div class="mk" style="--c:${role.color};width:${size}px;height:${size}px">
         <span class="mk-emoji">${role.icon}</span></div>`
    : `<div class="mk mk-dot" style="--c:${role.color};width:${size}px;height:${size}px"></div>`
  return L.marker([c.lat, c.lng], {
    icon: L.divIcon({ className: glow ? 'mk-wrap mk-sel' : 'mk-wrap', html, iconSize: [size, size], iconAnchor: [size / 2, size / 2] }),
  }).bindTooltip(
    `<b>${c.name}</b> · ${role.label}<br>${c.region} · pop ${(c.population / 1e6).toFixed(1)}M<br>` +
      `${WEATHER_ICON[c.weather] || ''} ${c.weather} · fuel ${cur()}${c.fuel_price}` +
      (c.road_quality != null ? `<br>🛣️ road ${c.road_quality} · 🚔 crime ${c.crime_index} · toll ${cur()}${c.toll_per_km}/km` : ''),
    { direction: 'top' },
  ).on('click', () => selectCity(c))
}

function drawCities() {
  const g = groups.cities.value
  if (!g) return
  g.clearLayers()
  for (const c of game.cities) cityMarker(c).addTo(g)
}

function drawWeather() {
  const g = groups.weather.value
  if (!g) return
  g.clearLayers()
  for (const c of game.cities) {
    if (c.weather === 'clear' || !c.weather) continue
    const color = WEATHER_COLOR[c.weather] ?? '#94a3b8'
    L.circleMarker([c.lat, c.lng], { radius: 16, color, weight: 1, fillColor: color, fillOpacity: 0.14, className: 'wx-halo' })
      .bindTooltip(`${WEATHER_ICON[c.weather] || ''} ${c.weather} — ${c.name}`, { direction: 'top' })
      .addTo(g)
  }
}

function drawWarehouses() {
  const g = groups.warehouses.value
  if (!g) return
  g.clearLayers()
  for (const w of warehouses.value) {
    const c = cityById.value.get(w.city?.id)
    if (!c) continue
    L.marker([c.lat, c.lng], {
      icon: L.divIcon({ className: 'mk-wrap', html: `<div class="mk mk-wh"><span class="mk-emoji">📦</span></div>`, iconSize: [20, 20], iconAnchor: [10, 10] }),
      zIndexOffset: 400,
    }).bindTooltip(`<b>${w.name}</b><br>Your warehouse · ${w.city?.name}`, { direction: 'top' }).addTo(g)
  }
}

function drawEvents() {
  const g = groups.events.value
  if (!g) return
  g.clearLayers()
  for (const e of events.value) {
    const targets = e.city_id
      ? [cityById.value.get(e.city_id)].filter(Boolean) as City[]
      : game.cities.filter((c) => c.region === e.region)
    const color = SEVERITY_COLOR[e.severity] ?? '#fb923c'
    for (const c of targets) {
      L.marker([c.lat, c.lng], {
        icon: L.divIcon({ className: 'mk-wrap', html: `<div class="ev-pulse" style="--c:${color}"></div>`, iconSize: [22, 22], iconAnchor: [11, 11] }),
        zIndexOffset: 300,
      }).bindTooltip(`⚠️ <b>${e.title}</b><br>${e.severity} · ${c.name}`, { direction: 'top' }).addTo(g)
    }
  }
}

function redrawRoutes() {
  const g = groups.routes.value
  if (!g) return
  g.clearLayers()
  for (const s of shipments.value) {
    const o = s.contract?.origin
    const d = s.contract?.destination
    if (!o || !d) continue
    const sel = selectedCity.value && (o.id === selectedCity.value.id || d.id === selectedCity.value.id)
    const color = layers.traffic ? trafficColor(congestion(s)) : '#38bdf8'
    L.polyline([[o.lat, o.lng], [d.lat, d.lng]], {
      color, weight: sel ? 3 : 1.6, opacity: sel ? 0.9 : 0.4, dashArray: '6 10', className: 'route-flow',
    }).addTo(g)
  }
}

function animateTrucks() {
  const g = groups.trucks.value
  if (g) {
    g.clearLayers()
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
      L.marker([lat, lng], {
        icon: L.divIcon({ className: 'mk-wrap', html: `<div class="truck-mk"></div>`, iconSize: [14, 14], iconAnchor: [7, 7] }),
        zIndexOffset: 500,
      }).bindTooltip(
        `${o.name} → ${d.name}<br>${s.contract?.commodity?.name} · ${credits(s.projected_payout)}<br>${Math.round(t * 100)}% · ETA layer`,
        { direction: 'top' },
      ).addTo(g)
    }
  }
  animId = requestAnimationFrame(animateTrucks)
}

// ---- interaction -----------------------------------------------------------

function selectCity(c: City) {
  selectedCity.value = c
  map.value?.flyTo([c.lat, c.lng], Math.max(map.value.getZoom(), 6), { duration: 0.6 })
  drawCities()
  redrawRoutes()
}

function toggleLayer(key: keyof typeof layers) {
  layers[key] = !layers[key]
  if (key === 'satellite') return applyBase()
  if (key === 'traffic') return redrawRoutes()
  const g = (groups as any)[key]?.value as L.LayerGroup | undefined
  if (!g || !map.value) return
  if (layers[key]) g.addTo(map.value)
  else g.remove()
}

function applyBase() {
  if (!map.value) return
  if (layers.satellite) { baseDark.value?.remove(); baseSat.value?.addTo(map.value) }
  else { baseSat.value?.remove(); baseDark.value?.addTo(map.value) }
  baseDark.value && baseDark.value.setZIndex(0)
  baseSat.value && baseSat.value.setZIndex(0)
}

// ---- data ------------------------------------------------------------------

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
async function loadWarehouses() {
  try {
    const { data } = await api.get('/warehouses')
    warehouses.value = data.data ?? []
    drawWarehouses()
  } catch { /* optional layer */ }
}
async function loadEvents() {
  try {
    const { data } = await api.get('/world/events')
    events.value = data.data ?? []
    drawEvents()
  } catch { /* optional layer */ }
}

onMounted(async () => {
  await game.loadReference().catch(() => {})
  if (!mapEl.value) return

  const m = L.map(mapEl.value, { zoomControl: true, attributionControl: false, minZoom: 3, maxZoom: 12 })
  map.value = m

  baseDark.value = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { subdomains: 'abcd' })
  baseSat.value = L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', {})
  baseDark.value.addTo(m)

  for (const key of Object.keys(groups) as (keyof typeof groups)[]) {
    groups[key].value = L.layerGroup()
    if (layers[key as keyof typeof layers]) groups[key].value!.addTo(m)
  }

  drawCities(); drawWeather(); drawEvents()
  if (game.cities.length) {
    m.fitBounds(L.latLngBounds(game.cities.map((c) => [c.lat, c.lng])).pad(0.2))
  }

  await Promise.all([loadShipments(), loadWarehouses(), loadEvents()])
  animateTrucks()
  poll = window.setInterval(() => { loadShipments(); loadEvents() }, 8000)
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
        <p class="text-slate-400 text-sm">Your network in real time — {{ activeCount }} of your trucks rolling.</p>
      </div>
    </div>

    <div class="relative">
      <div ref="mapEl" class="glass !p-0 overflow-hidden h-[72vh] w-full" style="background:#0b1120" />

      <!-- Layer control panel -->
      <div class="absolute top-3 right-3 z-[500] glass !p-2 w-40 space-y-0.5 text-xs shadow-glass">
        <p class="stat-label px-1 pb-1">Layers</p>
        <button v-for="l in LAYER_META" :key="l.key"
          class="w-full flex items-center gap-2 px-2 py-1.5 rounded-lg transition"
          :class="layers[l.key] ? 'bg-brand/15 text-slate-100' : 'text-slate-400 hover:bg-white/5'"
          @click="toggleLayer(l.key)">
          <span>{{ l.icon }}</span>
          <span class="flex-1 text-left">{{ l.label }}</span>
          <span class="h-2 w-2 rounded-full" :class="layers[l.key] ? 'bg-gain' : 'bg-white/15'" />
        </button>
      </div>

      <!-- City / route inspector -->
      <div v-if="selectedCity"
        class="absolute top-3 left-3 z-[500] glass !p-3 w-60 text-xs shadow-glass">
        <div class="flex items-start justify-between gap-2">
          <div>
            <p class="font-semibold text-sm">{{ selectedCity.name }}</p>
            <p class="text-slate-400">{{ cityRole(selectedCity).label }} · {{ selectedCity.region }}</p>
          </div>
          <button class="text-slate-400 hover:text-slate-200" @click="selectedCity = null">✕</button>
        </div>
        <div class="grid grid-cols-2 gap-1.5 mt-2">
          <div class="glass !p-1.5"><p class="stat-label">Weather</p><p>{{ WEATHER_ICON[selectedCity.weather] || '' }} {{ selectedCity.weather }}</p></div>
          <div class="glass !p-1.5"><p class="stat-label">Fuel</p><p class="font-mono">{{ cur() }}{{ selectedCity.fuel_price }}</p></div>
          <div class="glass !p-1.5"><p class="stat-label">Road</p><p class="font-mono">{{ selectedCity.road_quality ?? '—' }}</p></div>
          <div class="glass !p-1.5"><p class="stat-label">Toll/km</p><p class="font-mono">{{ cur() }}{{ selectedCity.toll_per_km ?? '—' }}</p></div>
        </div>
        <p class="stat-label mt-2 mb-1">Active routes here ({{ routesForCity.length }})</p>
        <div class="space-y-1 max-h-40 overflow-y-auto">
          <div v-for="s in routesForCity" :key="s.id" class="flex items-center justify-between">
            <span class="truncate">{{ s.contract?.origin?.name }} → {{ s.contract?.destination?.name }}</span>
            <span class="font-mono text-brand-soft shrink-0">{{ credits(s.projected_payout, { compact: true }) }}</span>
          </div>
          <p v-if="!routesForCity.length" class="text-slate-500">No trucks touching this city right now.</p>
        </div>
      </div>

      <!-- Legend -->
      <div class="absolute bottom-3 left-3 z-[500] glass !p-2 flex flex-wrap gap-x-3 gap-y-1 text-[11px] max-w-[70%]">
        <span class="flex items-center gap-1">⚓ Port</span>
        <span class="flex items-center gap-1">✈️ Airport</span>
        <span class="flex items-center gap-1">🚉 Rail</span>
        <span class="flex items-center gap-1">📦 Warehouse</span>
        <span v-if="layers.traffic" class="flex items-center gap-1"><span class="h-2 w-3 rounded" style="background:#34d399" /><span class="h-2 w-3 rounded" style="background:#fbbf24" /><span class="h-2 w-3 rounded" style="background:#f43f5e" /> Traffic</span>
      </div>
    </div>
  </div>
</template>

<style>
.leaflet-container { background: #0b1120; font-family: inherit; }
.leaflet-tooltip {
  background: #111a2e; border: 1px solid rgba(255, 255, 255, 0.12); color: #e2e8f0;
  border-radius: 8px; font-size: 11px; box-shadow: 0 8px 24px rgba(0, 0, 0, 0.5);
}
.leaflet-tooltip::before { display: none; }

/* City markers */
.mk-wrap { display: grid; place-items: center; }
.mk { display: grid; place-items: center; border-radius: 6px; }
.mk-dot { background: var(--c); box-shadow: 0 0 10px var(--c); border: 2px solid #0b1120; border-radius: 50%; }
.mk-emoji { font-size: 12px; line-height: 1; filter: drop-shadow(0 0 4px var(--c)); }
.mk-wh { width: 20px; height: 20px; background: rgba(52, 211, 153, 0.18); border: 1px solid #34d399; border-radius: 6px; }
.mk-sel .mk, .mk-sel .mk-dot { outline: 2px solid #e2e8f0; outline-offset: 2px; }

/* Truck marker */
.truck-mk { width: 14px; height: 14px; border-radius: 4px; background: #fbbf24; box-shadow: 0 0 12px #fbbf24; border: 2px solid #0b1120; }

/* Weather halo pulse */
.wx-halo { animation: wxPulse 3s ease-in-out infinite; }
@keyframes wxPulse { 0%,100% { opacity: 0.6; } 50% { opacity: 1; } }

/* Event pulse */
.ev-pulse { width: 12px; height: 12px; border-radius: 50%; background: var(--c); box-shadow: 0 0 0 0 var(--c); animation: evPulse 1.8s infinite; }
@keyframes evPulse {
  0% { box-shadow: 0 0 0 0 color-mix(in srgb, var(--c) 70%, transparent); }
  70% { box-shadow: 0 0 0 12px transparent; }
  100% { box-shadow: 0 0 0 0 transparent; }
}

/* Animated route flow */
.route-flow { animation: routeDash 1.2s linear infinite; }
@keyframes routeDash { to { stroke-dashoffset: -16; } }
</style>
