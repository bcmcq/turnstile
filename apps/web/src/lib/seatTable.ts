import type { SeatsPayload, TicketStateCode } from '@/api/types'

/**
 * Column store for every seat: one typed array per field, indexed 0..n-1, plus ticketId → index.
 * Even 100k seats fit in a few MB and the canvas can iterate them without allocating.
 */
export class SeatTable {
  readonly size: number
  readonly ticketId: Int32Array
  readonly sectionId: Int16Array
  /** logical arena units (the payload carries tenths) */
  readonly x: Float32Array
  readonly y: Float32Array
  readonly state: Uint8Array
  readonly platformId: Uint8Array
  readonly priceCents: Int32Array
  readonly seatNo: Uint16Array
  readonly rowLabel: string[]
  private readonly index: Map<number, number>

  constructor(payload: SeatsPayload) {
    const n = payload.rows.length
    this.size = n
    this.ticketId = new Int32Array(n)
    this.sectionId = new Int16Array(n)
    this.x = new Float32Array(n)
    this.y = new Float32Array(n)
    this.state = new Uint8Array(n)
    this.platformId = new Uint8Array(n)
    this.priceCents = new Int32Array(n)
    this.seatNo = new Uint16Array(n)
    this.rowLabel = new Array<string>(n)
    this.index = new Map()
    payload.rows.forEach((r, i) => {
      this.ticketId[i] = r[0]
      this.sectionId[i] = r[1]
      this.x[i] = r[2] / 10
      this.y[i] = r[3] / 10
      this.state[i] = r[4]
      this.platformId[i] = r[5]
      this.rowLabel[i] = r[6]
      this.seatNo[i] = r[7]
      this.priceCents[i] = r[8]
      this.index.set(r[0], i)
    })
  }

  indexOf(ticketId: number): number | undefined {
    return this.index.get(ticketId)
  }

  /** @returns the index, or undefined when the ticket is unknown */
  update(ticketId: number, state: TicketStateCode, platformId: number, priceCents: number): number | undefined {
    const i = this.index.get(ticketId)
    if (i === undefined) return undefined
    this.state[i] = state
    this.platformId[i] = platformId
    this.priceCents[i] = priceCents
    return i
  }
}
