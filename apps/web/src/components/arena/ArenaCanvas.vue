<script setup lang="ts">
import { gsap } from 'gsap'
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import SeatTooltip from '@/components/arena/SeatTooltip.vue'
import JobTracePopover from '@/components/tabs/JobTracePopover.vue'
import { useArenaCanvas } from '@/composables/useArenaCanvas'
import { useArenaInteraction } from '@/composables/useArenaInteraction'
import { useSeatAnimations } from '@/composables/useSeatAnimations'
import { COLORS, SeatPalette, fillSeat } from '@/lib/arenaPalette'
import { useArenaStore } from '@/stores/arena'
import { useRunStore } from '@/stores/run'

const arena = useArenaStore()
const run = useRunStore()
const canvas = ref<HTMLCanvasElement | null>(null)
const container = ref<HTMLElement | null>(null)
const palette = computed(() => new SeatPalette(arena.platforms))

const map = useArenaCanvas({
  canvas,
  container,
  arenaWidth: arena.arenaSize.width,
  arenaHeight: arena.arenaSize.height,
  seatSize: () => arena.seatSize,
  seats: () => arena.seats,
  sections: () => arena.sections,
  palette: () => palette.value,
  overlay: (ctx, view) => {
    fx.draw(ctx, view, map.seatPx())
    drawSelection(ctx, view)
  },
  baseClip: (view) => fx.revealClip(view),
})

const pick = useArenaInteraction({
  seats: () => arena.seats,
  view: map.view,
  toUnit: map.toUnit,
  toScreen: map.toScreen,
  requestFrame: () => map.requestFrame(),
  arenaWidth: arena.arenaSize.width,
  arenaHeight: arena.arenaSize.height,
})

function drawSelection(ctx: CanvasRenderingContext2D, view: { scale: number; offsetX: number; offsetY: number; width: number; height: number }): void {
  const seats = arena.seats
  if (!seats) return
  const s = Math.max(2, map.seatPx())
  const half = s / 2

  // selected sections: faint wash over their seats + highlighted label
  if (wash.alpha > 0.005 && arena.selectedSections.size) {
    ctx.fillStyle = COLORS.cyan
    ctx.globalAlpha = wash.alpha
    for (let i = 0; i < seats.size; i++) {
      if (!arena.selectedSections.has(seats.sectionId[i])) continue
      const x = seats.x[i] * view.scale + view.offsetX
      const y = seats.y[i] * view.scale + view.offsetY
      if (x < -s || y < -s || x > view.width + s || y > view.height + s) continue
      fillSeat(ctx, x - half - 0.5, y - half - 0.5, s + 1, s + 1)
    }
    ctx.globalAlpha = 1
  }
  ctx.font = '600 11px Inter, system-ui, sans-serif'
  ctx.textAlign = 'center'
  ctx.textBaseline = 'middle'
  for (const sec of arena.sections) {
    const selected = arena.selectedSections.has(sec.id)
    const hovered = pick.hoveredSection.value === sec.id
    if (!selected && !hovered) continue
    const [lx, ly] = map.toScreen(sec.geometry.labelX, sec.geometry.labelY)
    const label = sec.code
    const w = ctx.measureText(label).width + 12
    ctx.fillStyle = selected ? 'rgba(34,211,238,0.16)' : 'rgba(230,237,243,0.08)'
    ctx.strokeStyle = selected ? 'rgba(34,211,238,0.7)' : 'rgba(230,237,243,0.3)'
    ctx.lineWidth = 1
    ctx.beginPath()
    ctx.roundRect(lx - w / 2, ly - 9, w, 18, 4)
    ctx.fill()
    ctx.stroke()
    ctx.fillStyle = selected ? COLORS.cyan : COLORS.fg
    ctx.fillText(label, lx, ly)
  }

  // box select in progress
  const b = pick.box.value
  if (b) {
    const x = Math.min(b.x0, b.x1)
    const y = Math.min(b.y0, b.y1)
    const w = Math.abs(b.x1 - b.x0)
    const h = Math.abs(b.y1 - b.y0)
    ctx.fillStyle = COLORS.cyan
    ctx.globalAlpha = 0.1
    ctx.fillRect(x, y, w, h)
    ctx.globalAlpha = 1
    ctx.strokeStyle = COLORS.cyan
    ctx.lineWidth = 1
    ctx.setLineDash([4, 3])
    ctx.strokeRect(x + 0.5, y + 0.5, w, h)
    ctx.setLineDash([])
  }

  // selected seats: cyan ring; hovered seat: white ring
  ctx.lineWidth = 1
  if (arena.selectedTickets.size) {
    ctx.strokeStyle = COLORS.cyan
    ctx.fillStyle = COLORS.cyan
    for (const id of arena.selectedTickets) {
      const i = seats.indexOf(id)
      if (i === undefined) continue
      const x = seats.x[i] * view.scale + view.offsetX
      const y = seats.y[i] * view.scale + view.offsetY
      // ring plus a wash so a picked seat still reads at fit scale, where the ring is a pixel wide
      ctx.globalAlpha = 0.35
      fillSeat(ctx, x - half - 0.5, y - half - 0.5, s + 1, s + 1)
      ctx.globalAlpha = 1
      ctx.strokeRect(x - half - 2, y - half - 2, s + 4, s + 4)
    }
  }
  const h = pick.hover.value
  if (h) {
    const x = seats.x[h.index] * view.scale + view.offsetX
    const y = seats.y[h.index] * view.scale + view.offsetY
    ctx.strokeStyle = COLORS.fg
    ctx.strokeRect(x - half - 2, y - half - 2, s + 4, s + 4)
  }
}

