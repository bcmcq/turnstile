/**
 * Mirrors the PHP response DTOs one to one (apps/api/src/Application/**\/Dto). Change both sides together.
 */

export type PlatformCode = 'tixhub' | 'seatswap' | 'passmarket'
export type SectionTier = 'floor' | 'lower' | 'upper'
export type SectionStatus = 'open' | 'closed'
export type RunType = 'fill' | 'transfer' | 'release' | 'reprice' | 'regenerate' | 'close_section' | 'open_section' | 'replay'
export type RunStatus = 'pending' | 'dispatching' | 'running' | 'paused' | 'completed' | 'completed_with_failures' | 'cancelled'
export type JobStatus = 'queued' | 'in_flight' | 'completed' | 'skipped' | 'retry_wait' | 'dead_lettered' | 'cancelled'
export type JobOutcome = 'success' | 'http_429' | 'http_5xx' | 'timeout' | 'lock_conflict' | 'idempotent_skip' | 'sold_during_run' | 'unexpected'
export type WorkerState = 'idle' | 'busy' | 'backoff'

/** tickets.status as a compact code in the seats payload and seat batches. */
export const TicketState = { Available: 0, Listed: 1, Sold: 2, Closed: 3 } as const
export type TicketStateCode = (typeof TicketState)[keyof typeof TicketState]

export interface SectionGeometry {
  angleStart: number
  angleEnd: number
  ringStart: number
  ringEnd: number
  labelX: number
  labelY: number
}

export interface SectionView {
  id: number
  code: string
  tier: SectionTier
  status: SectionStatus
  seatCount: number
  geometry: SectionGeometry
}

export interface PlatformView {
  id: number
  code: PlatformCode
  name: string
  color: string
  rateLimitPerMin: number
  clientPaceRatio: number
  feeBps: number
  failureRate: number
  buyerRate: number
}

export interface ArenaBootstrap {
  venueName: string
  eventId: number
  eventName: string
  arenaWidth: number
  arenaHeight: number
  seatPitch: number
  seatCount: number
  sections: SectionView[]
  platforms: PlatformView[]
  ticketCounts: Partial<Record<'available' | 'listed' | 'sold' | 'closed', number>>
  listedByPlatform: Partial<Record<PlatformCode, number>>
  currentRunId: string | null
  autoscaleEnabled: boolean
}

/** [ticketId, sectionId, x (tenths), y (tenths), state, platformId, row, seat, priceCents] */
export type SeatRow = [number, number, number, number, TicketStateCode, number, string, number, number]

export interface SeatsPayload {
  columns: string[]
  rows: SeatRow[]
}

/** [ticketId, sectionId, state, platformId, priceCents] */
export type SeatUpdate = [number, number, TicketStateCode, number, number]

/** Per-seat job lifecycle carried on the seats topic (last per ticket per tick); mirrors Publisher::JOB_STATE. */
export const JobState = { Started: 1, RetryWait: 2, Conflict: 3, DeadLettered: 4, Completed: 5, Skipped: 6, Requeued: 7 } as const
export type JobStateCode = (typeof JobState)[keyof typeof JobState]
/** [ticketId, jobState, delayMs] */
export type JobStateUpdate = [number, JobStateCode, number]

export interface SeatsBatch {
  rows: SeatUpdate[]
  jobs: JobStateUpdate[]
}

export interface RunCounters {
  queued: number
  in_flight: number
  retry_wait: number
  completed: number
  skipped: number
  dead_lettered: number
  cancelled: number
  conflicts: number
  sold_during_run: number
  retries: number
  webhook_conflicts: number
}

export interface RunSelection {
  sections: number[]
  tickets: number[]
}

export interface RunView {
  id: string
  number: number
  type: RunType
  status: RunStatus
  targetPlatform: PlatformCode | null
  selection: RunSelection
  params: Record<string, unknown>
  replayOfId: string | null
  totalJobs: number
  counters: RunCounters
  startedAt: string | null
  pausedAt: string | null
  finishedAt: string | null
  createdAt: string
}

export type RunTopic = RunView | { reset: true }

export interface WorkerView {
  id: string
  name: string
  state: WorkerState
  jobId: number | null
  ticketId: number | null
  platform: PlatformCode | 'house' | null
  lastLatencyMs: number | null
  jobsPerMin: number
  startedAt: number
}

export interface PlatformGauge {
  code: PlatformCode
  name: string
  color: string
  remainingTokens: number
  capacity: number
  tokensPerSec: number
  rateLimitPerMin: number
  calls: number
  ok: number
  http429: number
  http5xx: number
  timeouts: number
  rejected: number
  failureRate: number
  buyerRate: number
  listed: number
}

export type SeriesName = 'jobsPerSec' | 'eventsPerSec' | 'queued' | 'inFlight' | 'failed'

export interface AutoscaleState {
  enabled: boolean
  min: number
  max: number
  lastDecision: string | null
}

export interface MetricsSnapshot {
  ts: number
  run: RunView | null
  jobsPerSec: number
  eventsPerSec: number
  counts: Record<JobStatus, number>
  series: Partial<Record<SeriesName, number[]>>
  platforms: PlatformGauge[]
  workers: WorkerView[]
  autoscale: AutoscaleState
  /** false = workers keep pacing to the default limit and the vendor's 429s drive backoff */
  paceToVendorLimit: boolean
}

export interface LogEvent {
  type: string
  ts: number
  [key: string]: unknown
}

export interface LogBatch {
  events: LogEvent[]
}

export interface JobRow {
  id: number
  runId: string
  ticketId: number
  platformId: number | null
  status: JobStatus
  attempts: number
  maxAttempts: number
  lastOutcome: JobOutcome | null
  lastError: string | null
  workerId: string | null
  durationMs: number | null
}

export interface JobAttempt {
  attemptNo: number
  workerId: string
  outcome: JobOutcome | null
  httpStatus: number | null
  latencyMs: number | null
  retryAfterMs: number | null
  error: string | null
  startedAt: string
  finishedAt: string | null
}

export interface JobDetail {
  job: JobRow
  attempts: JobAttempt[]
  /** null until a worker has run it */
  idempotencyKey: string | null
}

export interface RepriceInput {
  mode: 'percent' | 'absolute'
  delta: number
  floorCents?: number | null
  ceilingCents?: number | null
}

export interface StartRunRequest {
  type: RunType
  selection?: RunSelection
  targetPlatform?: PlatformCode | null
  reprice?: RepriceInput | null
  replayLimit?: number
}

export interface ApiProblem {
  error: string
  status: number
  details?: string[]
}

export interface ScaleResult {
  target: number
  before: number
  after: number
  containers: string[]
}

export interface WorkersStatus {
  workers: WorkerView[]
  queueDepth: number
  autoscale: AutoscaleState
}
