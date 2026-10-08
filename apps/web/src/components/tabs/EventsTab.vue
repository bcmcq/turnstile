<script setup lang="ts">
import { computed } from 'vue'
import Chip from '@/components/ui/Chip.vue'
import type { ChipTone } from '@/components/ui/Chip.vue'
import Label from '@/components/ui/Label.vue'
import Sparkline from '@/components/metrics/Sparkline.vue'
import { COLORS } from '@/lib/arenaPalette'
import { describeEvent, fmtEventTime, toneClass } from '@/lib/eventText'
import { fmtInt } from '@/lib/format'
import { useArenaStore } from '@/stores/arena'
import { useLogStore } from '@/stores/log'
import { useMetricsStore } from '@/stores/metrics'
import { useRunStore } from '@/stores/run'

const ROWS = 40
const TICKER = 14

const arena = useArenaStore()
const log = useLogStore()
const metrics = useMetricsStore()
const run = useRunStore()

const rows = computed(() => log.events.slice(0, ROWS).map((e, i) => ({ key: `${e.ts}-${i}`, time: fmtEventTime(e.ts), ...describeEvent(e), raw: e })))
const ticker = computed(() => log.events.slice(0, TICKER).map((e, i) => ({ key: `${e.ts}-${i}`, type: e.type, tone: describeEvent(e).tone })))

const replay = computed(() => {
  const r = run.current
  if (!r || r.type !== 'replay') return null
  return { reenqueued: r.totalJobs, prevented: r.counters.skipped, movedTwice: r.counters.completed, done: !['pending', 'dispatching', 'running', 'paused'].includes(r.status) }
})

function platformTone(code: string | null, ticketId: number | null): ChipTone | null {
  let c = code
  if (!c && ticketId !== null && arena.seats) {
    const i = arena.seats.indexOf(ticketId)
    if (i !== undefined) c = arena.platformById.get(arena.seats.platformId[i])?.code ?? null
  }
  return c === 'tixhub' ? 'cyan' : c === 'seatswap' ? 'violet' : c === 'passmarket' ? 'amber' : c === 'house' ? 'muted' : null
}
function platformName(code: string | null, ticketId: number | null): string {
  if (code && code !== 'house') return arena.platformByCode.get(code as never)?.name ?? code
  if (code === 'house') return 'house'
  if (ticketId !== null && arena.seats) {
    const i = arena.seats.indexOf(ticketId)
    if (i !== undefined) return arena.platformById.get(arena.seats.platformId[i])?.name ?? 'house'
  }
  return ''
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-2">
    <div class="flex items-end justify-between gap-3">
      <div class="flex flex-col gap-0.5">
        <Label text="SSE events / sec" />
        <span class="text-2xl leading-none font-semibold tabular-nums">{{ fmtInt(metrics.eventsPerSec) }}</span>
      </div>
      <div class="w-[150px]"><Sparkline :points="metrics.series.eventsPerSec" :color="COLORS.cyan" :height="32" /></div>
    </div>

    <div class="flex gap-1.5 overflow-hidden whitespace-nowrap">
      <TransitionGroup name="ticker">
        <span v-for="t in ticker" :key="t.key" class="shrink-0 rounded px-1.5 py-0.5 text-[10px] font-medium" :class="[toneClass[t.tone], 'bg-current/10']">{{ t.type }}</span>
      </TransitionGroup>
      <span v-if="ticker.length === 0" class="text-[10px] text-muted">no events yet</span>
    </div>

    <div v-if="replay" class="rounded-full bg-tixhub/15 px-2.5 py-1 text-[10px] font-medium text-tixhub">
      Replay · {{ fmtInt(replay.reenqueued) }} re-enqueued · {{ fmtInt(replay.prevented) }} duplicates prevented · {{ fmtInt(replay.movedTwice) }} moved twice{{ replay.done ? '' : ' · running' }}
    </div>

    <ul class="min-h-0 flex-1 divide-y divide-border overflow-y-auto">
      <li v-for="r in rows" :key="r.key" class="flex items-center gap-2 py-1 text-[11px]">
        <span class="w-[74px] shrink-0 text-[10px] text-muted tabular-nums">{{ r.time }}</span>
        <Chip v-if="platformTone(r.platform, r.ticketId)" :label="platformName(r.platform, r.ticketId)" :tone="platformTone(r.platform, r.ticketId) ?? 'muted'" />
        <span class="min-w-0 flex-1 truncate font-medium" :class="toneClass[r.tone]" :title="r.text">{{ r.text }}</span>
        <span v-if="r.ticketId !== null" class="shrink-0 text-[10px] text-muted tabular-nums">TKT-{{ r.ticketId }}</span>
        <span v-if="r.attempt" class="w-8 shrink-0 text-right text-[10px] text-muted tabular-nums">{{ r.attempt }}</span>
      </li>
      <li v-if="rows.length === 0" class="py-6 text-center text-[11px] text-muted">Start a run to see the event stream.</li>
    </ul>
    <p class="text-[10px] text-muted">Idempotency key = ticket + run · a replayed job finds the key and exits without calling the platform</p>
  </div>
</template>

<style scoped>
.ticker-enter-active {
  transition: all 0.25s ease-out;
}
.ticker-enter-from {
  opacity: 0;
  transform: translateX(-6px);
}
.ticker-leave-active {
  display: none;
}
</style>
