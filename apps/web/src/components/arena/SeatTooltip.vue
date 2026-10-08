<script setup lang="ts">
import { computed } from 'vue'
import { TicketState } from '@/api/types'
import type { ChipTone } from '@/components/ui/Chip.vue'
import Chip from '@/components/ui/Chip.vue'
import { fmtMoney } from '@/lib/format'
import type { SeatTable } from '@/lib/seatTable'
import { useArenaStore } from '@/stores/arena'

import type { SeatOverlayKind } from '@/composables/useSeatAnimations'

const props = defineProps<{
  seats: SeatTable
  index: number
  x: number
  y: number
  overlay: SeatOverlayKind
  bounds: { width: number; height: number }
}>()

const arena = useArenaStore()

const ticketId = computed(() => props.seats.ticketId[props.index])
const section = computed(() => arena.sectionById.get(props.seats.sectionId[props.index]))
const platform = computed(() => arena.platformById.get(props.seats.platformId[props.index]) ?? null)
const state = computed(() => props.seats.state[props.index])

const stateChip = computed<{ label: string; tone: ChipTone }>(() => {
  switch (state.value) {
    case TicketState.Listed:
      return { label: 'Listed', tone: platformTone(platform.value?.code) }
    case TicketState.Sold:
      return { label: 'Sold', tone: 'fg' }
    case TicketState.Closed:
      return { label: 'Closed', tone: 'muted' }
    default:
      return { label: 'Available · house', tone: 'muted' }
  }
})
const overlayChip = computed<{ label: string; tone: ChipTone } | null>(() => {
  switch (props.overlay) {
    case 'queued':
      return { label: 'Queued', tone: 'muted' }
    case 'in_flight':
      return { label: 'In flight', tone: 'orange' }
    case 'retry_wait':
      return { label: 'Retry wait', tone: 'orange' }
    case 'conflict':
      return { label: 'Version conflict', tone: 'amber' }
    case 'failed':
      return { label: 'Dead letter', tone: 'red' }
    default:
      return null
  }
})
const inSelection = computed(() => arena.selectedTickets.has(ticketId.value) || arena.selectedSections.has(props.seats.sectionId[props.index]))

function platformTone(code: string | undefined): ChipTone {
  return code === 'tixhub' ? 'cyan' : code === 'seatswap' ? 'violet' : code === 'passmarket' ? 'amber' : 'muted'
}

const sectionLabel = computed(() => `Sec ${section.value?.code ?? '?'}`)
const dotColor = computed(() => (state.value === TicketState.Listed ? (platform.value?.color ?? '#243040') : state.value === TicketState.Sold ? '#e6edf3' : state.value === TicketState.Closed ? '#151c26' : '#243040'))

/** Keep the card inside the canvas: flip left/up near the edges. */
const style = computed(() => {
  const w = 236
  const h = 150
  const left = props.x + 14 + w > props.bounds.width ? props.x - 14 - w : props.x + 14
  const top = props.y - h - 10 < 0 ? props.y + 14 : props.y - h - 10
  return { left: `${Math.max(4, left)}px`, top: `${Math.max(4, top)}px`, width: `${w}px` }
})
</script>

<template>
  <div class="pointer-events-none absolute z-10 rounded-lg border border-border bg-panel-2 p-3 text-[11px] shadow-[0_8px_20px_rgba(0,0,0,0.45)]" :style="style">
    <div class="flex items-center gap-2 text-xs font-semibold">
      <span class="size-2 rounded-[2px]" :style="{ background: dotColor }" />
      {{ sectionLabel }} · Row {{ seats.rowLabel[index] }} · Seat {{ seats.seatNo[index] }}
    </div>
    <div class="mt-1.5 flex flex-wrap gap-1.5">
      <Chip :label="stateChip.label" :tone="stateChip.tone" />
      <Chip v-if="platform && state === TicketState.Listed" :label="platform.name" :tone="platformTone(platform.code)" />
      <Chip v-if="overlayChip" :label="overlayChip.label" :tone="overlayChip.tone" />
      <Chip v-if="inSelection" label="in selection" tone="cyan" />
    </div>
    <dl class="mt-2 grid grid-cols-[72px_1fr] gap-y-1 border-t border-border pt-2">
      <dt class="text-muted">Price</dt>
      <dd>{{ fmtMoney(seats.priceCents[index]) }}</dd>
      <dt class="text-muted">Ticket</dt>
      <dd class="tabular-nums">#{{ ticketId }}</dd>
      <dt class="text-muted">Section</dt>
      <dd>{{ section?.code }} · {{ section?.tier }} · {{ section?.status }}</dd>
    </dl>
    <p class="mt-2 text-[10px] text-muted">click: seat · shift+click: add · shift+drag: box · label: section · right-click: trace</p>
  </div>
</template>
