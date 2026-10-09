<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { api } from '@/api/client'
import type { JobDetail, JobOutcome } from '@/api/types'
import Button from '@/components/ui/Button.vue'
import { fmtMs, outcomeLabel } from '@/lib/format'
import { useArenaStore } from '@/stores/arena'
import { useToastsStore } from '@/stores/toasts'

/** Open by job id (Failed tab) or by ticket id (seat map, Events tab): the latest job that touched the seat. */
const props = defineProps<{
  jobId?: number
  ticketId?: number
}>()
const emit = defineEmits<{
  close: []
  retried: []
}>()

const arena = useArenaStore()
const toasts = useToastsStore()
const detail = ref<JobDetail | null>(null)
const busy = ref(false)

let alive = true
function onKey(e: KeyboardEvent): void {
  if (e.key === 'Escape') emit('close')
}
onMounted(async () => {
  document.addEventListener('keydown', onKey)
  try {
    if (props.jobId === undefined && props.ticketId === undefined) throw new Error('trace needs a job or a ticket')
    detail.value = props.jobId !== undefined ? await api.job(props.jobId) : await api.ticketJob(props.ticketId ?? 0)
  } catch (e) {
    if (!alive) return
    toasts.fromError(e)
    emit('close')
  }
})
onBeforeUnmount(() => {
  alive = false
  document.removeEventListener('keydown', onKey)
})

const target = computed(() => {
  const id = detail.value?.job.platformId
  return id === null || id === undefined ? 'House' : (arena.platformById.get(id)?.name ?? `platform ${id}`)
})

function gapAfter(index: number): string | null {
  const attempts = detail.value?.attempts ?? []
  const a = attempts[index]
  const next = attempts[index + 1]
  if (!a?.finishedAt || !next) return null
  const ms = new Date(next.startedAt).getTime() - new Date(a.finishedAt).getTime()
  return ms >= 950 ? `+${Math.round(ms / 1000)}s` : `+${Math.round(ms)}ms`
}

/** Backoff gaps plus the final call; attempt timestamps are whole seconds, latency is exact. */
const elapsed = computed(() => {
  const attempts = detail.value?.attempts ?? []
  const first = attempts[0]
  const last = attempts[attempts.length - 1]
  if (!first || !last) return null
  return new Date(last.startedAt).getTime() - new Date(first.startedAt).getTime() + (last.latencyMs ?? 0)
})

const verdict = computed(() => {
  const job = detail.value?.job
  if (!job) return ''
  switch (job.status) {
    case 'completed':
      return 'ticket moved exactly once'
    case 'skipped':
      return job.lastOutcome === 'sold_during_run' ? 'sold mid-run, skipped' : 'already applied, replay was a no-op'
    case 'dead_lettered':
      return 'gave up, ticket untouched'
    case 'cancelled':
      return 'cancelled, ticket untouched'
    default:
      return 'still in progress'
  }
})

const tone: Record<JobOutcome, { dot: string; text: string }> = {
  success: { dot: 'bg-ok', text: 'text-ok' },
  http_429: { dot: 'bg-passmarket', text: 'text-passmarket' },
  timeout: { dot: 'bg-passmarket', text: 'text-passmarket' },
  lock_conflict: { dot: 'bg-passmarket', text: 'text-passmarket' },
  idempotent_skip: { dot: 'bg-tixhub', text: 'text-tixhub' },
  sold_during_run: { dot: 'bg-tixhub', text: 'text-tixhub' },
  http_5xx: { dot: 'bg-fail', text: 'text-fail' },
  unexpected: { dot: 'bg-fail', text: 'text-fail' },
}
const pending = { dot: 'bg-dim', text: 'text-muted' }

const definitions = [
  ['Idempotency', 'Keyed by ticket + run; a replay is a no-op'],
  ['Locking', 'Optimistic version column; conflict → retry, never overwrite'],
  ['Backoff', '1s · 2s · 4s · 8s with jitter, then dead letter'],
] as const

async function retry(): Promise<void> {
  const id = detail.value?.job.id
  if (id === undefined) return
  busy.value = true
  try {
    await api.retryJob(id)
    toasts.push('success', `Job ${id} re-queued with 5 more attempts`)
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
  <div role="dialog" aria-label="Retry trace" class="absolute inset-x-2 bottom-2 z-20 rounded-xl border border-border bg-panel-2 p-4 shadow-[0_12px_30px_rgba(0,0,0,0.5)]">
    <div class="flex items-center justify-between">
      <span class="text-sm font-semibold">Retry trace · {{ detail ? `job ${detail.job.id}` : 'loading…' }}</span>
      <button type="button" class="text-muted hover:text-fg" aria-label="Close" @click="emit('close')">×</button>
    </div>
    <p v-if="detail" class="mt-1 truncate text-xs text-muted">
      TKT-{{ detail.job.ticketId }} → {{ target }}
      <template v-if="detail.idempotencyKey"> · idempotency key <span class="font-mono" :title="detail.idempotencyKey">{{ detail.idempotencyKey }}</span></template>
    </p>

    <div v-if="detail" class="mt-4 flex items-start overflow-x-auto pb-1">
      <template v-for="(a, i) in detail.attempts" :key="a.attemptNo">
        <div class="flex shrink-0 flex-col items-center gap-1.5">
          <span class="size-4 rounded-full" :class="(a.outcome ? tone[a.outcome] : pending).dot" />
          <span class="text-xs font-semibold whitespace-nowrap" :class="(a.outcome ? tone[a.outcome] : pending).text">#{{ a.attemptNo }} · {{ a.outcome ? outcomeLabel[a.outcome] : '…' }}</span>
          <span class="text-[10px] text-muted">{{ [a.httpStatus, fmtMs(a.latencyMs)].filter((s) => s !== null).join(' · ') }}</span>
        </div>
        <div v-if="gapAfter(i)" class="flex min-w-16 flex-1 flex-col items-center gap-1.5 pt-[7px]">
          <span class="h-0.5 w-full rounded bg-dim" />
          <span class="text-[10px] text-muted">{{ gapAfter(i) }}</span>
        </div>
      </template>
    </div>
    <p v-if="detail && elapsed !== null" class="mt-2 text-xs text-fg">{{ fmtMs(elapsed) }} across {{ detail.attempts.length }} {{ detail.attempts.length === 1 ? 'attempt' : 'attempts' }} · {{ verdict }}</p>
    <p v-if="detail?.job.lastError" class="mt-1 truncate text-[10px] text-fail" :title="detail.job.lastError">{{ detail.job.lastError }}</p>

    <dl v-if="detail" class="mt-3 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 border-t border-border pt-3 text-xs">
      <template v-for="[term, text] in definitions" :key="term">
        <dt class="font-semibold text-fg">{{ term }}</dt>
        <dd class="text-muted">{{ text }}</dd>
      </template>
    </dl>

    <div v-if="detail?.job.status === 'dead_lettered'" class="mt-3 flex justify-end">
      <Button variant="primary" size="sm" :busy="busy" @click="retry">Retry job</Button>
    </div>
  </div>
</template>