function canvasPoint(e: PointerEvent | MouseEvent): [number, number] {
  const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
  return [e.clientX - r.left, e.clientY - r.top]
}

const traceTicket = ref<number | null>(null)

// crosshair while shift is held so the box-select affordance shows before the drag starts
const shiftHeld = ref(false)
function onKey(e: KeyboardEvent): void {
  if (e.key === 'Shift') shiftHeld.value = e.type === 'keydown'
}
function onBlur(): void {
  shiftHeld.value = false
  pick.cancelBox()
}
onMounted(() => {
  window.addEventListener('keydown', onKey)
  window.addEventListener('keyup', onKey)
  window.addEventListener('blur', onBlur)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKey)
  window.removeEventListener('keyup', onKey)
  window.removeEventListener('blur', onBlur)
  gsap.killTweensOf(wash)
})
const cursorClass = computed(() => (pick.box.value || shiftHeld.value ? 'cursor-crosshair' : 'cursor-grab active:cursor-grabbing'))

function onDown(e: PointerEvent): void {
  if (e.button === 0 && e.shiftKey) {
    // shift+drag draws a selection box instead of panning
    const [x, y] = canvasPoint(e)
    pick.beginBox(x, y)
    ;(e.currentTarget as HTMLElement).setPointerCapture(e.pointerId)
    return
  }
  map.onPointerDown(e)
}

function onMove(e: PointerEvent): void {
  const [x, y] = canvasPoint(e)
  if (pick.box.value) {
    pick.updateBox(x, y)
    return
  }
  map.onPointerMove(e)
  pick.onPointerMove(x, y)
}

function onUp(e: PointerEvent): void {
  if (pick.box.value) {
    if (!pick.endBox(true)) {
      const [x, y] = canvasPoint(e)
      pick.onClick(x, y, true)
    }
    return
  }
  const dragged = map.onPointerUp()
  if (dragged || e.button !== 0) return
  const [x, y] = canvasPoint(e)
  pick.onClick(x, y, e.shiftKey)
}

function onCancel(): void {
  pick.cancelBox()
  map.onPointerUp()
}

/** Right-click a seat: attempt trace of the latest job that touched it. */
function onContextMenu(e: MouseEvent): void {
  e.preventDefault()
  const [x, y] = canvasPoint(e)
  const ticketId = pick.seatAt(x, y)
  if (ticketId !== null) traceTicket.value = ticketId
}

