<script setup lang="ts">
import { computed } from 'vue'
import MetricTile from '@/components/metrics/MetricTile.vue'
import { COLORS } from '@/lib/arenaPalette'
import { fmtInt } from '@/lib/format'
import { useMetricsStore } from '@/stores/metrics'
import { useRunStore } from '@/stores/run'

const metrics = useMetricsStore()
const run = useRunStore()

const jobsPeak = computed(() => Math.max(0, ...metrics.series.jobsPerSec))
const queued = computed(() => (metrics.counts?.queued ?? 0) + (metrics.counts?.retry_wait ?? 0))
const queuedTrend = computed(() => {
  const s = metrics.series.queued
  if (s.length < 6) return null
  const d = s[s.length - 1] - s[s.length - 6]
  return d < 0 ? 'draining' : d > 0 ? 'growing' : 'steady'
})
const retryWait = computed(() => metrics.counts?.retry_wait ?? 0)
const failed = computed(() => metrics.counts?.dead_lettered ?? 0)
const conflicts = computed(() => run.current?.counters.conflicts ?? 0)
</script>

<template>
  <div class="grid grid-cols-2 gap-3">
    <MetricTile label="Jobs / sec" :value="metrics.jobsPerSec.toFixed(metrics.jobsPerSec >= 10 ? 0 : 1)" :sub="jobsPeak > 0 ? `peak ${jobsPeak.toFixed(0)}` : undefined" sub-class="text-ok" :series="metrics.series.jobsPerSec" :color="COLORS.cyan" />
    <MetricTile label="In flight" :value="fmtInt(metrics.counts?.in_flight ?? 0)" :sub="`${metrics.workers.length} worker${metrics.workers.length === 1 ? '' : 's'}`" :series="metrics.series.inFlight" :color="COLORS.inflight" />
    <MetricTile label="Queued" :value="fmtInt(queued)" :sub="retryWait > 0 ? `${fmtInt(retryWait)} in backoff` : (queuedTrend ?? undefined)" :sub-class="retryWait > 0 ? 'text-inflight' : 'text-muted'" :series="metrics.series.queued" :color="COLORS.violet" />
    <MetricTile label="Failed" :value="fmtInt(failed)" :sub="failed > 0 ? `${fmtInt(failed)} in DLQ` : conflicts > 0 ? `${fmtInt(conflicts)} conflicts` : undefined" :sub-class="failed > 0 ? 'text-fail' : 'text-passmarket'" :series="metrics.series.failed" :color="COLORS.fail" />
  </div>
</template>
