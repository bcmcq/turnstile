import { acceptHMRUpdate, defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { api } from '@/api/client'
import type { RunStatus, RunView, StartRunRequest } from '@/api/types'

const ACTIVE: RunStatus[] = ['pending', 'dispatching', 'running', 'paused']

export const useRunStore = defineStore('run', () => {
  const current = shallowRef<RunView | null>(null)
  const busy = ref(false)

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

  async function call(fn: () => Promise<RunView>): Promise<RunView> {
    busy.value = true
    try {
      const run = await fn()
      current.value = run
      return run
    } finally {
      busy.value = false
    }
  }

  const start = (req: StartRunRequest) => call(() => api.startRun(req))
  const pause = () => call(() => api.pauseRun(requireId()))
  const resume = () => call(() => api.resumeRun(requireId()))
  const cancel = () => call(() => api.cancelRun(requireId()))
  const replay = (limit = 500) => call(() => api.startRun({ type: 'replay', replayLimit: limit }))

  function requireId(): string {
    const id = current.value?.id
    if (!id) throw new Error('no run')
    return id
  }

  return { current, busy, isActive, isPaused, terminal, progress, remaining, set, start, pause, resume, cancel, replay }
})

if (import.meta.hot) import.meta.hot.accept(acceptHMRUpdate(useRunStore, import.meta.hot))
