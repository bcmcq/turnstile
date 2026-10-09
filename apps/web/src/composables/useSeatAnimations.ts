import { gsap } from 'gsap'
import { onBeforeUnmount, onMounted } from 'vue'
import { JobState, type LogEvent, type RunSelection, type SeatsBatch, type TicketStateCode } from '@/api/types'
import type { ArenaView } from '@/composables/useArenaCanvas'
import { COLORS, FLOOR, SeatPalette, seatPath } from '@/lib/arenaPalette'
import { liveBus } from '@/lib/liveBus'
import type { SeatTable } from '@/lib/seatTable'

/** Job state painted over the base seat color until the next event replaces it. */
const Overlay = { None: 0, Queued: 1, InFlight: 2, RetryWait: 3, Conflict: 4, Failed: 5 } as const
type OverlayKind = (typeof Overlay)[keyof typeof Overlay]
export type SeatOverlayKind = 'queued' | 'in_flight' | 'retry_wait' | 'conflict' | 'failed' | null

/** Tweenable per-seat values; GSAP drives them, draw() reads them. Sizes are multiples of the seat, offsets in seats. */
interface SeatFx {
  scale: number
  alpha: number
  ring: number
  ringAlpha: number
  dx: number
  dy: number
  glow: number
  color: string
}

export interface SeatAnimationOptions {
  seats: () => SeatTable | null
  palette: () => SeatPalette
  markSeatsDirty: (indices: Iterable<number>) => void
  requestFrame: () => void
  /** Selection of the run that just started, for the queued sweep. */
  runSelection: (runId: string) => RunSelection | null
  /** Tint for queued seats: the run's target platform color, or a neutral. */
  queuedColor: () => string
  arenaWidth: number
  arenaHeight: number
}

const REVEAL_MS = 1_800
const REVEAL_POP_MS = 700
const REVEAL_STEPS = 16
/** The wavefront bursts out of the floor and settles at the rim. */
const REVEAL_WAVE = gsap.parseEase('power2.out')
/** Each seat pops to ~1.7×, undershoots, then settles at 1×; sampled at REVEAL_STEPS points. */
const REVEAL_POP = gsap.parseEase('elastic.out(2.2, 0.55)')
const SWEEP_BAND = 0.08 // fraction of the queue lit brightly at the sweep front

/**
 * Keeps per-seat overlay state and GSAP tweens for everything that moves on the arena: the queued sweep
 * when a run fans out, breathing in-flight seats, backoff countdown rings, completion pops with a ripple,
 * conflict shakes, dead-letter drops and the first-load reveal wave. One ticker draws frames only while
 * something is alive.
 */
