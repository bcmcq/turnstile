import type { JobOutcome, JobRow, RunStatus, RunType } from '@/api/types'

export const fmtInt = (n: number): string => new Intl.NumberFormat('en-US').format(Math.round(n))

export const fmtMoney = (cents: number): string => `$${(cents / 100).toFixed(2)}`

export const fmtPct = (fraction: number): string => `${Math.round(fraction * 100)}%`

export function fmtEta(seconds: number | null): string {
  if (seconds === null || !Number.isFinite(seconds)) return '—'
  if (seconds < 60) return `${Math.ceil(seconds)}s`
  const m = Math.floor(seconds / 60)
  return `${m}m ${Math.ceil(seconds - m * 60)}s`
}

export const fmtClock = (d: Date): string => d.toLocaleTimeString('en-US', { hour12: false })

export const runTypeLabel: Record<RunType, string> = {
  fill: 'Fill',
  transfer: 'Transfer',
  release: 'Release',
  reprice: 'Reprice',
  regenerate: 'Regenerate',
  close_section: 'Close section',
  open_section: 'Open section',
  replay: 'Replay',
}

export const runStatusLabel: Record<RunStatus, string> = {
  pending: 'Pending',
  dispatching: 'Dispatching',
  running: 'Running',
  paused: 'Paused',
  completed: 'Completed',
  completed_with_failures: 'Completed with failures',
  cancelled: 'Cancelled',
}

export const outcomeLabel: Record<JobOutcome, string> = {
  success: 'ok',
  http_429: '429',
  http_5xx: '500',
  timeout: 'timeout',
  lock_conflict: 'conflict',
  idempotent_skip: 'duplicate',
  sold_during_run: 'sold',
  unexpected: 'error',
}

/** "429 ×5", "timeout ×5", "conflict ×2" */
export function jobReason(job: Pick<JobRow, 'lastOutcome' | 'attempts'>): string {
  const label = job.lastOutcome ? outcomeLabel[job.lastOutcome] : 'failed'
  return job.attempts > 1 ? `${label} ×${job.attempts}` : label
}

export const fmtMs = (ms: number | null | undefined): string => (ms === null || ms === undefined ? '—' : ms >= 1000 ? `${(ms / 1000).toFixed(1)} s` : `${fmtInt(ms)} ms`)
