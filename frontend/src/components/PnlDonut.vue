<script setup lang="ts">
import { computed } from 'vue'
import { Doughnut } from 'vue-chartjs'
import { Chart as ChartJS, ArcElement, Tooltip, Legend } from 'chart.js'
import { credits } from '../utils/format'

ChartJS.register(ArcElement, Tooltip, Legend)

const props = defineProps<{ pnl: Record<string, number> }>()

const COLORS: Record<string, string> = {
  revenue: '#34d399', fuel: '#38bdf8', wages: '#a78bfa',
  purchase: '#fbbf24', upkeep: '#f472b6', penalty: '#fb7185', research: '#22d3ee',
}

const entries = computed(() =>
  Object.entries(props.pnl)
    .map(([k, v]) => [k, Math.abs(v)] as const)
    .filter(([, v]) => v > 0),
)

const chartData = computed(() => ({
  labels: entries.value.map(([k]) => k),
  datasets: [
    {
      data: entries.value.map(([, v]) => v / 100),
      backgroundColor: entries.value.map(([k]) => COLORS[k] ?? '#64748b'),
      borderColor: '#0b1120',
      borderWidth: 2,
      hoverOffset: 6,
    },
  ],
}))

const options: any = {
  responsive: true,
  maintainAspectRatio: true,
  cutout: '62%',
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (ctx: { label: string; raw: unknown }) => `${ctx.label}: ${credits(Number(ctx.raw) * 100)}`,
      },
    },
  },
}
</script>

<template>
  <div class="max-w-[220px] mx-auto">
    <Doughnut v-if="entries.length" :data="chartData" :options="options" />
    <p v-else class="text-center text-sm text-slate-500 py-8">No financial activity yet.</p>
  </div>
</template>
