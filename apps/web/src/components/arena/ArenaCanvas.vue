<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import SeatTooltip from '@/components/arena/SeatTooltip.vue'
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

  // selected sections: faint wash over their seats + highlighted label (wash only while idle: during a run the colors are the data)
  if (arena.selectedSections.size && !run.isActive) {
    ctx.fillStyle = COLORS.cyan
    ctx.globalAlpha = 0.22
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

  // selected seats: cyan ring; hovered seat: white ring
  ctx.lineWidth = 1
  if (arena.selectedTickets.size) {
    ctx.strokeStyle = COLORS.cyan
    for (const id of arena.selectedTickets) {
      const i = seats.indexOf(id)
      if (i === undefined) continue
      const x = seats.x[i] * view.scale + view.offsetX
      const y = seats.y[i] * view.scale + view.offsetY
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

function onMove(e: PointerEvent): void {
  map.onPointerMove(e)
  const [x, y] = canvasPoint(e)
  pick.onPointerMove(x, y)
}

function onUp(e: PointerEvent): void {
  const dragged = map.onPointerUp()
  if (dragged || e.button !== 0) return
  const [x, y] = canvasPoint(e)
  pick.onClick(x, y, e.shiftKey)
}

const fx = useSeatAnimations({
  seats: () => arena.seats,
  palette: () => palette.value,
  markSeatsDirty: (i) => map.markSeatsDirty(i),
  requestFrame: () => map.requestFrame(),
  arenaWidth: arena.arenaSize.width,
  arenaHeight: arena.arenaSize.height,
})

watch(() => arena.seats, (seats) => {
  map.invalidate()
  if (seats) fx.revealSeats()
})
watch(() => arena.sections.map((s) => s.status).join(), () => map.requestFrame())
watch([() => arena.selectedSections, () => arena.selectedTickets, () => run.isActive], () => map.requestFrame())

const zoomLabel = computed(() => `${Math.round((map.view.scale / map.view.fitScale) * 100)}%`)

defineExpose({ map })
</script>

<template>
  <div ref="container" class="relative min-h-0 flex-1 overflow-hidden rounded-lg">
    <canvas
      ref="canvas"
      class="block cursor-grab touch-none select-none active:cursor-grabbing"
      @wheel="map.onWheel"
      @pointerdown="map.onPointerDown"
      @pointermove="onMove"
      @pointerup="onUp"
      @pointercancel="map.onPointerUp"
      @pointerleave="pick.onPointerLeave"
      @dblclick="map.fit"
    />
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
      <button type="button" class="rounded px-1.5 text-[11px] text-muted hover:bg-panel-2" title="Fit (double-click the map)" @click="map.fit">fit</button>
    </div>
  </div>
</template>
