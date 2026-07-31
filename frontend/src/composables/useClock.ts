import { ref, onMounted, onUnmounted } from 'vue'

/** A reactive `now` timestamp (ms) that ticks every `intervalMs`. */
export function useClock(intervalMs = 1000) {
  const now = ref(Date.now())
  let id: number | undefined
  onMounted(() => {
    id = window.setInterval(() => (now.value = Date.now()), intervalMs)
  })
  onUnmounted(() => clearInterval(id))
  return now
}

/** Live 0..100 progress between two ISO timestamps, given a reactive now. */
export function progressBetween(departedIso: string, etaIso: string, nowMs: number): number {
  const start = new Date(departedIso).getTime()
  const end = new Date(etaIso).getTime()
  if (end <= start) return 100
  return Math.max(0, Math.min(100, ((nowMs - start) / (end - start)) * 100))
}
