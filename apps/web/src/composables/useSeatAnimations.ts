import { gsap } from 'gsap'
import { onBeforeUnmount, onMounted } from 'vue'
import type { LogEvent, RunView, SeatUpdate, TicketStateCode } from '@/api/types'
import type { ArenaView } from '@/composables/useArenaCanvas'
import { COLORS, SeatPalette } from '@/lib/arenaPalette'
import { liveBus } from '@/lib/liveBus'
import type { SeatTable } from '@/lib/seatTable'

/** Transient job state painted over the base seat color. */
const Overlay = { None: 0, InFlight: 1, RetryWait: 2, Conflict: 3, Failed: 4 } as const
type OverlayKind = (typeof Overlay)[keyof typeof Overlay]

interface Pulse {
  start: number
  duration: number
  color: string
  kind: 'pop' | 'flicker'
}

interface Ripple {
  start: number
  duration: number
  cx: number
  cy: number
  maxDist: number
  seats: Int32Array
}

export interface SeatAnimationOptions {
  seats: () => SeatTable | null
  palette: () => SeatPalette
  markSeatsDirty: (indices: Iterable<number>) => void
  requestFrame: () => void
  arenaWidth: number
  arenaHeight: number
}

const POP_MS = 320
const FLICKER_MS = 400
const RIPPLE_MS = 700
const RIPPLE_SEAT_CAP = 25_000
const ease = gsap.parseEase('power2.out')

/**
 * Keeps per-seat overlay state (in flight, retry wait, conflict, failed) and short-lived pulses, and
 * draws them on the arena's overlay layer. One GSAP ticker drives frames only while something moves.
 */
