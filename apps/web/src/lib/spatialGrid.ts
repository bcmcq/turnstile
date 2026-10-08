import type { SeatTable } from '@/lib/seatTable'

/** Uniform grid over arena units, CSR-packed, so a hover costs a handful of distance checks. */
export class SpatialGrid {
  private readonly seats: SeatTable
  private readonly cell: number
  private readonly cols: number
  private readonly rows: number
  private readonly cellStart: Int32Array
  private readonly items: Int32Array

  constructor(seats: SeatTable, width: number, height: number, cell = 8) {
    this.seats = seats
    this.cell = cell
    this.cols = Math.ceil(width / cell) + 1
    this.rows = Math.ceil(height / cell) + 1
    const counts = new Int32Array(this.cols * this.rows)
    const key = (i: number) => Math.floor(seats.y[i] / cell) * this.cols + Math.floor(seats.x[i] / cell)
    for (let i = 0; i < seats.size; i++) counts[key(i)]++
    this.cellStart = new Int32Array(this.cols * this.rows + 1)
    for (let c = 0; c < counts.length; c++) this.cellStart[c + 1] = this.cellStart[c] + counts[c]
    const fill = this.cellStart.slice(0, -1)
    this.items = new Int32Array(seats.size)
    for (let i = 0; i < seats.size; i++) this.items[fill[key(i)]++] = i
  }

  /** Nearest seat index within `radius` units of (x, y), or -1. */
  nearest(x: number, y: number, radius: number): number {
    const c0 = Math.floor((x - radius) / this.cell)
    const c1 = Math.floor((x + radius) / this.cell)
    const r0 = Math.floor((y - radius) / this.cell)
    const r1 = Math.floor((y + radius) / this.cell)
    let best = -1
    let bestD = radius * radius
    for (let r = Math.max(0, r0); r <= Math.min(this.rows - 1, r1); r++) {
      for (let c = Math.max(0, c0); c <= Math.min(this.cols - 1, c1); c++) {
        const k = r * this.cols + c
        for (let p = this.cellStart[k]; p < this.cellStart[k + 1]; p++) {
          const i = this.items[p]
          const dx = this.seats.x[i] - x
          const dy = this.seats.y[i] - y
          const d = dx * dx + dy * dy
          if (d < bestD) {
            bestD = d
            best = i
          }
        }
      }
    }
    return best
  }
}
