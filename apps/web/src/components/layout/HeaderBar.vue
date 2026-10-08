<script setup lang="ts">
import { computed } from 'vue'
import Chip from '@/components/ui/Chip.vue'
import type { ChipTone } from '@/components/ui/Chip.vue'
import { useClock } from '@/composables/useClock'
import { fmtClock, fmtEta, fmtInt, fmtPct, runStatusLabel } from '@/lib/format'
import { useMetricsStore } from '@/stores/metrics'
import { useRunStore } from '@/stores/run'

const props = defineProps<{ connected: boolean }>()
const run = useRunStore()
const metrics = useMetricsStore()
const { now } = useClock()

const statusTone = computed<ChipTone>(() => {
  switch (run.current?.status) {
    case 'running':
    case 'dispatching':
      return 'green'
    case 'paused':
      return 'amber'
    case 'completed_with_failures':
    case 'cancelled':
      return 'red'
    case 'completed':
      return 'cyan'
    default:
      return 'muted'
  }
})
const statusLabel = computed(() => (run.current ? `${runStatusLabel[run.current.status]} · run #${run.current.number}` : 'Idle'))
const progressLabel = computed(() => (run.current ? `${fmtInt(run.terminal)} / ${fmtInt(run.current.totalJobs)} complete` : null))
const eta = computed<number | null>(() => (run.isActive && metrics.jobsPerSec > 0 ? run.remaining / metrics.jobsPerSec : null))
const etaLabel = computed(() => (run.current ? `${fmtPct(run.progress)}${run.isActive ? ` · ETA ${fmtEta(eta.value)}` : ''}` : null))
const workersLabel = computed(() => `${metrics.workers.length} worker${metrics.workers.length === 1 ? '' : 's'}`)
const chaosLabel = computed(() => `Chaos ${fmtPct(metrics.chaosRate)}`)
</script>

<template>
  <header class="flex items-center gap-3.5">
    <div class="flex items-center gap-2.5">
      <span class="grid size-[26px] place-items-center rounded-[7px] bg-tixhub text-sm font-bold text-bg">T</span>
      <span class="text-sm font-bold tracking-[0.12em]">TURNSTILE</span>
    </div>
    <span class="text-xs whitespace-nowrap text-muted">Bulk ticket sync</span>
    <Chip :label="statusLabel" :tone="statusTone" />
    <Chip v-if="progressLabel" :label="progressLabel" tone="cyan" />
    <Chip v-if="etaLabel" :label="etaLabel" tone="muted" />
    <span class="flex-1" />
    <Chip :label="workersLabel" tone="cyan" />
    <Chip :label="props.connected ? 'Mercure connected' : 'Mercure reconnecting'" :tone="props.connected ? 'green' : 'red'" />
    <Chip :label="chaosLabel" :tone="metrics.chaosRate > 0.3 ? 'red' : 'orange'" />
    <span class="text-xs font-medium text-muted tabular-nums">{{ fmtClock(now) }}</span>
  </header>
</template>
