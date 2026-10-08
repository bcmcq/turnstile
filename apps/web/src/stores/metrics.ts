import { defineStore } from 'pinia'
import { computed, shallowRef } from 'vue'
import type { MetricsSnapshot, PlatformGauge, SeriesName, WorkerView } from '@/api/types'

const EMPTY_SERIES: Record<SeriesName, number[]> = { jobsPerSec: [], eventsPerSec: [], queued: [], inFlight: [], failed: [] }

export const useMetricsStore = defineStore('metrics', () => {
  const snapshot = shallowRef<MetricsSnapshot | null>(null)

  const jobsPerSec = computed(() => snapshot.value?.jobsPerSec ?? 0)
  const eventsPerSec = computed(() => snapshot.value?.eventsPerSec ?? 0)
  const counts = computed(() => snapshot.value?.counts ?? null)
  const workers = computed<WorkerView[]>(() => snapshot.value?.workers ?? [])
  const platforms = computed<PlatformGauge[]>(() => snapshot.value?.platforms ?? [])
  const series = computed<Record<SeriesName, number[]>>(() => ({ ...EMPTY_SERIES, ...(snapshot.value?.series ?? {}) }))
  const autoscale = computed(() => snapshot.value?.autoscale ?? { enabled: false, min: 1, max: 16, lastDecision: null })
  const chaosRate = computed(() => Math.max(0, ...platforms.value.map((p) => p.failureRate)))
  const buyersOn = computed(() => platforms.value.some((p) => p.buyerRate > 0))

  function set(next: MetricsSnapshot): void {
    snapshot.value = next
  }

  return { snapshot, jobsPerSec, eventsPerSec, counts, workers, platforms, series, autoscale, chaosRate, buyersOn, set }
})
