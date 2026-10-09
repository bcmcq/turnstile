import type {
  ApiProblem,
  ArenaBootstrap,
  JobDetail,
  JobRow,
  JobStatus,
  MetricsSnapshot,
  PlatformCode,
  RunView,
  ScaleResult,
  SeatsPayload,
  StartRunRequest,
  WorkersStatus,
} from './types'

export class ApiError extends Error {
  readonly status: number
  readonly details: string[]

  constructor(status: number, message: string, details: string[] = []) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.details = details
  }
}

const BASE = import.meta.env.VITE_API_URL
// Scale, autoscale and reset need it; sending it on every call keeps the client dumb about which ones.
const ADMIN = import.meta.env.VITE_ADMIN_TOKEN

async function request<T>(method: 'GET' | 'POST' | 'PUT', path: string, body?: unknown): Promise<T> {
  const res = await fetch(`${BASE}${path}`, {
    method,
    headers: { ...(body === undefined ? {} : { 'Content-Type': 'application/json' }), ...(ADMIN ? { 'X-Admin-Token': ADMIN } : {}) },
    body: body === undefined ? undefined : JSON.stringify(body),
  })
  if (!res.ok) {
    let problem: ApiProblem = { error: `HTTP ${res.status}`, status: res.status }
    try {
      problem = (await res.json()) as ApiProblem
    } catch {
      // body was not JSON; keep the generic message
    }
    throw new ApiError(res.status, problem.error, problem.details ?? [])
  }
  return (await res.json()) as T
}

export const api = {
  bootstrap: () => request<ArenaBootstrap>('GET', '/api/bootstrap'),
  seats: () => request<SeatsPayload>('GET', '/api/seats'),
  metrics: () => request<MetricsSnapshot>('GET', '/api/metrics'),
  currentRun: () => request<RunView | null>('GET', '/api/runs/current'),

  startRun: (body: StartRunRequest) => request<RunView>('POST', '/api/runs', body),
  pauseRun: (id: string) => request<RunView>('POST', `/api/runs/${id}/pause`),
  resumeRun: (id: string) => request<RunView>('POST', `/api/runs/${id}/resume`),
  cancelRun: (id: string) => request<RunView>('POST', `/api/runs/${id}/cancel`),
  retryFailed: (id: string) => request<{ requeued: number }>('POST', `/api/runs/${id}/retry-failed`),
  runJobs: (id: string, status?: JobStatus, limit = 50, offset = 0) => {
    const q = new URLSearchParams({ limit: String(limit), offset: String(offset) })
    if (status) q.set('status', status)
    return request<JobRow[]>('GET', `/api/runs/${id}/jobs?${q}`)
  },

  job: (id: number) => request<JobDetail>('GET', `/api/jobs/${id}`),
  ticketJob: (ticketId: number) => request<JobDetail>('GET', `/api/tickets/${ticketId}/job`),
  retryJob: (id: number) => request<{ requeued: number }>('POST', `/api/jobs/${id}/retry`),

  setChaos: (platform: PlatformCode | 'all', failureRate: number) =>
    request<{ failureRate: number }>('PUT', `/api/platforms/${platform}/chaos`, { failureRate }),
  setRateLimit: (platform: PlatformCode | 'all', rpm: number) => request<{ rpm: number }>('PUT', `/api/platforms/${platform}/rate-limit`, { rpm }),
  setPacing: (follow: boolean) => request<{ follow: boolean }>('PUT', '/api/platforms/pacing', { follow }),
  setBuyers: (platform: PlatformCode | 'all', buyerRate: number) =>
    request<{ buyerRate: number }>('PUT', `/api/platforms/${platform}/buyers`, { buyerRate }),

  resetDemo: () => request<{ seats: number }>('POST', '/api/demo/reset'),

  workers: () => request<WorkersStatus>('GET', '/api/workers'),
  scaleWorkers: (workers: number) => request<ScaleResult>('POST', '/api/workers/scale', { workers }),
  setAutoscale: (enabled: boolean) => request<{ enabled: boolean }>('PUT', '/api/workers/autoscale', { enabled }),
}
