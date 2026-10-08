import { gsap } from 'gsap'
import { onBeforeUnmount, onMounted } from 'vue'
import type { LogEvent, SeatUpdate, TicketStateCode } from '@/api/types'
import type { ArenaView } from '@/composables/useArenaCanvas'
import { COLORS, FLOOR, SeatPalette } from '@/lib/arenaPalette'
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
const REVEAL_MS = 1_600
const REVEAL_POP_MS = 420
const ease = gsap.parseEase('power2.out')

/**
 * Keeps per-seat overlay state (in flight, retry wait, conflict, failed) and short-lived pulses, and
 * draws them on the arena's overlay layer. One GSAP ticker drives frames only while something moves.
 */
export function useSeatAnimations(opts: SeatAnimationOptions) {
  let overlay = new Uint8Array(0)
  const overlaid = new Set<number>()
  const pulses = new Map<number, Pulse>()
  let reveal: { start: number; duration: number } | null = null
  let revealOrder: Int32Array | null = null // seat indices sorted by distance from the floor
  let revealDist: Float32Array | null = null // distance per seat, same indexing as the table
  let revealMax = 1
  let revealShift = 0 // distance from the innermost floor seat to the floor edge
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
    if (reveal && now - reveal.start > reveal.duration) reveal = null
    opts.requestFrame()
    if (pulses.size === 0 && !reveal) {
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

  function reset(): void {
    overlay = new Uint8Array(0)
    overlaid.clear()
    pulses.clear()
    opts.requestFrame()
  }

  /** Called when a seat table arrives (first load, demo reset): the bowl populates outward from the floor. */
  function revealSeats(): void {
    const seats = ensureArrays()
    if (!seats) return
    revealDist = new Float32Array(seats.size)
    revealMax = 1
    let min = 0
    for (let i = 0; i < seats.size; i++) {
      const d = floorDistance(seats.x[i], seats.y[i])
      revealDist[i] = d
      if (d > revealMax) revealMax = d
      if (d < min) min = d
    }
    // shift so the innermost floor seat is at 0 and the wave starts there
    for (let i = 0; i < seats.size; i++) revealDist[i] -= min
    revealMax -= min
    revealShift = -min
    const dist = revealDist
    revealOrder = Int32Array.from({ length: seats.size }, (_, i) => i).sort((a, b) => dist[a] - dist[b])
    reveal = { start: performance.now(), duration: REVEAL_MS }
    startTicker()
  }

  /** Signed distance (arena units) from the floor's rounded rectangle; rings are offsets of it, so this is the ring offset. */
  function floorDistance(x: number, y: number): number {
    const px = Math.abs(x - opts.arenaWidth / 2) - (FLOOR.width / 2 - FLOOR.radius)
    const py = Math.abs(y - opts.arenaHeight / 2) - (FLOOR.height / 2 - FLOOR.radius)
    const qx = Math.max(px, 0)
    const qy = Math.max(py, 0)
    return Math.hypot(qx, qy) + Math.min(Math.max(px, py), 0) - FLOOR.radius
  }

  /** While revealing, the base layer is only shown inside this rounded rectangle (the floor grown by the wave's reach); the wavefront seats are drawn on top. */
  function revealClip(view: ArenaView): { x: number; y: number; w: number; h: number; r: number } | null {
    if (!reveal || !revealDist) return null
    const t = Math.min(1, (performance.now() - reveal.start) / reveal.duration)
    const grow = Math.max(0, ease(t) * revealMax - bandUnits() - revealShift)
    const w = FLOOR.width + 2 * grow
    const h = FLOOR.height + 2 * grow
    return {
      x: (opts.arenaWidth / 2 - w / 2) * view.scale + view.offsetX,
      y: (opts.arenaHeight / 2 - h / 2) * view.scale + view.offsetY,
      w: w * view.scale,
      h: h * view.scale,
      r: (FLOOR.radius + grow) * view.scale,
    }
  }

  function bandUnits(): number {
    return revealMax * (REVEAL_POP_MS / REVEAL_MS)
  }

  /** First index in revealOrder whose distance is >= d. */
  function lowerBound(d: number): number {
    const order = revealOrder
    const dist = revealDist
    if (!order || !dist) return 0
    let lo = 0
    let hi = order.length
    while (lo < hi) {
      const mid = (lo + hi) >> 1
      if (dist[order[mid]] < d) lo = mid + 1
      else hi = mid
    }
    return lo
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

    if (reveal && revealOrder && revealDist) {
      // Seats whose distance is within one pop-duration of the wavefront: scale 2.4× → 1×, fade in, final color.
      const t = Math.min(1, (now - reveal.start) / reveal.duration)
      const reach = ease(t) * revealMax
      const band = bandUnits()
      const from = lowerBound(reach - band)
      const to = lowerBound(reach)
      const palette = opts.palette()
      for (let k = from; k < to; k++) {
        const i = revealOrder[k]
        const p = Math.min(1, (reach - revealDist[i]) / band) // 0 = just arrived, 1 = settled
        const grow = 1 + 1.4 * (1 - p) * (1 - p)
        ctx.globalAlpha = 0.25 + 0.75 * p
        ctx.fillStyle = palette.colors[palette.slot(seats.state[i], seats.platformId[i])]
        ctx.fillRect(sx(i) - half * grow, sy(i) - half * grow, s * grow, s * grow)
      }
      ctx.globalAlpha = 1
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
    unsubscribe.push(liveBus.on('seats', onSeats), liveBus.on('log', onLog), liveBus.on('reset', reset))
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

  return { draw, overlayOf, revealSeats, revealClip, overlayCount: () => overlaid.size }
}
