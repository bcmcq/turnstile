import { describe, expect, it } from 'vitest'
import type { SeatsPayload } from '@/api/types'
import { SeatTable } from '@/lib/seatTable'
import { SpatialGrid } from '@/lib/spatialGrid'

// [ticketId, sectionId, x·10, y·10, state, platformId, rowLabel, seatNo, priceCents]
const payload: SeatsPayload = {
  columns: [],
  rows: [
    [1, 10, 100, 200, 0, 0, 'A', 1, 5000],
    [2, 10, 150, 200, 0, 0, 'A', 2, 5000],
    [3, 11, 900, 900, 2, 0, 'B', 1, 8000],
  ],
}

describe('SeatTable', () => {
  it('unpacks tenths into arena units and indexes by ticket', () => {
    const t = new SeatTable(payload)
    expect(t.size).toBe(3)
    expect(t.x[0]).toBeCloseTo(10)
    expect(t.y[1]).toBeCloseTo(20)
    expect(t.indexOf(3)).toBe(2)
    expect(t.indexOf(99)).toBeUndefined()
  })

  it('updates a known ticket in place and ignores unknown ones', () => {
    const t = new SeatTable(payload)
    expect(t.update(2, 3, 1, 6500)).toBe(1)
    expect(t.state[1]).toBe(3)
    expect(t.platformId[1]).toBe(1)
    expect(t.priceCents[1]).toBe(6500)
    expect(t.update(99, 3, 1, 6500)).toBeUndefined()
  })
})

describe('SpatialGrid', () => {
  it('returns the nearest seat within the radius and -1 beyond it', () => {
    const grid = new SpatialGrid(new SeatTable(payload), 100, 100)
    expect(grid.nearest(14, 20, 3)).toBe(1) // closer to seat 2 at x=15 than seat 1 at x=10
    expect(grid.nearest(10.4, 20, 3)).toBe(0)
    expect(grid.nearest(50, 50, 3)).toBe(-1)
  })
})
