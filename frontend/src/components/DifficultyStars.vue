<script setup lang="ts">
defineProps<{ value: number }>()
</script>

<template>
  <span class="inline-flex gap-0.5 text-xs" :title="`Difficulty ${value}/5`">
    <span v-for="i in 5" :key="i" :class="i <= value ? 'text-gold' : 'text-ink-600'">★</span>
  </span>
</template>