export function useSeatAnimations(opts: SeatAnimationOptions) {
  let overlay = new Uint8Array(0)
  const overlaid = new Set<number>()
  const pulses = new Map<number, Pulse>()
  let ripple: Ripple | null = null
  let ticking = false
  const unsubscribe: Array<() => void> = []

  function ensureArrays(): SeatTable | null {
    const seats = opts.seats()
    if (seats && overlay.length !== seats.size) {
      overlay = new Uint8Array(seats.size)
      overlaid.clear()
      pulses.clear()
    }
    return seats
  }

  function setOverlay(i: number, kind: OverlayKind): void {
    overlay[i] = kind
    if (kind === Overlay.None) overlaid.delete(i)
    else overlaid.add(i)
  }

  function finalColor(seats: SeatTable, i: number): string {
    const p = opts.palette()
    return p.colors[p.slot(seats.state[i], seats.platformId[i])]
  }

  function startTicker(): void {
    if (ticking) return
    ticking = true
    gsap.ticker.add(tick)
  }

  function tick(): void {
    const now = performance.now()
    for (const [i, p] of pulses) if (now - p.start > p.duration) pulses.delete(i)
    if (ripple && now - ripple.start > ripple.duration) ripple = null
    opts.requestFrame()
    if (pulses.size === 0 && !ripple) {
      gsap.ticker.remove(tick)
      ticking = false
    }
  }

  // --- inputs ---------------------------------------------------------------------------------------
  function onSeats(rows: SeatUpdate[]): void {
    const seats = ensureArrays()
    if (!seats) return
    const dirty: number[] = []
    const now = performance.now()
    for (const [ticketId, , state, platformId, priceCents] of rows) {
      const i = seats.update(ticketId, state as TicketStateCode, platformId, priceCents)
      if (i === undefined) continue
      dirty.push(i)
      setOverlay(i, Overlay.None)
      pulses.set(i, { start: now, duration: POP_MS, color: finalColor(seats, i), kind: 'pop' })
    }
    if (dirty.length) {
      opts.markSeatsDirty(dirty)
      startTicker()
    }
  }

  function onLog(events: LogEvent[]): void {
    const seats = ensureArrays()
    if (!seats) return
    const now = performance.now()
    let changed = false
    for (const e of events) {
      const ticketId = typeof e.ticketId === 'number' ? e.ticketId : null
      if (ticketId === null) continue
      const i = seats.indexOf(ticketId)
      if (i === undefined) continue
      switch (e.type) {
        case 'job.started':
          setOverlay(i, Overlay.InFlight)
          changed = true
          break
        case 'job.retry':
          if (e.outcome === 'lock_conflict') {
            setOverlay(i, Overlay.Conflict)
            pulses.set(i, { start: now, duration: FLICKER_MS, color: COLORS.amber, kind: 'flicker' })
          } else {
            setOverlay(i, Overlay.RetryWait)
          }
          changed = true
          break
        case 'job.dead_lettered':
          setOverlay(i, Overlay.Failed)
          changed = true
          break
        case 'job.completed':
        case 'job.skipped':
        case 'job.requeued':
          // The seats batch of the same tick already painted the final color; the log batch arrives after it.
          setOverlay(i, Overlay.None)
          changed = true
          break
        default:
          break
      }
    }
    if (changed) {
      opts.requestFrame()
      if (pulses.size) startTicker()
    }
  }

  /** Fan-out: flash the run's selection outward from the floor over RIPPLE_MS. */
  function onRunStarted(run: RunView): void {
    const seats = ensureArrays()
    if (!seats || run.totalJobs === 0) return
    const sections = new Set(run.selection.sections)
    const tickets = new Set(run.selection.tickets)
    const picked: number[] = []
    for (let i = 0; i < seats.size && picked.length < RIPPLE_SEAT_CAP; i++) {
      if (sections.has(seats.sectionId[i]) || tickets.has(seats.ticketId[i])) picked.push(i)
    }
    const cx = opts.arenaWidth / 2
    const cy = opts.arenaHeight / 2
    let maxDist = 1
    for (const i of picked) maxDist = Math.max(maxDist, Math.hypot(seats.x[i] - cx, seats.y[i] - cy))
    ripple = { start: performance.now(), duration: RIPPLE_MS, cx, cy, maxDist, seats: Int32Array.from(picked) }
    startTicker()
  }

  function reset(): void {
    overlay = new Uint8Array(0)
    overlaid.clear()
    pulses.clear()
    ripple = null
    opts.requestFrame()
  }

  // --- drawing --------------------------------------------------------------------------------------
  function draw(ctx: CanvasRenderingContext2D, view: ArenaView, seatPx: number): void {
    const seats = ensureArrays()
    if (!seats) return
    const now = performance.now()
    const s = Math.max(2, seatPx)
    const half = s / 2
    const sx = (i: number) => seats.x[i] * view.scale + view.offsetX
    const sy = (i: number) => seats.y[i] * view.scale + view.offsetY

    if (ripple) {
      const t = Math.min(1, (now - ripple.start) / ripple.duration)
      const reach = ease(t) * ripple.maxDist * view.scale
      const alpha = 0.55 * (1 - t)
      ctx.fillStyle = COLORS.cyan
      ctx.globalAlpha = alpha
      const ocx = ripple.cx * view.scale + view.offsetX
      const ocy = ripple.cy * view.scale + view.offsetY
      for (const i of ripple.seats) {
        const x = sx(i)
        const y = sy(i)
        if (Math.hypot(x - ocx, y - ocy) <= reach) ctx.fillRect(x - half, y - half, s, s)
      }
      ctx.globalAlpha = 1
      ctx.strokeStyle = COLORS.cyan
      ctx.lineWidth = 1.5
      ctx.globalAlpha = 0.5 * (1 - t)
      ctx.beginPath()
      ctx.arc(ocx, ocy, reach, 0, Math.PI * 2)
      ctx.stroke()
      ctx.globalAlpha = 1
    }

    for (const i of overlaid) {
      const x = sx(i)
      const y = sy(i)
      if (x < -s || y < -s || x > view.width + s || y > view.height + s) continue
      switch (overlay[i]) {
        case Overlay.InFlight:
          ctx.fillStyle = COLORS.inflight
          ctx.fillRect(x - half * 1.3, y - half * 1.3, s * 1.3, s * 1.3)
          break
        case Overlay.RetryWait:
          ctx.strokeStyle = COLORS.inflight
          ctx.lineWidth = 1
          ctx.strokeRect(x - half, y - half, s, s)
          break
        case Overlay.Conflict:
          ctx.fillStyle = COLORS.amber
          ctx.fillRect(x - half, y - half, s, s)
          break
        case Overlay.Failed:
          ctx.fillStyle = COLORS.fail
          ctx.fillRect(x - half, y - half, s, s)
          break
        default:
          break
      }
    }

    for (const [i, p] of pulses) {
      const t = Math.min(1, (now - p.start) / p.duration)
      const x = sx(i)
      const y = sy(i)
      if (p.kind === 'pop') {
        const grow = 1 + 1.2 * (1 - ease(t))
        ctx.globalAlpha = 1 - t
        ctx.fillStyle = p.color
        ctx.fillRect(x - half * grow, y - half * grow, s * grow, s * grow)
      } else {
        ctx.globalAlpha = 0.5 + 0.5 * Math.sin(t * Math.PI * 4) ** 2
        ctx.fillStyle = p.color
        ctx.fillRect(x - half * 1.4, y - half * 1.4, s * 1.4, s * 1.4)
      }
    }
    ctx.globalAlpha = 1
  }

  onMounted(() => {
    unsubscribe.push(liveBus.on('seats', onSeats), liveBus.on('log', onLog), liveBus.on('runStarted', onRunStarted), liveBus.on('reset', reset))
  })
  onBeforeUnmount(() => {
    unsubscribe.forEach((u) => u())
    if (ticking) gsap.ticker.remove(tick)
  })

  function overlayOf(i: number): 'in_flight' | 'retry_wait' | 'conflict' | 'failed' | null {
    switch (overlay[i]) {
      case Overlay.InFlight:
        return 'in_flight'
      case Overlay.RetryWait:
        return 'retry_wait'
      case Overlay.Conflict:
        return 'conflict'
      case Overlay.Failed:
        return 'failed'
      default:
        return null
    }
  }

  return { draw, overlayOf, overlayCount: () => overlaid.size }
}
