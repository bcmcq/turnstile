import { defineStore } from 'pinia'
import { ref, shallowRef } from 'vue'
import type { LogEvent } from '@/api/types'

const MAX = 500

export const useLogStore = defineStore('log', () => {
  const events = shallowRef<LogEvent[]>([])
  const total = ref(0)

  function push(batch: LogEvent[]): void {
    if (batch.length === 0) return
    total.value += batch.length
    const next = batch.slice().reverse().concat(events.value) // newest first
    events.value = next.length > MAX ? next.slice(0, MAX) : next
  }

  function clear(): void {
    events.value = []
    total.value = 0
  }

  return { events, total, push, clear }
})
