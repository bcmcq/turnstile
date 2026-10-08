import type { LogEvent, SeatUpdate } from '@/api/types'
import { createEmitter } from '@/lib/emitter'

/** Live stream fan-out for consumers that are not stores (the canvas). */
export const liveBus = createEmitter<{
  seats: SeatUpdate[]
  log: LogEvent[]
  reset: undefined
}>()
