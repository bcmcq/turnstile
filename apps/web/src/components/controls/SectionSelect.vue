<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import type { SectionTier, SectionView } from '@/api/types'
import { fmtInt } from '@/lib/format'
import { useArenaStore } from '@/stores/arena'

const props = defineProps<{ disabled?: boolean }>()
const arena = useArenaStore()
const open = ref(false)
const root = ref<HTMLElement | null>(null)

const tiers: { tier: SectionTier; label: string }[] = [
  { tier: 'floor', label: 'Floor' },
  { tier: 'lower', label: 'Lower bowl' },
  { tier: 'upper', label: 'Upper bowl' },
]
const byTier = computed(() => tiers.map((t) => ({ ...t, sections: arena.sections.filter((s) => s.tier === t.tier) })))

const summary = computed(() => {
  const n = arena.selectedSections.size
  if (n === 0 && arena.selectedTickets.size === 0) return 'Select sections…'
  const codes = [...arena.selectedSections].map((id) => arena.sectionById.get(id)?.code ?? '').sort()
  const label = n === 0 ? '' : n <= 3 ? codes.join(', ') : `${n} sections`
  const seats = arena.selectedTickets.size ? ` + ${arena.selectedTickets.size} seats` : ''
  return `${label}${seats} · ${fmtInt(arena.selectedSeatTotal)} seats`
})

function toggle(s: SectionView): void {
  arena.toggleSection(s.id, true)
}
function selectTier(tier: SectionTier): void {
  const ids = arena.sections.filter((s) => s.tier === tier).map((s) => s.id)
  const all = ids.every((id) => arena.selectedSections.has(id))
  arena.setSections(all ? [...arena.selectedSections].filter((id) => !ids.includes(id)) : [...new Set([...arena.selectedSections, ...ids])])
}
function onDocumentClick(e: MouseEvent): void {
  if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false
}
onMounted(() => document.addEventListener('mousedown', onDocumentClick))
onBeforeUnmount(() => document.removeEventListener('mousedown', onDocumentClick))
</script>

<template>
  <div ref="root" class="relative w-[196px]">
    <button
      type="button"
      :disabled="props.disabled"
      class="flex w-full items-center justify-between rounded-lg border border-border bg-panel-2 px-3 py-2 text-left text-[13px] font-medium disabled:cursor-not-allowed disabled:opacity-50"
      :class="open ? 'border-tixhub/60' : ''"
      @click="open = !open"
    >
      <span class="truncate">{{ summary }}</span>
      <span class="ml-2 text-xs text-muted">▾</span>
    </button>
    <div v-if="open" class="absolute bottom-full left-0 z-20 mb-2 w-[360px] rounded-xl border border-border bg-panel p-3 shadow-[0_12px_30px_rgba(0,0,0,0.5)]">
      <div class="mb-2 flex items-center justify-between text-[11px]">
        <span class="text-muted">Click to toggle · also click sections on the map</span>
        <button type="button" class="text-muted hover:text-fg" @click="arena.clearSelection()">Clear</button>
      </div>
      <div v-for="group in byTier" :key="group.tier" class="mb-2 last:mb-0">
        <button type="button" class="mb-1 text-[10px] font-medium tracking-[0.08em] text-muted uppercase hover:text-fg" @click="selectTier(group.tier)">{{ group.label }} · all</button>
        <div class="grid grid-cols-8 gap-1">
          <button
            v-for="s in group.sections"
            :key="s.id"
            type="button"
            class="rounded-md border px-1 py-1 text-[11px] font-medium tabular-nums"
            :class="arena.selectedSections.has(s.id) ? 'border-tixhub/60 bg-tixhub/15 text-tixhub' : s.status === 'closed' ? 'border-border text-dim line-through' : 'border-border bg-panel-2 text-fg hover:border-muted'"
            :title="`${s.code} · ${fmtInt(s.seatCount)} seats · ${s.status}`"
            @click="toggle(s)"
          >
            {{ s.tier === 'floor' ? 'FLR' : s.code }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