const fx = useSeatAnimations({
  seats: () => arena.seats,
  palette: () => palette.value,
  markSeatsDirty: (i) => map.markSeatsDirty(i),
  requestFrame: () => map.requestFrame(),
  runSelection: (runId) => (run.current?.id === runId ? run.current.selection : null),
  queuedColor: () => arena.platformColor(run.current?.targetPlatform) ?? COLORS.muted,
  arenaWidth: arena.arenaSize.width,
  arenaHeight: arena.arenaSize.height,
})

// selection wash fades in and out instead of snapping (hidden during a run: the colors are the data)
const wash = { alpha: 0 }
watch(
  () => (arena.selectedSections.size > 0 && !run.isActive ? 0.22 : 0),
  (alpha) => {
    gsap.killTweensOf(wash)
    gsap.to(wash, { alpha, duration: 0.35, ease: 'power2.out', onUpdate: () => map.requestFrame() })
  },
)

// picking sections in the dropdown zooms the map to them
watch(
  () => arena.focus.seq,
  () => {
    const seats = arena.seats
    const ids = new Set(arena.focus.ids)
    if (!seats || ids.size === 0) {
      map.fit(true)
      return
    }
    let minX = Infinity
    let minY = Infinity
    let maxX = -Infinity
    let maxY = -Infinity
    for (let i = 0; i < seats.size; i++) {
      if (!ids.has(seats.sectionId[i])) continue
      const x = seats.x[i]
      const y = seats.y[i]
      if (x < minX) minX = x
      if (x > maxX) maxX = x
      if (y < minY) minY = y
      if (y > maxY) maxY = y
    }
    if (minX < maxX) map.zoomToBounds(minX - 6, minY - 6, maxX + 6, maxY + 6)
  },
)

watch(() => arena.seats, (seats) => {
  map.invalidate()
  if (seats) {
    fx.revealSeats()
    map.pullBack()
  }
})
watch(() => arena.sections.map((s) => s.status).join(), () => map.requestFrame())
watch([() => arena.selectedSections, () => arena.selectedTickets, () => run.isActive], () => map.requestFrame())

const zoomLabel = computed(() => `${Math.round((map.view.scale / map.view.fitScale) * 100)}%`)

</script>

<template>
  <div ref="container" class="relative min-h-0 flex-1 overflow-hidden rounded-lg">
    <canvas
      ref="canvas"
      class="block touch-none select-none"
      :class="cursorClass"
      @wheel="map.onWheel"
      @pointerdown="onDown"
      @pointermove="onMove"
      @pointerup="onUp"
      @pointercancel="onCancel"
      @pointerleave="pick.onPointerLeave"
      @dblclick="map.fit(true)"
      @contextmenu="onContextMenu"
    />
    <JobTracePopover v-if="traceTicket !== null" :ticket-id="traceTicket" @close="traceTicket = null" />
    <SeatTooltip
      v-if="pick.hover.value && arena.seats"
      :seats="arena.seats"
      :index="pick.hover.value.index"
      :x="pick.hover.value.x"
      :y="pick.hover.value.y"
      :overlay="fx.overlayOf(pick.hover.value.index)"
      :bounds="{ width: map.view.width, height: map.view.height }"
    />
    <div v-if="!arena.seats" class="absolute inset-0 grid place-items-center text-xs text-muted">
      {{ arena.seatsError ?? 'loading seats…' }}
    </div>
    <div class="absolute right-2 bottom-2 flex items-center gap-1 rounded-md border border-border bg-panel/90 p-1 text-xs backdrop-blur">
      <button type="button" class="size-6 rounded hover:bg-panel-2" title="Zoom out" @click="map.zoomOut">−</button>
      <span class="w-10 text-center text-[11px] text-muted tabular-nums">{{ zoomLabel }}</span>
      <button type="button" class="size-6 rounded hover:bg-panel-2" title="Zoom in" @click="map.zoomIn">+</button>
      <button type="button" class="rounded px-1.5 text-[11px] text-muted hover:bg-panel-2" title="Fit (double-click the map)" @click="map.fit(true)">fit</button>
    </div>
  </div>
</template>
