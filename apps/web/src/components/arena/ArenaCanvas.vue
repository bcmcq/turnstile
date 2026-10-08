<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useArenaCanvas } from '@/composables/useArenaCanvas'
import { useSeatAnimations } from '@/composables/useSeatAnimations'
import { SeatPalette } from '@/lib/arenaPalette'
import { useArenaStore } from '@/stores/arena'

const arena = useArenaStore()
const canvas = ref<HTMLCanvasElement | null>(null)
const container = ref<HTMLElement | null>(null)
const palette = computed(() => new SeatPalette(arena.platforms))

const map = useArenaCanvas({
  canvas,
  container,
  arenaWidth: arena.arenaSize.width,
  arenaHeight: arena.arenaSize.height,
  seats: () => arena.seats,
  sections: () => arena.sections,
  palette: () => palette.value,
  overlay: (ctx, view) => fx.draw(ctx, view, map.seatPx()),
})

const fx = useSeatAnimations({
  seats: () => arena.seats,
  palette: () => palette.value,
  markSeatsDirty: (i) => map.markSeatsDirty(i),
  requestFrame: () => map.requestFrame(),
  arenaWidth: arena.arenaSize.width,
  arenaHeight: arena.arenaSize.height,
})

watch(() => arena.seats, () => map.invalidate())
watch(() => arena.sections.map((s) => s.status).join(), () => map.requestFrame())

const zoomLabel = computed(() => `${Math.round((map.view.scale / map.view.fitScale) * 100)}%`)

defineExpose({ map })
</script>

<template>
  <div ref="container" class="relative min-h-0 flex-1 overflow-hidden rounded-lg">
    <canvas
      ref="canvas"
      class="block cursor-grab touch-none select-none active:cursor-grabbing"
      @wheel="map.onWheel"
      @pointerdown="map.onPointerDown"
      @pointermove="map.onPointerMove"
      @pointerup="map.onPointerUp"
      @pointercancel="map.onPointerUp"
      @dblclick="map.fit"
    />
    <div v-if="!arena.seats" class="absolute inset-0 grid place-items-center text-xs text-muted">
      {{ arena.seatsError ?? 'loading 100,790 seats…' }}
    </div>
    <div class="absolute right-2 bottom-2 flex items-center gap-1 rounded-md border border-border bg-panel/90 p-1 text-xs backdrop-blur">
      <button type="button" class="size-6 rounded hover:bg-panel-2" title="Zoom out" @click="map.zoomOut">−</button>
      <span class="w-10 text-center text-[11px] text-muted tabular-nums">{{ zoomLabel }}</span>
      <button type="button" class="size-6 rounded hover:bg-panel-2" title="Zoom in" @click="map.zoomIn">+</button>
      <button type="button" class="rounded px-1.5 text-[11px] text-muted hover:bg-panel-2" title="Fit (double-click the map)" @click="map.fit">fit</button>
    </div>
  </div>
</template>
