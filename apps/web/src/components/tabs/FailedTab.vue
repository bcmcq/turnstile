<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { api } from '@/api/client'
import type { JobRow } from '@/api/types'
import JobTracePopover from '@/components/tabs/JobTracePopover.vue'
import Button from '@/components/ui/Button.vue'
import Chip from '@/components/ui/Chip.vue'
import type { ChipTone } from '@/components/ui/Chip.vue'
import { platformTone } from '@/lib/platformTone'
import { jobReason } from '@/lib/format'
import { useArenaStore } from '@/stores/arena'
import { useRunStore } from '@/stores/run'
import { useToastsStore } from '@/stores/toasts'

const arena = useArenaStore()
const run = useRunStore()
const toasts = useToastsStore()
const jobs = ref<JobRow[]>([])
const loading = ref(false)
const traceId = ref<number | null>(null)
const retrying = ref<number | null>(null)

const deadLettered = computed(() => run.current?.counters.dead_lettered ?? 0)

async function load(): Promise<void> {
  const id = run.current?.id
  if (!id) {
    jobs.value = []
    return
  }
  loading.value = true
  try {
    jobs.value = await api.runJobs(id, 'dead_lettered', 100)
    if (traceId.value !== null && !jobs.value.some((j) => j.id === traceId.value)) traceId.value = null // its job was re-queued
  } catch (e) {
    toasts.fromError(e)
  } finally {
    loading.value = false
  }
}

let timer: ReturnType<typeof setTimeout> | null = null
watch([deadLettered, () => run.current?.id], () => {
  if (timer) clearTimeout(timer)
  timer = setTimeout(load, 600) // counters tick fast under chaos; coalesce
})
onMounted(load)
onBeforeUnmount(() => {
  if (timer) clearTimeout(timer)
})

const toneOf = (id: number | null): ChipTone => platformTone(id === null ? null : arena.platformById.get(id)?.code)
function platformName(id: number | null): string {
  return id === null ? 'house' : (arena.platformById.get(id)?.name ?? '?')
}

async function retryOne(job: JobRow): Promise<void> {
  retrying.value = job.id
  try {
    await api.retryJob(job.id)
    jobs.value = jobs.value.filter((j) => j.id !== job.id)
  } catch (e) {
    toasts.fromError(e)
  } finally {
    retrying.value = null
  }
}

defineExpose({ reload: load })
</script>

<template>
  <div class="relative flex min-h-0 flex-1 flex-col">
    <p v-if="!run.current" class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-[11px] text-muted">No run yet.</p>
    <p v-else-if="jobs.length === 0" class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-[11px] text-muted">
      {{ loading ? 'Loading…' : `No dead letters in run #${run.current.number}. Raise chaos to see some.` }}
    </p>
    <ul v-else class="min-h-0 flex-1 divide-y divide-border overflow-y-auto">
      <li v-for="job in jobs" :key="job.id" class="flex items-center gap-2.5 py-1.5 text-[11px]">
        <button type="button" class="w-[76px] text-left font-medium hover:text-tixhub" :title="`Attempt trace for job ${job.id}`" @click="traceId = job.id">TKT-{{ job.ticketId }}</button>
        <Chip :label="platformName(job.platformId)" :tone="toneOf(job.platformId)" />
        <span class="flex-1 truncate text-muted" :title="job.lastError ?? ''">{{ jobReason(job) }}</span>
        <Button size="sm" :busy="retrying === job.id" @click="retryOne(job)">Retry</Button>
      </li>
    </ul>
    <p v-if="jobs.length" class="mt-2 text-[10px] text-muted">Retry re-queues with five more attempts · the idempotency key is preserved, so a half-applied attempt cannot double-apply</p>
    <JobTracePopover v-if="traceId !== null" :job-id="traceId" @close="traceId = null" @retried="load" />
  </div>
</template>
