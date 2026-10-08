<script setup lang="ts">
import ArenaCanvas from '@/components/arena/ArenaCanvas.vue'
import Chip from '@/components/ui/Chip.vue'
import Panel from '@/components/ui/Panel.vue'
import { fmtInt } from '@/lib/format'
import { useArenaStore } from '@/stores/arena'

const arena = useArenaStore()
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
      <span class="font-medium">&nbsp;</span>
    </footer>
  </Panel>
</template>
