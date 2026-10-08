import type { LogEvent } from '@/api/types'
import { fmtInt, fmtMoney, fmtMs, outcomeLabel } from '@/lib/format'
import type { JobOutcome } from '@/api/types'

export type EventTone = 'ok' | 'warn' | 'fail' | 'info' | 'muted' | 'cyan'

export interface EventLine {
  text: string
  tone: EventTone
  ticketId: number | null
  platform: string | null
  attempt: string | null
}

const num = (v: unknown): number | null => (typeof v === 'number' ? v : null)
const str = (v: unknown): string | null => (typeof v === 'string' ? v : null)

/** Human line for one stream event, matching the vocabulary of the Figma Events tab. */
export function describeEvent(e: LogEvent): EventLine {
  const ticketId = num(e.ticketId)
  const platform = str(e.platform)
  const attempt = num(e.attempt)
  const attemptLabel = attempt === null ? null : `${attempt} / 5`
  const outcome = str(e.outcome) as JobOutcome | null
  switch (e.type) {
    case 'job.started':
      return { text: 'started', tone: 'muted', ticketId, platform, attempt: attemptLabel }
    case 'job.completed':
      return { text: `completed · ${fmtMs(num(e.latencyMs))}`, tone: 'ok', ticketId, platform, attempt: attemptLabel }
    case 'job.retry': {
      const reason = outcome === 'http_429' ? '429 rate limited' : outcome === 'timeout' ? 'timeout' : outcome === 'lock_conflict' ? 'version conflict · another writer holds the ticket' : outcome === 'http_5xx' ? '500 upstream error' : (outcome ? outcomeLabel[outcome] : 'error')
      const delay = num(e.delayMs)
      return { text: `${reason} · backoff ${delay === null ? '' : `${(delay / 1000).toFixed(1)}s`}`, tone: outcome === 'lock_conflict' ? 'warn' : outcome === 'http_5xx' ? 'fail' : 'warn', ticketId, platform, attempt: attemptLabel }
    }
    case 'job.skipped':
      return { text: outcome === 'idempotent_skip' ? 'skipped · already applied (idempotency key)' : outcome === 'sold_during_run' ? 'skipped · sold during run' : `skipped · ${str(e.reason) ?? ''}`, tone: 'cyan', ticketId, platform, attempt: attemptLabel }
    case 'job.dead_lettered':
      return { text: `moved to dead letter after ${num(e.attempts) ?? '?'} attempts`, tone: 'fail', ticketId, platform, attempt: null }
    case 'job.requeued':
      return { text: 're-queued with 5 more attempts', tone: 'info', ticketId, platform, attempt: null }
    case 'webhook.received':
      return { text: `webhook ${str(e.event) ?? ''} · ${num(e.soldPriceCents) === null ? '' : fmtMoney(num(e.soldPriceCents) ?? 0)}${e.conflictRetried ? ' · conflict retried' : ''}`, tone: 'cyan', ticketId, platform, attempt: null }
    case 'run.created':
    case 'run.dispatching':
    case 'run.started':
    case 'run.paused':
    case 'run.resumed':
    case 'run.cancelled':
      return { text: `run #${num(e.number) ?? '?'} ${e.type.slice(4)}${e.type === 'run.started' ? ` · ${fmtInt(num(e.totalJobs) ?? 0)} jobs` : ''}`, tone: 'info', ticketId: null, platform: null, attempt: null }
    case 'run.finished':
      return { text: `run #${num(e.number) ?? '?'} ${String(e.status ?? '').replace(/_/g, ' ')} · ${fmtInt(num(e.completed) ?? 0)} done · ${fmtInt(num(e.skipped) ?? 0)} skipped · ${fmtInt(num(e.deadLettered) ?? 0)} dead-lettered`, tone: num(e.deadLettered) ? 'warn' : 'ok', ticketId: null, platform: null, attempt: null }
    case 'worker.joined':
      return { text: `worker ${str(e.worker) ?? ''} joined`, tone: 'info', ticketId: null, platform: null, attempt: null }
    case 'worker.left':
      return { text: `worker ${str(e.worker) ?? ''} left`, tone: 'muted', ticketId: null, platform: null, attempt: null }
    case 'autoscale.decision':
      return { text: `autoscale ${num(e.from) ?? '?'} → ${num(e.to) ?? '?'} · ${str(e.reason) ?? ''}`, tone: 'info', ticketId: null, platform: null, attempt: null }
    case 'demo.reset':
      return { text: `arena reseeded · ${fmtInt(num(e.seats) ?? 0)} seats`, tone: 'info', ticketId: null, platform: null, attempt: null }
    case 'section.updated':
      return { text: `section ${num(e.sectionId) ?? ''} ${str(e.status) ?? ''}`, tone: 'info', ticketId: null, platform: null, attempt: null }
    default:
      return { text: e.type, tone: 'muted', ticketId, platform, attempt: null }
  }
}

export const toneClass: Record<EventTone, string> = {
  ok: 'text-ok',
  warn: 'text-passmarket',
  fail: 'text-fail',
  info: 'text-fg',
  muted: 'text-muted',
  cyan: 'text-tixhub',
}

export const fmtEventTime = (ts: number): string => {
  const d = new Date(ts)
  return `${d.toLocaleTimeString('en-US', { hour12: false })}.${String(d.getMilliseconds()).padStart(3, '0')}`
}
