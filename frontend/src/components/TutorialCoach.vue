<script setup lang="ts">
import { computed, ref } from 'vue'
import { useAuthStore } from '../stores/auth'

// A one-time welcome walkthrough of the core loop. Shows until the company is
// marked onboarded (persisted server-side), so it never nags returning players.
const auth = useAuthStore()

const STEPS = [
  {
    icon: '🚚',
    title: 'Welcome aboard, CEO',
    body: 'You start with one truck, a trailer, a driver and a garage. Your job: move cargo where it’s scarce and grow into the largest carrier in the country.',
  },
  {
    icon: '▤',
    title: '1 · Claim a contract',
    body: 'Open the Contract Market and claim a haulage job your fleet can carry. Tick “Only what my fleet can haul” to see jobs your starter van fits.',
  },
  {
    icon: '⟳',
    title: '2 · Dispatch it',
    body: 'In Operations, slot a vehicle, a matching trailer (bigger tractors need one), a driver and enough fuel — then hit GO. The truck rolls in real time.',
  },
  {
    icon: '⛽',
    title: '3 · Keep rolling',
    body: 'Refuel and service trucks in Fleet & Dealership. Buy bigger tractors and specialised trailers as you level up to haul heavier, richer loads.',
  },
  {
    icon: '✦',
    title: 'Build your empire',
    body: 'Bank profits, trade in Warehouses, research technology, join a Guild and climb the leaderboard. The world advances every minute — so does your rivals’.',
  },
]

const step = ref(0)
const saving = ref(false)

const show = computed(() => !!auth.company && !auth.company.onboarded_at)
const current = computed(() => STEPS[step.value])
const isLast = computed(() => step.value === STEPS.length - 1)

async function next() {
  if (isLast.value) return finish()
  step.value++
  auth.updateTutorial({ step: step.value }).catch(() => {})
}

async function finish() {
  saving.value = true
  try {
    await auth.updateTutorial({ done: true })
  } catch {
    /* even if it fails, hide locally */
    if (auth.company) auth.company.onboarded_at = new Date().toISOString()
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <Teleport to="body">
    <Transition name="coach">
      <div
        v-if="show"
        class="fixed inset-0 z-[1400] grid place-items-center p-4"
        style="padding-bottom: calc(1rem + env(safe-area-inset-bottom))"
      >
        <div class="absolute inset-0 bg-ink-950/80 backdrop-blur-sm" />
        <div class="glass relative w-full max-w-md p-6 sm:p-7">
          <div class="flex items-center gap-3">
            <div class="h-12 w-12 rounded-2xl bg-gradient-to-br from-brand/30 to-brand-glow/20 grid place-items-center text-2xl">
              {{ current.icon }}
            </div>
            <div class="flex-1">
              <p class="text-[11px] uppercase tracking-[0.2em] text-brand-soft">
                Getting started · {{ step + 1 }}/{{ STEPS.length }}
              </p>
              <h2 class="text-lg font-bold leading-tight">{{ current.title }}</h2>
            </div>
          </div>

          <p class="mt-4 text-sm text-slate-300 leading-relaxed">{{ current.body }}</p>

          <!-- progress dots -->
          <div class="flex items-center gap-1.5 mt-5">
            <span
              v-for="i in STEPS.length"
              :key="i"
              class="h-1.5 rounded-full transition-all"
              :class="i - 1 === step ? 'w-6 bg-brand' : 'w-1.5 bg-ink-600'"
            />
          </div>

          <div class="flex items-center justify-between mt-6">
            <button class="text-xs text-slate-500 hover:text-slate-300" :disabled="saving" @click="finish">
              Skip tour
            </button>
            <button class="btn-primary !px-5" :disabled="saving" @click="next">
              {{ isLast ? (saving ? 'Starting…' : 'Start playing →') : 'Next →' }}
            </button>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<style scoped>
.coach-enter-active,
.coach-leave-active {
  transition: opacity 0.3s ease;
}
.coach-enter-from,
.coach-leave-to {
  opacity: 0;
}
</style>
