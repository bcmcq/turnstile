import { gsap } from 'gsap'
import { onBeforeUnmount, onMounted, reactive, type Ref } from 'vue'
import type { SectionView } from '@/api/types'
import { COLORS, FLOOR, SeatPalette, fillSeat, seatPath } from '@/lib/arenaPalette'
import type { SeatTable } from '@/lib/seatTable'

export interface ArenaView {
  /** CSS px per arena unit */
  scale: number
  fitScale: number
  offsetX: number
  offsetY: number
  width: number
  height: number
}

export interface ArenaCanvasOptions {
  canvas: Ref<HTMLCanvasElement | null>
  container: Ref<HTMLElement | null>
  arenaWidth: number
  /** Seat dot edge in arena units; follows the seeded density. */
  seatSize: () => number
  arenaHeight: number
  seats: () => SeatTable | null
  sections: () => SectionView[]
  palette: () => SeatPalette
  /** draws on top of seats each frame, in CSS px with the view transform already applied by the caller */
  overlay?: (ctx: CanvasRenderingContext2D, view: ArenaView) => void
  /** when set, the base layer is composited only inside this rounded rectangle (CSS px) */
  baseClip?: (view: ArenaView) => { x: number; y: number; w: number; h: number; r: number } | null
}

const MAX_ZOOM = 6

/**
 * Two layers: a base canvas holding every seat in device pixels (re-rendered only when the view
 * transform or a seat changes) and the visible canvas that composites base + floor labels + overlay.
 * A frame is only drawn when something asked for one.
 */
