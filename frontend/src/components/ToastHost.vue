<script setup lang="ts">
import { useToastStore } from '../stores/toast'
const toast = useToastStore()
</script>

<template>
  <!-- Toasts appear at the TOP, just below the header, centred. -->
  <div
    class="fixed left-1/2 -translate-x-1/2 z-[9999] flex flex-col gap-2 w-[calc(100%-1.5rem)] max-w-sm"
    style="top: calc(env(safe-area-inset-top) + 4.25rem)"
  >
    <TransitionGroup name="toast">
      <div
        v-for="t in toast.items"
        :key="t.id"
        class="glass px-4 py-3 flex items-start gap-3 cursor-pointer shadow-glass"
        @click="toast.dismiss(t.id)"
      >
        <span
          class="mt-0.5 h-2.5 w-2.5 rounded-full shrink-0"
          :class="{
            'bg-gain': t.kind === 'success',
            'bg-loss': t.kind === 'error',
            'bg-brand': t.kind === 'info',
          }"
        />
        <p class="text-sm text-slate-200 leading-snug">{{ t.text }}</p>
      </div>
    </TransitionGroup>
  </div>
</template>

<style scoped>
.toast-enter-active,
.toast-leave-active {
  transition: all 0.3s ease;
}
.toast-enter-from,
.toast-leave-to {
  opacity: 0;
  transform: translateY(-16px);
}
</style>
