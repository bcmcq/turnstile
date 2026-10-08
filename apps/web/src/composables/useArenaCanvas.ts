import { onBeforeUnmount, onMounted, reactive, type Ref } from 'vue'
import type { SectionView } from '@/api/types'
import { COLORS, FLOOR, SEAT_SIZE, SeatPalette } from '@/lib/arenaPalette'
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
 * Two layers: a base canvas holding all 100k seats in device pixels (re-rendered only when the view
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
    return Math.max(1, SEAT_SIZE * view.scale)
  }

  function toScreen(ux: number, uy: number): [number, number] {
    return [ux * view.scale + view.offsetX, uy * view.scale + view.offsetY]
  }

  function toUnit(sx: number, sy: number): [number, number] {
    return [(sx - view.offsetX) / view.scale, (sy - view.offsetY) / view.scale]
  }

  function fit(): void {
    const el = opts.container.value
    if (!el) return
    view.width = el.clientWidth
    view.height = el.clientHeight
    view.fitScale = Math.min(view.width / opts.arenaWidth, view.height / opts.arenaHeight)
    view.scale = view.fitScale
    view.offsetX = (view.width - opts.arenaWidth * view.scale) / 2
    view.offsetY = (view.height - opts.arenaHeight * view.scale) / 2
    resizeCanvases()
    baseDirty = true
    requestFrame()
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

  function zoomAt(sx: number, sy: number, factor: number): void {
    const next = Math.min(view.fitScale * MAX_ZOOM, Math.max(view.fitScale, view.scale * factor))
    if (next === view.scale) return
    const [ux, uy] = toUnit(sx, sy)
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

    // seats, batched by palette slot so fillStyle changes a handful of times, not 100k
    const palette = opts.palette()
    const s = seatPx()
    const half = s / 2
    const n = seats.size
    const slots = new Uint8Array(n)
    for (let i = 0; i < n; i++) slots[i] = palette.slot(seats.state[i], seats.platformId[i])
    for (let slot = 0; slot < palette.colors.length; slot++) {
      ctx.fillStyle = palette.colors[slot]
      for (let i = 0; i < n; i++) {
        if (slots[i] !== slot) continue
        ctx.fillRect(seats.x[i] * view.scale + view.offsetX - half, seats.y[i] * view.scale + view.offsetY - half, s, s)
      }
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
      ctx.fillRect(px, py, s, s)
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
      ctx.fillText(section.tier === 'floor' ? 'FLOOR' : section.code, sx, sy)
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
    if (!c || !ctx) return
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
    zoomAt(view.width / 2, view.height / 2, 1.5)
  }

  function zoomOut(): void {
    zoomAt(view.width / 2, view.height / 2, 1 / 1.5)
  }

  onMounted(() => {
    fit()
    observer = new ResizeObserver(() => fit())
    if (opts.container.value) observer.observe(opts.container.value)
  })

  onBeforeUnmount(() => observer?.disconnect())

  return { view, toUnit, toScreen, seatPx, fit, zoomIn, zoomOut, invalidate, markSeatsDirty, requestFrame, onWheel, onPointerDown, onPointerMove, onPointerUp }
}
