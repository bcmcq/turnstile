<script setup lang="ts">
export type ChipTone = 'cyan' | 'violet' | 'amber' | 'green' | 'red' | 'orange' | 'muted' | 'fg'

const props = withDefaults(defineProps<{ label: string; tone?: ChipTone; dot?: boolean }>(), { tone: 'muted', dot: true })

const tones: Record<ChipTone, { text: string; bg: string; dot: string }> = {
  cyan: { text: 'text-tixhub', bg: 'bg-tixhub/15', dot: 'bg-tixhub' },
  violet: { text: 'text-seatswap', bg: 'bg-seatswap/15', dot: 'bg-seatswap' },
  amber: { text: 'text-passmarket', bg: 'bg-passmarket/15', dot: 'bg-passmarket' },
  green: { text: 'text-ok', bg: 'bg-ok/15', dot: 'bg-ok' },
  red: { text: 'text-fail', bg: 'bg-fail/15', dot: 'bg-fail' },
  orange: { text: 'text-inflight', bg: 'bg-inflight/15', dot: 'bg-inflight' },
  muted: { text: 'text-muted', bg: 'bg-muted/15', dot: 'bg-muted' },
  fg: { text: 'text-fg', bg: 'bg-fg/15', dot: 'bg-fg' },
}
</script>

<template>
  <span class="inline-flex items-center gap-1.5 rounded-full px-2 py-0.5 text-[11px] font-medium whitespace-nowrap" :class="[tones[props.tone].text, tones[props.tone].bg]">
    <span v-if="props.dot" class="size-1.5 rounded-full" :class="tones[props.tone].dot" />
    {{ props.label }}
  </span>
</template>
