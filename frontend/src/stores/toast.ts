import { defineStore } from 'pinia'
import { ref } from 'vue'

export interface Toast {
  id: number
  text: string
  kind: 'success' | 'error' | 'info'
}

let seq = 0

export const useToastStore = defineStore('toast', () => {
  const items = ref<Toast[]>([])

  function push(text: string, kind: Toast['kind'] = 'info') {
    const id = ++seq
    items.value.push({ id, text, kind })
    setTimeout(() => dismiss(id), 4200)
  }

  function dismiss(id: number) {
    items.value = items.value.filter((t) => t.id !== id)
  }

  const success = (t: string) => push(t, 'success')
  const error = (t: string) => push(t, 'error')
  const info = (t: string) => push(t, 'info')

  return { items, push, dismiss, success, error, info }
})