export function useArenaCanvas(opts: ArenaCanvasOptions) {
  const view = reactive<ArenaView>({ scale: 1, fitScale: 1, offsetX: 0, offsetY: 0, width: 0, height: 0 })
  const base = document.createElement('canvas')
  let dpr = 1
  let baseDirty = true
  let frameRequested = false
  let observer: ResizeObserver | null = null
  const dirtySeats = new Set<number>()
  let drag: { x: number; y: number; ox: number; oy: number; moved: boolean } | null = null

  function seatPx(): number {
    return Math.max(1, opts.seatSize() * view.scale)
  }

  function toScreen(ux: number, uy: number): [number, number] {
    return [ux * view.scale + view.offsetX, uy * view.scale + view.offsetY]
  }

  function toUnit(sx: number, sy: number): [number, number] {
    return [(sx - view.offsetX) / view.scale, (sy - view.offsetY) / view.scale]
  }

  function fit(animated = false): void {
    const el = opts.container.value
    if (!el) return
    // a layout shift during the pull-back (the first data load resizes the panel) retargets it instead of snapping;
    // any other live tween (a section zoom) is left alone so a resize cannot hijack it
    const live = pullingBack ? gsap.getTweensOf(view)[0] : undefined
    if (!pullingBack && gsap.isTweening(view)) {
      view.width = el.clientWidth
      view.height = el.clientHeight
      view.fitScale = Math.min(view.width / opts.arenaWidth, view.height / opts.arenaHeight)
      resizeCanvases()
      return
    }
    gsap.killTweensOf(view)
    view.width = el.clientWidth
    view.height = el.clientHeight
    view.fitScale = Math.min(view.width / opts.arenaWidth, view.height / opts.arenaHeight)
    const scale = view.fitScale
    const offsetX = (view.width - opts.arenaWidth * scale) / 2
    const offsetY = (view.height - opts.arenaHeight * scale) / 2
    resizeCanvases()
    if (live) {
      animateView(scale, offsetX, offsetY, live.duration() - live.time(), live.vars.ease)
      return
    }
    if (animated) {
      animateView(scale, offsetX, offsetY)
      return
    }
    view.scale = scale
    view.offsetX = offsetX
    view.offsetY = offsetY
    baseDirty = true
    requestFrame()
  }

  /** Tween the view transform; every frame re-renders the base layer, which is cheap at this seat count. */
  function animateView(scale: number, offsetX: number, offsetY: number, duration = 0.5, ease: gsap.TweenVars['ease'] = 'power3.out'): void {
    gsap.killTweensOf(view)
    gsap.to(view, {
      scale,
      offsetX,
      offsetY,
      duration,
      ease,
      onUpdate: () => {
        baseDirty = true
        requestFrame()
      },
    })
  }

  /** Jump in on the floor and ease back out to the fit; paced to the seat reveal wave. */
  let pullingBack = false
  function pullBack(factor = 1.2, duration = 2): void {
    const centred = (scale: number): [number, number] => [(view.width - opts.arenaWidth * scale) / 2, (view.height - opts.arenaHeight * scale) / 2]
    gsap.killTweensOf(view)
    view.scale = view.fitScale * factor
    ;[view.offsetX, view.offsetY] = centred(view.scale)
    pullingBack = true
    animateView(view.fitScale, ...centred(view.fitScale), duration, 'expo.out')
    gsap.getTweensOf(view)[0]?.eventCallback('onComplete', () => (pullingBack = false))
  }

  /** Zoom so the given arena-unit box fills the panel with some padding, capped at max zoom. */
  function zoomToBounds(minX: number, minY: number, maxX: number, maxY: number, paddingPx = 48, maxZoom = 3.5): void {
    const w = Math.max(1, maxX - minX)
    const h = Math.max(1, maxY - minY)
    // capped below MAX_ZOOM so a single section keeps its neighbours in view for context
    const scale = Math.min(view.fitScale * maxZoom, Math.max(view.fitScale, Math.min((view.width - 2 * paddingPx) / w, (view.height - 2 * paddingPx) / h)))
    animateView(scale, view.width / 2 - ((minX + maxX) / 2) * scale, view.height / 2 - ((minY + maxY) / 2) * scale)
  }

  function resizeCanvases(): void {
    const c = opts.canvas.value
    if (!c) return
    dpr = window.devicePixelRatio || 1
    for (const el of [c, base]) {
      el.width = Math.round(view.width * dpr)
      el.height = Math.round(view.height * dpr)
    }
    c.style.width = `${view.width}px`
    c.style.height = `${view.height}px`
  }

  function clampOffsets(): void {
    // keep at least a quarter of the arena on screen
    const w = opts.arenaWidth * view.scale
    const h = opts.arenaHeight * view.scale
    view.offsetX = Math.min(view.width - w * 0.25, Math.max(-w * 0.75, view.offsetX))
    view.offsetY = Math.min(view.height - h * 0.25, Math.max(-h * 0.75, view.offsetY))
  }

  function zoomAt(sx: number, sy: number, factor: number, animated = false): void {
    gsap.killTweensOf(view)
    const next = Math.min(view.fitScale * MAX_ZOOM, Math.max(view.fitScale, view.scale * factor))
    if (next === view.scale) return
    const [ux, uy] = toUnit(sx, sy)
    if (animated) {
      animateView(next, sx - ux * next, sy - uy * next)
      return
    }
    view.scale = next
    view.offsetX = sx - ux * next
    view.offsetY = sy - uy * next
    clampOffsets()
    baseDirty = true
    requestFrame()
  }

  function renderBase(): void {
    const seats = opts.seats()
    const ctx = base.getContext('2d')
    if (!ctx || !seats) return
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    ctx.clearRect(0, 0, view.width, view.height)

    // floor
    const [fx, fy] = toScreen(opts.arenaWidth / 2 - FLOOR.width / 2, opts.arenaHeight / 2 - FLOOR.height / 2)
    ctx.fillStyle = COLORS.panel2
    ctx.strokeStyle = COLORS.border
    ctx.lineWidth = 1
    ctx.beginPath()
    ctx.roundRect(fx, fy, FLOOR.width * view.scale, FLOOR.height * view.scale, FLOOR.radius * view.scale)
    ctx.fill()
    ctx.stroke()
    ctx.fillStyle = COLORS.dim
    ctx.font = `500 ${Math.max(9, Math.min(14, 11 * Math.sqrt(view.scale / view.fitScale)))}px Inter, system-ui, sans-serif`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    ctx.fillText('FLOOR', fx + (FLOOR.width * view.scale) / 2, fy + (FLOOR.height * view.scale) / 2)

    // seats, batched by palette slot so fillStyle changes a handful of times
    const palette = opts.palette()
    const s = seatPx()
    const half = s / 2
    const n = seats.size
    const slots = new Uint8Array(n)
    for (let i = 0; i < n; i++) slots[i] = palette.slot(seats.state[i], seats.platformId[i])
    for (let slot = 0; slot < palette.colors.length; slot++) {
      ctx.fillStyle = palette.colors[slot]
      ctx.beginPath()
      for (let i = 0; i < n; i++) {
        if (slots[i] !== slot) continue
        seatPath(ctx, seats.x[i] * view.scale + view.offsetX - half, seats.y[i] * view.scale + view.offsetY - half, s, s)
      }
      ctx.fill()
    }
    baseDirty = false
    dirtySeats.clear()
  }

  function repaintDirtySeats(): void {
    const seats = opts.seats()
    const ctx = base.getContext('2d')
    if (!ctx || !seats || dirtySeats.size === 0) return
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    const palette = opts.palette()
    const s = seatPx()
    const half = s / 2
    for (const i of dirtySeats) {
      const px = seats.x[i] * view.scale + view.offsetX - half
      const py = seats.y[i] * view.scale + view.offsetY - half
      ctx.clearRect(px - 0.5, py - 0.5, s + 1, s + 1)
      ctx.fillStyle = palette.colors[palette.slot(seats.state[i], seats.platformId[i])]
      fillSeat(ctx, px, py, s, s)
    }
    dirtySeats.clear()
  }

  function drawLabels(ctx: CanvasRenderingContext2D): void {
    const zoom = view.scale / view.fitScale
    ctx.font = `500 ${Math.max(9, Math.min(13, 9 * Math.sqrt(zoom)))}px Inter, system-ui, sans-serif`
    ctx.textAlign = 'center'
    ctx.textBaseline = 'middle'
    for (const section of opts.sections()) {
      const [sx, sy] = toScreen(section.geometry.labelX, section.geometry.labelY)
      if (sx < -20 || sy < -20 || sx > view.width + 20 || sy > view.height + 20) continue
      const closed = section.status === 'closed'
      ctx.fillStyle = closed ? COLORS.dim : COLORS.muted
      ctx.fillText(section.code, sx, sy)
      if (closed) {
        const w = ctx.measureText(section.code).width
        ctx.strokeStyle = COLORS.dim
        ctx.lineWidth = 1
        ctx.beginPath()
        ctx.moveTo(sx - w / 2, sy)
        ctx.lineTo(sx + w / 2, sy)
        ctx.stroke()
      }
    }
  }

  function frame(): void {
    frameRequested = false
    const c = opts.canvas.value
    const ctx = c?.getContext('2d')
    // the container has no layout yet on the very first frame; drawImage throws on a 0×0 source
    if (!c || !ctx || base.width === 0 || base.height === 0) return
    if (baseDirty) renderBase()
    else repaintDirtySeats()
    ctx.setTransform(1, 0, 0, 1, 0, 0)
    ctx.clearRect(0, 0, c.width, c.height)
    const clip = opts.baseClip?.(view) ?? null
    if (clip) {
      ctx.save()
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
      ctx.beginPath()
      ctx.roundRect(clip.x, clip.y, clip.w, clip.h, clip.r)
      ctx.clip()
      ctx.setTransform(1, 0, 0, 1, 0, 0)
      ctx.drawImage(base, 0, 0)
      ctx.restore()
    } else {
      ctx.drawImage(base, 0, 0)
    }
    ctx.setTransform(dpr, 0, 0, dpr, 0, 0)
    drawLabels(ctx)
    opts.overlay?.(ctx, view)
  }

  function requestFrame(): void {
    if (frameRequested) return
    frameRequested = true
    requestAnimationFrame(frame)
  }

  function markSeatsDirty(indices: Iterable<number>): void {
    for (const i of indices) dirtySeats.add(i)
    requestFrame()
  }

  function invalidate(): void {
    baseDirty = true
    requestFrame()
  }

  // --- pointer interaction: wheel zoom, drag pan, double-click reset ---------------------------------
  function onWheel(e: WheelEvent): void {
    e.preventDefault()
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
    zoomAt(e.clientX - r.left, e.clientY - r.top, Math.exp(-e.deltaY * 0.0015))
  }

  function onPointerDown(e: PointerEvent): void {
    if (e.button !== 0) return
    gsap.killTweensOf(view)
    drag = { x: e.clientX, y: e.clientY, ox: view.offsetX, oy: view.offsetY, moved: false }
    ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
  }

  function onPointerMove(e: PointerEvent): void {
    if (!drag) return
    const dx = e.clientX - drag.x
    const dy = e.clientY - drag.y
    if (!drag.moved && Math.hypot(dx, dy) < 4) return
    drag.moved = true
    view.offsetX = drag.ox + dx
    view.offsetY = drag.oy + dy
    clampOffsets()
    baseDirty = true
    requestFrame()
  }

  /** @returns true when the pointer-up ended a drag (so a click handler can ignore it) */
  function onPointerUp(): boolean {
    const moved = drag?.moved ?? false
    drag = null
    return moved
  }

  function zoomIn(): void {
    zoomAt(view.width / 2, view.height / 2, 1.5, true)
  }

  function zoomOut(): void {
    zoomAt(view.width / 2, view.height / 2, 1 / 1.5, true)
  }

  onMounted(() => {
    fit()
    observer = new ResizeObserver(() => fit())
    if (opts.container.value) observer.observe(opts.container.value)
  })

  onBeforeUnmount(() => {
    observer?.disconnect()
    gsap.killTweensOf(view)
  })

  return { view, toUnit, toScreen, seatPx, fit, pullBack, zoomIn, zoomOut, zoomToBounds, invalidate, markSeatsDirty, requestFrame, onWheel, onPointerDown, onPointerMove, onPointerUp }
}