export function useSeatAnimations(opts: SeatAnimationOptions) {
  let overlay = new Uint8Array(0)
  const overlaid = new Set<number>()
  const fx = new Map<number, SeatFx>()
  let sweep: { start: number; duration: number; order: Int32Array; marked: number; touched: Set<number> } | null = null
  let reveal: { start: number; duration: number } | null = null
  let revealOrder: Int32Array | null = null
  let revealDist: Float32Array | null = null
  let revealMax = 1
  let revealShift = 0
  let ticking = false
  const unsubscribe: Array<() => void> = []

  function ensureArrays(): SeatTable | null {
    const seats = opts.seats()
    if (seats && overlay.length !== seats.size) {
      overlay = new Uint8Array(seats.size)
      overlaid.clear()
      killAll()
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

  // --- fx registry ----------------------------------------------------------------------------------
  function fxOf(i: number, color: string): SeatFx {
    let f = fx.get(i)
    if (f) {
      gsap.killTweensOf(f)
      f.color = color
      return f
    }
    f = { scale: 1, alpha: 1, ring: 0, ringAlpha: 0, dx: 0, dy: 0, glow: 0, color }
    fx.set(i, f)
    return f
  }

  function killFx(i: number): void {
    const f = fx.get(i)
    if (!f) return
    gsap.killTweensOf(f)
    fx.delete(i)
  }

  function killAll(): void {
    for (const f of fx.values()) gsap.killTweensOf(f)
    fx.clear()
  }

  /** Drop the fx entry once its tweens finish, unless a persistent overlay still needs it. */
  function settle(i: number): void {
    if (overlay[i] !== Overlay.None) return
    const f = fx.get(i)
    if (f) gsap.killTweensOf(f)
    fx.delete(i)
  }

  function startTicker(): void {
    if (ticking) return
    ticking = true
    gsap.ticker.add(tick)
  }

  function tick(): void {
    const now = performance.now()
    if (reveal && now - reveal.start > reveal.duration) reveal = null
    if (sweep) advanceSweep(now)
    opts.requestFrame()
    if (fx.size === 0 && !reveal && !sweep) {
      gsap.ticker.remove(tick)
      ticking = false
    }
  }

  // --- motions --------------------------------------------------------------------------------------
  function breathe(i: number): void {
    const f = fxOf(i, COLORS.inflight)
    f.scale = 1.15
    f.glow = 0.5
    gsap.to(f, { scale: 1.5, glow: 1, duration: 0.55, ease: 'sine.inOut', yoyo: true, repeat: -1 })
  }

  /** Ring closes over the real backoff delay, then idles with a soft pulse until the retry starts. */
  function countdown(i: number, delayMs: number): void {
    const f = fxOf(i, COLORS.inflight)
    f.ring = 3.4
    f.ringAlpha = 0.9
    gsap.to(f, {
      ring: 1.1,
      duration: Math.max(0.3, delayMs / 1000),
      ease: 'none',
      onComplete: () => gsap.to(f, { ringAlpha: 0.3, duration: 0.5, ease: 'sine.inOut', yoyo: true, repeat: -1 }),
    })
  }

  function shake(i: number): void {
    const f = fxOf(i, COLORS.amber)
    f.scale = 1.4
    f.glow = 1
    gsap
      .timeline({ onComplete: () => settle(i) })
      .to(f, { dx: -0.9, duration: 0.05 })
      .to(f, { dx: 0.9, duration: 0.05 })
      .to(f, { dx: -0.6, duration: 0.05 })
      .to(f, { dx: 0, duration: 0.05 })
      .to(f, { scale: 1, glow: 0, duration: 0.3, ease: 'power2.out' }, '<')
  }

  function drop(i: number): void {
    const f = fxOf(i, COLORS.fail)
    f.dy = -3
    f.scale = 1.6
    f.alpha = 0
    gsap.to(f, { dy: 0, scale: 1, alpha: 1, duration: 0.7, ease: 'bounce.out', onComplete: () => settle(i) })
  }

  /** Completion: overshoot settle on the seat plus a ripple ring in the final color. */
  function pop(i: number, color: string, ringOnly = false): void {
    const f = fxOf(i, color)
    f.scale = ringOnly ? 1 : 2.1
    f.alpha = ringOnly ? 1 : 0.6
    f.ring = 1
    f.ringAlpha = 0.85
    gsap.to(f, { scale: 1, alpha: 1, duration: 0.55, ease: 'back.out(2.5)' })
    gsap.to(f, { ring: 3.6, ringAlpha: 0, duration: 0.6, ease: 'power2.out', onComplete: () => settle(i) })
  }

  /** Queued sweep: seats of the new run dim to the target tint in dispatch order (ticket id), front lit brightly. */
  function startSweep(runId: string, sectionsCsv: string): void {
    const seats = ensureArrays()
    if (!seats) return
    const selection = opts.runSelection(runId)
    const sections = new Set(selection?.sections ?? sectionsCsv.split(',').filter(Boolean).map(Number))
    const tickets = new Set(selection?.tickets ?? [])
    const picked: number[] = []
    for (let i = 0; i < seats.size; i++) {
      if (sections.has(seats.sectionId[i]) || tickets.has(seats.ticketId[i])) picked.push(i)
    }
    if (picked.length === 0) return
    picked.sort((a, b) => seats.ticketId[a] - seats.ticketId[b])
    sweep = {
      start: performance.now(),
      duration: Math.min(1_400, Math.max(350, picked.length * 0.35)),
      order: Int32Array.from(picked),
      marked: 0,
      touched: new Set(),
    }
    startTicker()
  }

  function advanceSweep(now: number): void {
    if (!sweep) return
    const t = Math.min(1, (now - sweep.start) / sweep.duration)
    const upTo = Math.floor(t * sweep.order.length)
    for (; sweep.marked < upTo; sweep.marked++) {
      const i = sweep.order[sweep.marked]
      if (overlay[i] === Overlay.None && !sweep.touched.has(i)) setOverlay(i, Overlay.Queued)
    }
    if (t >= 1) sweep = null
  }

  function clearQueued(): void {
    for (const i of [...overlaid]) if (overlay[i] === Overlay.Queued) setOverlay(i, Overlay.None)
    sweep = null
    opts.requestFrame()
  }

  // --- inputs ---------------------------------------------------------------------------------------
  /**
   * Ticket changes first, then the last job state per ticket of the same tick. Both ride one message, so a job
   * that started and finished inside a tick resolves to its final state; the (sampled) log cannot leave a seat stuck.
   */
  function onSeats(batch: SeatsBatch): void {
    const seats = ensureArrays()
    if (!seats) return
    const dirty: number[] = []
    for (const [ticketId, , state, platformId, priceCents] of batch.rows) {
      const i = seats.update(ticketId, state as TicketStateCode, platformId, priceCents)
      if (i === undefined) continue
      dirty.push(i)
      sweep?.touched.add(i)
      setOverlay(i, Overlay.None)
      pop(i, finalColor(seats, i))
    }
    for (const [ticketId, state, delayMs] of batch.jobs ?? []) {
      const i = seats.indexOf(ticketId)
      if (i === undefined) continue
      sweep?.touched.add(i)
      switch (state) {
        case JobState.Started:
          setOverlay(i, Overlay.InFlight)
          breathe(i)
          break
        case JobState.RetryWait:
          setOverlay(i, Overlay.RetryWait)
          countdown(i, delayMs || 1_000)
          break
        case JobState.Conflict:
          setOverlay(i, Overlay.Conflict)
          shake(i)
          break
        case JobState.DeadLettered:
          setOverlay(i, Overlay.Failed)
          drop(i)
          break
        case JobState.Skipped:
          // Nothing changed on the seat (idempotent replay): a cyan ripple says "checked, already done".
          setOverlay(i, Overlay.None)
          pop(i, COLORS.cyan, true)
          break
        case JobState.Requeued:
          setOverlay(i, Overlay.Queued)
          killFx(i)
          break
        case JobState.Completed:
          if (overlay[i] !== Overlay.None) {
            setOverlay(i, Overlay.None)
            if (!fx.has(i)) pop(i, finalColor(seats, i))
            else settle(i)
          }
          break
        default:
          break
      }
    }
    if (dirty.length) opts.markSeatsDirty(dirty)
    if (dirty.length || batch.jobs?.length) startTicker()
  }

  /** The log is sampled under load, so only run-level events are read here; per-seat job state comes with the seats batch. */
  function onLog(events: LogEvent[]): void {
    for (const e of events) {
      if (e.type === 'run.started') startSweep(String(e.runId), typeof e.sections === 'string' ? e.sections : '')
      else if (e.type === 'run.finished' || e.type === 'run.cancelled') clearQueued()
    }
  }

  function reset(): void {
    overlay = new Uint8Array(0)
    overlaid.clear()
    killAll()
    sweep = null
    opts.requestFrame()
  }

  // --- first-load reveal: the bowl populates outward from the floor at constant speed -----------------
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
    for (let i = 0; i < seats.size; i++) revealDist[i] -= min
    revealMax -= min
    revealShift = -min
    const dist = revealDist
    revealOrder = Int32Array.from({ length: seats.size }, (_, i) => i).sort((a, b) => dist[a] - dist[b])
    reveal = { start: performance.now(), duration: REVEAL_MS + REVEAL_POP_MS }
    startTicker()
  }

  /** Signed distance (arena units) from the floor's rounded rectangle; rings are offsets of it. */
  function floorDistance(x: number, y: number): number {
    const px = Math.abs(x - opts.arenaWidth / 2) - (FLOOR.width / 2 - FLOOR.radius)
    const py = Math.abs(y - opts.arenaHeight / 2) - (FLOOR.height / 2 - FLOOR.radius)
    const qx = Math.max(px, 0)
    const qy = Math.max(py, 0)
    return Math.hypot(qx, qy) + Math.min(Math.max(px, py), 0) - FLOOR.radius
  }

  /** While revealing, the base layer only shows inside this rounded rectangle; the wavefront seats draw on top. */
  function revealClip(view: ArenaView): { x: number; y: number; w: number; h: number; r: number } | null {
    if (!reveal || !revealDist) return null
    const t = Math.min(1, (performance.now() - reveal.start) / reveal.duration)
    const grow = Math.max(0, revealReach(t) - bandUnits() - revealShift)
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

  function revealReach(t: number): number {
    return REVEAL_WAVE(t) * (revealMax + bandUnits())
  }

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
    const s = Math.max(2, seatPx)
    const half = s / 2
    const sx = (i: number) => seats.x[i] * view.scale + view.offsetX
    const sy = (i: number) => seats.y[i] * view.scale + view.offsetY
    const onScreen = (x: number, y: number) => x >= -4 * s && y >= -4 * s && x <= view.width + 4 * s && y <= view.height + 4 * s
    const square = (x: number, y: number, k: number) => seatPath(ctx, x - half * k, y - half * k, s * k, s * k)

    // queued: one path, there can be thousands
    ctx.fillStyle = opts.queuedColor()
    ctx.globalAlpha = 0.3
    ctx.beginPath()
    for (const i of overlaid) {
      if (overlay[i] !== Overlay.Queued) continue
      const x = sx(i)
      const y = sy(i)
      if (onScreen(x, y)) square(x, y, 1)
    }
    ctx.fill()
    if (sweep) {
      // bright band at the sweep front
      const from = Math.max(0, sweep.marked - Math.ceil(sweep.order.length * SWEEP_BAND))
      ctx.globalAlpha = 0.85
      ctx.beginPath()
      for (let k = from; k < sweep.marked; k++) {
        const i = sweep.order[k]
        if (overlay[i] === Overlay.Queued) square(sx(i), sy(i), 1.3)
      }
      ctx.fill()
    }
    ctx.globalAlpha = 1

    for (const i of overlaid) {
      const kind = overlay[i]
      if (kind === Overlay.Queued) continue
      const x = sx(i)
      const y = sy(i)
      if (!onScreen(x, y)) continue
      const f = fx.get(i)
      switch (kind) {
        case Overlay.InFlight: {
          const glow = f?.glow ?? 0.5
          ctx.fillStyle = COLORS.inflight
          ctx.globalAlpha = 0.16 * glow
          ctx.beginPath()
          square(x, y, 2.6)
          ctx.fill()
          ctx.globalAlpha = 1
          ctx.beginPath()
          square(x, y, f?.scale ?? 1.3)
          ctx.fill()
          break
        }
        case Overlay.RetryWait: {
          ctx.strokeStyle = COLORS.inflight
          ctx.lineWidth = 1
          ctx.beginPath()
          square(x, y, 1)
          ctx.stroke()
          if (f && f.ringAlpha > 0) {
            ctx.globalAlpha = f.ringAlpha
            ctx.lineWidth = 1.25
            ctx.beginPath()
            square(x, y, f.ring)
            ctx.stroke()
            ctx.globalAlpha = 1
          }
          break
        }
        case Overlay.Conflict: {
          const dx = (f?.dx ?? 0) * s
          ctx.fillStyle = COLORS.amber
          ctx.beginPath()
          square(x + dx, y, f?.scale ?? 1)
          ctx.fill()
          if (f && f.glow > 0) {
            ctx.fillStyle = COLORS.sold
            ctx.globalAlpha = 0.8 * f.glow
            ctx.beginPath()
            square(x + dx, y, f.scale)
            ctx.fill()
            ctx.globalAlpha = 1
          }
          break
        }
        case Overlay.Failed: {
          ctx.fillStyle = COLORS.fail
          ctx.globalAlpha = f?.alpha ?? 1
          ctx.beginPath()
          square(x, y + (f?.dy ?? 0) * s, f?.scale ?? 1)
          ctx.fill()
          ctx.globalAlpha = 1
          break
        }
        default:
          break
      }
    }

    // transient pops and ripples on seats with no persistent overlay
    for (const [i, f] of fx) {
      if (overlay[i] !== Overlay.None) continue
      const x = sx(i)
      const y = sy(i)
      if (!onScreen(x, y)) continue
      if (f.scale !== 1 || f.alpha !== 1) {
        ctx.fillStyle = f.color
        ctx.globalAlpha = f.alpha
        ctx.beginPath()
        square(x, y, f.scale)
        ctx.fill()
      }
      if (f.ringAlpha > 0) {
        ctx.strokeStyle = f.color
        ctx.globalAlpha = f.ringAlpha
        ctx.lineWidth = 1.25
        ctx.beginPath()
        square(x, y, f.ring)
        ctx.stroke()
      }
    }
    ctx.globalAlpha = 1

    if (reveal && revealOrder && revealDist) {
      // Seats within one pop-duration of the wavefront ride REVEAL_POP in their final color, with a white
      // flash while overshooting. Progress is quantized so the band is a handful of path fills, not one per seat.
      const t = Math.min(1, (performance.now() - reveal.start) / reveal.duration)
      const reach = revealReach(t)
      const band = bandUnits()
      const from = lowerBound(reach - band)
      const to = lowerBound(reach)
      const palette = opts.palette()
      const buckets = new Map<number, number[]>()
      for (let k = from; k < to; k++) {
        const i = revealOrder[k]
        const step = Math.min(REVEAL_STEPS - 1, Math.floor(((reach - revealDist[i]) / band) * REVEAL_STEPS))
        const key = palette.slot(seats.state[i], seats.platformId[i]) * REVEAL_STEPS + step
        let list = buckets.get(key)
        if (!list) buckets.set(key, (list = []))
        list.push(i)
      }
      for (const [key, list] of buckets) {
        const step = key % REVEAL_STEPS
        const p = (step + 0.5) / REVEAL_STEPS
        const grow = REVEAL_POP(p)
        ctx.globalAlpha = Math.min(1, 0.4 + 2 * p)
        ctx.fillStyle = palette.colors[(key - step) / REVEAL_STEPS]
        ctx.beginPath()
        for (const i of list) square(sx(i), sy(i), grow)
        ctx.fill()
        if (grow > 1.05) {
          ctx.globalAlpha = 0.5 * (grow - 1)
          ctx.fillStyle = COLORS.fg
          ctx.fill()
        }
      }
      ctx.globalAlpha = 1
    }
  }

  onMounted(() => {
    unsubscribe.push(liveBus.on('seats', onSeats), liveBus.on('log', onLog), liveBus.on('reset', reset))
  })
  onBeforeUnmount(() => {
    for (const off of unsubscribe) off()
    killAll()
    if (ticking) gsap.ticker.remove(tick)
  })

  function overlayOf(i: number): SeatOverlayKind {
    switch (overlay[i]) {
      case Overlay.Queued:
        return 'queued'
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

  return { draw, overlayOf, revealSeats, revealClip }
}
