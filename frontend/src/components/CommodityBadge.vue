<script setup lang="ts">
import { computed } from 'vue'
import type { Commodity } from '../types'
import { CATEGORY_COLOR } from '../utils/format'

const props = defineProps<{ commodity?: Commodity; size?: 'sm' | 'md' }>()
const color = computed(() => CATEGORY_COLOR[props.commodity?.category ?? ''] ?? '#94a3b8')
</script>

<template>
  <span
    class="chip"
    :style="{ background: color + '22', color }"
    :class="size === 'md' ? 'text-xs px-2.5 py-1' : ''"
  >
    <span class="h-1.5 w-1.5 rounded-full" :style="{ background: color }" />
    {{ commodity?.name }}
    <span v-if="commodity?.requires_reefer" title="Refrigerated">❄</span>
    <span v-if="commodity?.requires_tanker" title="Tanker">⬢</span>
    <span v-if="commodity?.is_hazardous" title="Hazmat">☣</span>
  </span>
</template>
