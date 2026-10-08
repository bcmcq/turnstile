import { acceptHMRUpdate, defineStore } from 'pinia'
import { ref } from 'vue'
import { ApiError } from '@/api/client'

export interface Toast {
  id: number
  kind: 'error' | 'info' | 'success'
  text: string
  details: string[]
}

export const useToastsStore = defineStore('toasts', () => {
  const toasts = ref<Toast[]>([])
  let seq = 0

  function push(kind: Toast['kind'], text: string, details: string[] = [], ttlMs = 5000): void {
    const id = ++seq
    toasts.value = [...toasts.value, { id, kind, text, details }]
    setTimeout(() => dismiss(id), ttlMs)
  }

  function dismiss(id: number): void {
    toasts.value = toasts.value.filter((t) => t.id !== id)
  }

  /** Surface an API failure as a toast and return it, so callers can `catch (e) { toasts.fromError(e) }`. */
  function fromError(e: unknown): void {
    if (e instanceof ApiError) push('error', e.message, e.details, e.status === 422 ? 8000 : 5000)
    else push('error', e instanceof Error ? e.message : String(e))
  }

  return { toasts, push, dismiss, fromError }
})

if (import.meta.hot) import.meta.hot.accept(acceptHMRUpdate(useToastsStore, import.meta.hot))
