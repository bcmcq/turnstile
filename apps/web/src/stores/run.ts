import { defineStore } from 'pinia'
import { computed, shallowRef } from 'vue'
import type { RunStatus, RunView } from '@/api/types'

const ACTIVE: RunStatus[] = ['pending', 'dispatching', 'running', 'paused']

export const useRunStore = defineStore('run', () => {
  const current = shallowRef<RunView | null>(null)

  const isActive = computed(() => current.value !== null && ACTIVE.includes(current.value.status))
  const isPaused = computed(() => current.value?.status === 'paused')

  const terminal = computed(() => {
    const c = current.value?.counters
    return c ? c.completed + c.skipped + c.dead_lettered + c.cancelled : 0
  })
  const progress = computed(() => {
    const total = current.value?.totalJobs ?? 0
    return total === 0 ? 0 : Math.min(1, terminal.value / total)
  })
  const remaining = computed(() => Math.max(0, (current.value?.totalJobs ?? 0) - terminal.value))

  function set(run: RunView | null): void {
    current.value = run
  }

  return { current, isActive, isPaused, terminal, progress, remaining, set }
})
