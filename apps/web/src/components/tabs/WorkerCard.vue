<script setup lang="ts">
import { computed } from 'vue'
import type { WorkerView } from '@/api/types'
import { fmtMs } from '@/lib/format'
import { useArenaStore } from '@/stores/arena'

const props = defineProps<{
  worker: WorkerView
  maxJobsPerMin: number
}>()

const arena = useArenaStore()

const platform = computed(() => (props.worker.platform && props.worker.platform !== 'house' ? arena.platformByCode.get(props.worker.platform) : null))
const dotClass = computed(() => ({ idle: 'bg-muted', busy: 'bg-ok', backoff: 'bg-passmarket' })[props.worker.state])
const statusText = computed(() => {
  if (props.worker.state === 'backoff') return 'backoff'
  if (props.worker.state === 'busy' && props.worker.jobId) return fmtMs(props.worker.lastLatencyMs)
  return 'idle'
})
const fraction = computed(() => (props.maxJobsPerMin > 0 ? props.worker.jobsPerMin / props.maxJobsPerMin : 0))
</script>

<template>
  <article class="flex flex-col gap-1.5 rounded-lg bg-panel-2 px-2.5 py-2">
    <div class="flex items-center gap-1.5 text-[11px]">
      <span class="size-1.5 rounded-full" :class="[dotClass, worker.state === 'busy' ? 'animate-pulse' : '']" />
      <span class="font-semibold">{{ worker.name }}</span>
      <span class="ml-auto font-medium" :class="worker.state === 'backoff' ? 'text-passmarket' : 'text-muted'">{{ statusText }}</span>
    </div>
    <div class="flex items-center gap-1 text-[11px]">
      <span v-if="worker.ticketId" class="text-muted tabular-nums">#{{ worker.ticketId }}</span>
      <span v-else class="text-muted">waiting for work</span>
      <span v-if="platform" class="font-medium" :style="{ color: platform.color }">→ {{ platform.name }}</span>
      <span v-else-if="worker.platform === 'house'" class="text-muted">→ house</span>
    </div>
    <div class="flex items-center gap-2">
      <div class="h-1 flex-1 overflow-hidden rounded-full bg-dim">
        <div class="h-full rounded-full bg-ok transition-[width] duration-500" :style="{ width: `${Math.max(2, fraction * 100)}%` }" />
      </div>
      <span class="w-14 text-right text-[10px] text-muted tabular-nums">{{ worker.jobsPerMin }} / min</span>
    </div>
  </article>
</template>
