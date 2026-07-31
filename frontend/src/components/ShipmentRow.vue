<script setup lang="ts">
import { computed } from 'vue'
import type { Shipment } from '../types'
import { progressBetween } from '../composables/useClock'
import { credits, untilString, WEATHER_ICON } from '../utils/format'

const props = defineProps<{ shipment: Shipment; now: number }>()

const pct = computed(() =>
  props.shipment.status === 'en_route' && props.shipment.departed_at && props.shipment.eta_at
    ? progressBetween(props.shipment.departed_at, props.shipment.eta_at, props.now)
    : props.shipment.progress_percent,
)
</script>

<template>
  <div class="glass p-4">
    <div class="flex items-center justify-between gap-3 mb-3">
      <div class="min-w-0">
        <p class="font-semibold text-sm truncate">
          {{ shipment.contract?.origin?.name }}
          <span class="text-slate-500 mx-1">→</span>
          {{ shipment.contract?.destination?.name }}
        </p>
        <p class="text-[11px] text-slate-400 truncate">
          {{ shipment.contract?.commodity?.name }} · {{ shipment.vehicle?.model?.name }} · {{ shipment.driver?.name }}
        </p>
      </div>
      <div class="text-right shrink-0">
        <p class="font-mono text-gold text-sm font-semibold">{{ credits(shipment.projected_payout) }}</p>
        <p class="text-[11px] text-slate-400">
          {{ WEATHER_ICON[shipment.weather_snapshot] }} ETA {{ untilString(shipment.eta_at) }}
        </p>
      </div>
    </div>

    <div class="relative h-2 rounded-full bg-ink-700 overflow-hidden">
      <div
        class="absolute inset-y-0 left-0 bg-gradient-to-r from-brand-deep to-brand-glow transition-all duration-1000 ease-linear"
        :style="{ width: pct + '%' }"
      />
    </div>
    <div class="flex justify-between mt-1.5 text-[10px] text-slate-500 font-mono">
      <span>{{ Math.round(pct) }}%</span>
      <span>{{ Math.round((pct / 100) * shipment.distance_km) }} / {{ Math.round(shipment.distance_km) }} km</span>
    </div>
  </div>
</template>
