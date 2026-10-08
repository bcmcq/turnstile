import { TicketState } from '@/api/types'
import type { PlatformView } from '@/api/types'

/** Mirrors ArenaGeometry in the API: the floor rectangle the rings wrap around. */
export const FLOOR = { width: 300, height: 140, radius: 12 } as const
/** Seat dot edge in arena units (pitch is 2, so dots never touch). */
export const SEAT_SIZE = 1.4

export const COLORS = {
  bg: '#0a0e13',
  panel: '#111721',
  panel2: '#182230',
  border: '#1f2a38',
  fg: '#e6edf3',
  muted: '#7d8b9b',
  dim: '#2a3644',
  seat: '#243040',
  seatClosed: '#151c26',
  sold: '#e6edf3',
  inflight: '#fb923c',
  ok: '#34d399',
  fail: '#f87171',
  amber: '#fbbf24',
  cyan: '#22d3ee',
} as const

/**
 * Base color of a seat from its ticket state. Palette index 0..2 are states, 3+ are platforms
 * (index 3 + platform position), so the canvas can batch fillStyle changes.
 */
export class SeatPalette {
  readonly colors: string[]
  private readonly platformSlot = new Map<number, number>()

  constructor(platforms: PlatformView[]) {
    this.colors = [COLORS.seat, COLORS.seatClosed, COLORS.sold]
    platforms.forEach((p, i) => {
      this.platformSlot.set(p.id, 3 + i)
      this.colors.push(p.color)
    })
  }

  slot(state: number, platformId: number): number {
    switch (state) {
      case TicketState.Closed:
        return 1
      case TicketState.Sold:
        return 2
      case TicketState.Listed:
        return this.platformSlot.get(platformId) ?? 0
      default:
        return 0
    }
  }
}
