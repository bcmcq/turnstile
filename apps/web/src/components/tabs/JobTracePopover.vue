<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { JobDetail } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import { fmtMs, outcomeLabel } from '@/lib/format'
import { useToastsStore } from '@/stores/toasts'

const props = defineProps<{
  jobId: number
}>()
const emit = defineEmits<{
  close: []
  retried: []
}>()

const toasts = useToastsStore()
const detail = ref<JobDetail | null>(null)
const busy = ref(false)

onMounted(async () => {
  try {
    detail.value = await api.job(props.jobId)
  } catch (e) {
    toasts.fromError(e)
    emit('close')
  }
})

function delayAfter(index: number): string | null {
  const attempts = detail.value?.attempts ?? []
  const a = attempts[index]
  const next = attempts[index + 1]
  if (!a?.finishedAt || !next) return null
  const ms = new Date(next.startedAt).getTime() - new Date(a.finishedAt).getTime()
  return `+${(ms / 1000).toFixed(1)}s`
}

function colorFor(outcome: string | null): string {
  switch (outcome) {
    case 'success':
      return 'bg-ok text-ok'
    case 'http_429':
    case 'timeout':
      return 'bg-passmarket text-passmarket'
    case 'lock_conflict':
      return 'bg-passmarket text-passmarket'
    case 'idempotent_skip':
    case 'sold_during_run':
      return 'bg-tixhub text-tixhub'
    default:
      return 'bg-fail text-fail'
  }
}

async function retry(): Promise<void> {
  busy.value = true
  try {
    await api.retryJob(props.jobId)
    toasts.push('success', `Job ${props.jobId} re-queued with 5 more attempts`)
    emit('retried')
    emit('close')
  } catch (e) {
    toasts.fromError(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="absolute inset-x-2 bottom-2 z-20 rounded-xl border border-border bg-panel-2 p-3 shadow-[0_12px_30px_rgba(0,0,0,0.5)]">
    <div class="flex items-center justify-between">
      <span class="text-xs font-semibold">Retry trace · job {{ jobId }}</span>
      <button type="button" class="text-muted hover:text-fg" aria-label="Close" @click="emit('close')">×</button>
    </div>
    <p v-if="detail" class="mt-0.5 text-[11px] text-muted">
      ticket #{{ detail.job.ticketId }} · {{ detail.job.attempts }} / {{ detail.job.maxAttempts }} attempts · {{ detail.job.status.replace('_', ' ') }}
    </p>
    <div v-if="detail" class="mt-3 flex items-start gap-0 overflow-x-auto pb-1">
      <template v-for="(a, i) in detail.attempts" :key="a.attemptNo">
        <div class="flex shrink-0 flex-col items-center gap-1">
          <span class="size-3 rounded-full" :class="colorFor(a.outcome).split(' ')[0]" />
          <span class="text-[10px] font-semibold whitespace-nowrap" :class="colorFor(a.outcome).split(' ')[1]">#{{ a.attemptNo }} · {{ a.outcome ? outcomeLabel[a.outcome] : '…' }}</span>
          <span class="text-[9px] text-muted">{{ a.httpStatus ?? '' }} {{ fmtMs(a.latencyMs) }}</span>
        </div>
        <div v-if="delayAfter(i)" class="flex shrink-0 flex-col items-center gap-1 pt-1.5">
          <span class="h-0.5 w-10 rounded bg-dim" />
          <span class="text-[9px] text-muted">{{ delayAfter(i) }}</span>
        </div>
      </template>
    </div>
    <p v-if="detail?.job.lastError" class="mt-2 truncate text-[10px] text-fail" :title="detail.job.lastError">{{ detail.job.lastError }}</p>
    <div v-if="detail?.job.status === 'dead_lettered'" class="mt-3 flex justify-end">
      <Button variant="primary" size="sm" :busy="busy" @click="retry">Retry job</Button>
    </div>
  </div>
</template>
