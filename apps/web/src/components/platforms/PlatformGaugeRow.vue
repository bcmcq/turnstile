<script setup lang="ts">
import { COLORS } from '@/lib/arenaPalette'
import { computed } from 'vue'
import type { PlatformGauge } from '@/api/types'
import { fmtInt } from '@/lib/format'

const props = defineProps<{
  gauge: PlatformGauge
}>()

const fraction = computed(() => (props.gauge.capacity ? props.gauge.remainingTokens / props.gauge.capacity : 0))
const saturated = computed(() => fraction.value <= 0.05)
const pacing = computed(() => fraction.value < 0.25 && !saturated.value)
const barColor = computed(() => (saturated.value ? COLORS.fail : props.gauge.color))
/** Vendor limit in requests per second; the bar is the client bucket, the numbers read in the vendor's units. */
const perSec = computed(() => Math.max(1, Math.round(props.gauge.rateLimitPerMin / 60)))
const availablePerSec = computed(() => Math.round(fraction.value * perSec.value))
const refillSeconds = computed(() => ((props.gauge.capacity - props.gauge.remainingTokens) / Math.max(1, props.gauge.tokensPerSec)).toFixed(1))
const note = computed(() => {
  if (saturated.value) return `${props.gauge.name} bucket empty · refills ${props.gauge.tokensPerSec}/s · workers wait, no 429s sent`
  if (pacing.value) return `pacing · ${fmtInt(props.gauge.remainingTokens)} tokens left · full in ${refillSeconds.value}s`
  return null
})
</script>

<template>
  <div class="flex flex-col gap-1">
    <div class="flex items-center gap-2 text-[11px]">
      <span class="size-2 shrink-0 rounded-full" :style="{ background: gauge.color }" />
      <span class="w-[76px] text-xs font-medium" :style="{ color: gauge.color }">{{ gauge.name }}</span>
      <div class="relative h-2.5 flex-1 overflow-hidden rounded-full bg-dim" :title="`${gauge.remainingTokens} of ${gauge.capacity} tokens · ${gauge.tokensPerSec}/s refill · vendor limit ${fmtInt(gauge.rateLimitPerMin)}/min`">
        <div class="h-full rounded-full transition-[width] duration-300" :style="{ width: `${fraction * 100}%`, background: barColor }" />
        <span class="absolute top-0 h-full w-px bg-fg/40" :style="{ left: '80%' }" title="80% pacing threshold" />
      </div>
      <span class="w-[66px] text-right font-medium tabular-nums" :class="saturated ? 'text-fail' : 'text-fg'">{{ availablePerSec }} / {{ perSec }}/s</span>
      <span class="w-[64px] shrink-0 text-right font-medium tabular-nums whitespace-nowrap" :class="gauge.http429 > 0 ? 'text-passmarket' : 'text-muted'">{{ fmtInt(gauge.http429) }} ×429</span>
    </div>
    <p v-if="note" class="pl-4 text-[10px]" :class="saturated ? 'text-fail' : 'text-passmarket'">{{ note }}</p>
  </div>
</template>
