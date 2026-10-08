import { reactive, ref, watch } from 'vue'
import type { ArenaView } from '@/composables/useArenaCanvas'
import { SpatialGrid } from '@/lib/spatialGrid'
import type { SeatTable } from '@/lib/seatTable'
import { useArenaStore } from '@/stores/arena'

export interface HoverState {
  index: number
  /** CSS px within the canvas */
  x: number
  y: number
}

export interface ArenaInteractionOptions {
  seats: () => SeatTable | null
  view: ArenaView
  toUnit: (sx: number, sy: number) => [number, number]
  toScreen: (ux: number, uy: number) => [number, number]
  requestFrame: () => void
  arenaWidth: number
  arenaHeight: number
}

const SECTION_PICK_UNITS = 14
const LABEL_PICK_PX = 16

export interface BoxState {
  /** CSS px within the canvas */
  x0: number
  y0: number
  x1: number
  y1: number
}

/** Hover → nearest seat; click → seat, section label, or the section under the pointer; shift+drag → box. Shift adds. */
export function useArenaInteraction(opts: ArenaInteractionOptions) {
  const arena = useArenaStore()
  const hover = ref<HoverState | null>(null)
  const hoveredSection = ref<number | null>(null)
  const box = ref<BoxState | null>(null)
  let grid: SpatialGrid | null = null
  const state = reactive({ gridReady: false })

  watch(
    () => opts.seats(),
    (seats) => {
      grid = seats ? new SpatialGrid(seats, opts.arenaWidth, opts.arenaHeight) : null
      state.gridReady = grid !== null
    },
    { immediate: true },
  )

  function seatPickRadius(): number {
    // half a seat pitch, or 6 CSS px, whichever is larger in unit space
    return Math.max(2.7, 6 / opts.view.scale)
  }

  function labelAt(sx: number, sy: number): number | null {
    for (const s of arena.sections) {
      const [lx, ly] = opts.toScreen(s.geometry.labelX, s.geometry.labelY)
      if (Math.abs(lx - sx) <= LABEL_PICK_PX && Math.abs(ly - sy) <= LABEL_PICK_PX * 0.7) return s.id
    }
    return null
  }

  function onPointerMove(sx: number, sy: number): void {
    if (!grid) return
    const [ux, uy] = opts.toUnit(sx, sy)
    const i = grid.nearest(ux, uy, seatPickRadius())
    const next: HoverState | null = i >= 0 ? { index: i, x: sx, y: sy } : null
    const label = i >= 0 ? null : labelAt(sx, sy)
    const changed = (hover.value?.index ?? -1) !== (next?.index ?? -1) || hoveredSection.value !== label
    hover.value = next
    hoveredSection.value = label
    if (changed) opts.requestFrame()
  }

  function onPointerLeave(): void {
    if (hover.value || hoveredSection.value !== null) {
      hover.value = null
      hoveredSection.value = null
      opts.requestFrame()
    }
  }

  /** Called on a click that was not a drag. */
  function onClick(sx: number, sy: number, additive: boolean): void {
    const seats = opts.seats()
    if (!grid || !seats) return
    const label = labelAt(sx, sy)
    if (label !== null) {
      arena.toggleSection(label, additive)
      opts.requestFrame()
      return
    }
    const [ux, uy] = opts.toUnit(sx, sy)
    const seat = grid.nearest(ux, uy, seatPickRadius())
    if (seat >= 0) {
      arena.toggleTicket(seats.ticketId[seat], additive)
      opts.requestFrame()
      return
    }
    const near = grid.nearest(ux, uy, SECTION_PICK_UNITS)
    if (near >= 0) {
      arena.toggleSection(seats.sectionId[near], additive)
      opts.requestFrame()
    }
  }

  function beginBox(sx: number, sy: number): void {
    box.value = { x0: sx, y0: sy, x1: sx, y1: sy }
    hover.value = null
  }

  function updateBox(sx: number, sy: number): void {
    if (!box.value) return
    box.value = { ...box.value, x1: sx, y1: sy }
    opts.requestFrame()
  }

  /** Select every seat inside the box; a box smaller than a few pixels is treated as a click. */
  function endBox(additive: boolean): boolean {
    const b = box.value
    box.value = null
    const seats = opts.seats()
    if (!b || !seats) return false
    if (Math.abs(b.x1 - b.x0) < 4 && Math.abs(b.y1 - b.y0) < 4) {
      opts.requestFrame()
      return false
    }
    const [ax, ay] = opts.toUnit(Math.min(b.x0, b.x1), Math.min(b.y0, b.y1))
    const [bx, by] = opts.toUnit(Math.max(b.x0, b.x1), Math.max(b.y0, b.y1))
    const ids: number[] = []
    for (let i = 0; i < seats.size; i++) {
      const x = seats.x[i]
      const y = seats.y[i]
      if (x >= ax && x <= bx && y >= ay && y <= by) ids.push(seats.ticketId[i])
    }
    arena.selectTickets(ids, additive)
    opts.requestFrame()
    return true
  }

  /** Seat under the pointer, if any (for the trace popover). */
  function seatAt(sx: number, sy: number): number | null {
    const seats = opts.seats()
    if (!grid || !seats) return null
    const [ux, uy] = opts.toUnit(sx, sy)
    const seat = grid.nearest(ux, uy, seatPickRadius())
    return seat >= 0 ? seats.ticketId[seat] : null
  }

  return { hover, hoveredSection, box, onPointerMove, onPointerLeave, onClick, beginBox, updateBox, endBox, seatAt, state }
}
