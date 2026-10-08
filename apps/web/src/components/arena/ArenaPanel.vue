<script setup lang="ts">
import ArenaCanvas from '@/components/arena/ArenaCanvas.vue'
import Chip from '@/components/ui/Chip.vue'
import Panel from '@/components/ui/Panel.vue'
import { fmtInt } from '@/lib/format'
import { useArenaStore } from '@/stores/arena'
import { useRunStore } from '@/stores/run'

const arena = useArenaStore()
const run = useRunStore()
</script>

<template>
  <Panel class="flex-1" :title="`Arena · ${fmtInt(arena.seatCount)} seats · ${arena.sections.length} sections`">
    <template #header>
      <div class="flex items-center gap-2">
        <Chip v-for="p in arena.platforms" :key="p.code" :label="p.name" :tone="p.code === 'tixhub' ? 'cyan' : p.code === 'seatswap' ? 'violet' : 'amber'" />
        <Chip label="In flight" tone="orange" />
        <Chip label="Failed" tone="red" />
        <Chip label="Sold" tone="fg" />
      </div>
    </template>
    <ArenaCanvas />
    <footer class="mt-2 flex items-center justify-between text-[11px]">
      <span class="text-muted">Selected: {{ arena.selectedSections.size }} sections + {{ arena.selectedTickets.size }} seats · {{ fmtInt(arena.selectedSeatTotal) }} tickets</span>
      <span v-if="run.current" class="font-medium tabular-nums">{{ fmtInt(run.current.counters.completed) }} moved · {{ fmtInt(run.current.counters.in_flight) }} in flight · {{ fmtInt(run.current.counters.dead_lettered) }} failed</span>
    </footer>
  </Panel>
</template>
