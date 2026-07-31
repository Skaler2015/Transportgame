<script setup lang="ts">
defineProps<{
  label: string
  value: string
  sub?: string
  accent?: 'brand' | 'gain' | 'loss' | 'gold' | 'default'
  icon?: string
}>()
const accentClass: Record<string, string> = {
  brand: 'text-brand-soft',
  gain: 'text-gain',
  loss: 'text-loss',
  gold: 'text-gold',
  default: 'text-slate-100',
}
</script>

<template>
  <div class="glass glass-hover p-4 sm:p-5">
    <div class="flex items-start justify-between">
      <p class="stat-label">{{ label }}</p>
      <span v-if="icon" class="text-slate-500 text-lg leading-none">{{ icon }}</span>
    </div>
    <p class="mt-2 text-2xl font-bold font-mono tracking-tight" :class="accentClass[accent || 'default']">
      {{ value }}
    </p>
    <p v-if="sub" class="text-xs text-slate-400 mt-1">{{ sub }}</p>
  </div>
</template>
