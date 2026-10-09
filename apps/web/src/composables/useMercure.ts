import { onBeforeUnmount, readonly, ref } from 'vue'
import type { LogBatch, MetricsSnapshot, RunTopic, SeatsBatch } from '@/api/types'

export interface MercureHandlers {
  metrics: (snapshot: MetricsSnapshot) => void
  seats: (batch: SeatsBatch) => void
  log: (batch: LogBatch) => void
  run: (update: RunTopic) => void
  /** The stream came back after an outage; seat updates sent meanwhile are gone, so the caller reloads. */
  reconnected: () => void
}

const TOPICS = ['turnstile/metrics', 'turnstile/seats', 'turnstile/log', 'turnstile/run'] as const
const BACKOFF_MS = [1_000, 2_000, 4_000, 8_000] as const

/** One EventSource for all four topics. The publisher names each SSE event after its topic suffix. */
export function useMercure(handlers: MercureHandlers) {
  const connected = ref(false)
  let source: EventSource | null = null
  let attempt = 0
  let reconnectTimer: ReturnType<typeof setTimeout> | null = null

  function url(): string {
    const u = new URL(import.meta.env.VITE_MERCURE_URL)
    for (const t of TOPICS) u.searchParams.append('topic', t)
    return u.toString()
  }

  function listen<T>(name: keyof MercureHandlers, handle: (data: T) => void): void {
    source?.addEventListener(name, (e: MessageEvent<string>) => {
      try {
        handle(JSON.parse(e.data) as T)
      } catch (err) {
        console.error(`bad ${name} payload`, err)
      }
    })
  }

  function connect(): void {
    disconnect()
    source = new EventSource(url())
    source.onopen = () => {
      connected.value = true
      if (attempt > 0) handlers.reconnected()
      attempt = 0
    }
    source.onerror = () => {
      connected.value = false
      source?.close()
      source = null
      const delay = BACKOFF_MS[Math.min(attempt, BACKOFF_MS.length - 1)]
      attempt += 1
      reconnectTimer = setTimeout(connect, delay)
    }
    listen<MetricsSnapshot>('metrics', handlers.metrics)
    listen<SeatsBatch>('seats', handlers.seats)
    listen<LogBatch>('log', handlers.log)
    listen<RunTopic>('run', handlers.run)
  }

  function disconnect(): void {
    if (reconnectTimer) clearTimeout(reconnectTimer)
    reconnectTimer = null
    source?.close()
    source = null
    connected.value = false
  }

  onBeforeUnmount(disconnect)

  return { connected: readonly(connected), connect, disconnect }
}
