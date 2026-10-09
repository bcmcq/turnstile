<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Label from '@/components/ui/Label.vue'
import Toggle from '@/components/ui/Toggle.vue'
import { COLORS } from '@/lib/arenaPalette'
import { fmtInt } from '@/lib/format'
import { useMetricsStore } from '@/stores/metrics'
import { usePlatformsStore } from '@/stores/platforms'
import { useToastsStore } from '@/stores/toasts'

const DEFAULT_RPM = 3000
const metrics = useMetricsStore()
const platforms = usePlatformsStore()
const toasts = useToastsStore()

const local = ref(metrics.vendorRpm)
const dragging = ref(false)
// metrics ticks several times a second; only they may move the thumb when the user is not holding it
watch(() => metrics.vendorRpm, (v) => {
  if (!dragging.value) local.value = v
})
const perSec = computed(() => Math.round(local.value / 60))
/** Below the default the vendor is tighter than our pacing: amber warns that 429s are coming unless pacing follows. */
const color = computed(() => (local.value < DEFAULT_RPM && !metrics.paceToVendorLimit ? COLORS.amber : COLORS.cyan))

async function commit(rpm: number): Promise<void> {
  try {
    await platforms.setRateLimit(rpm)
    toasts.push('info', `Vendor limit ${fmtInt(rpm)} rpm on all platforms · ${metrics.paceToVendorLimit ? `workers pace to ${Math.round(rpm * 0.8)}/min` : `workers keep pacing to ${Math.round(DEFAULT_RPM * 0.8)}/min`}`)
  } catch (e) {
    toasts.fromError(e)
  }
}

async function togglePacing(follow: boolean): Promise<void> {
  try {
    await platforms.setPacing(follow)
    toasts.push('info', follow ? 'Workers pace to 80% of the vendor limit · no 429s in steady state' : `Pacing pinned to ${Math.round(DEFAULT_RPM * 0.8)}/min · vendor limits below that will return 429s`)
  } catch (e) {
    toasts.fromError(e)
  }
}
</script>

<template>
  <div class="flex flex-col gap-1.5">
    <div class="flex items-center justify-between gap-3">
      <span class="flex items-center gap-2">
        <Label text="Vendor limit" />
        <span class="text-[11px] font-semibold tabular-nums" :style="{ color }">{{ fmtInt(local) }} rpm · {{ perSec }}/s</span>
      </span>
      <Toggle :model-value="metrics.paceToVendorLimit" label="Pace to limit" :disabled="platforms.saving" title="On: workers follow the vendor limit at 80%. Off: workers stay at 40/s so a lower vendor limit produces real 429s and backoff" @update:model-value="togglePacing" />
    </div>
    <input
      type="range"
      min="300"
      max="6000"
      step="100"
      :value="local"
      :disabled="platforms.saving"
      class="range-slider h-4 w-full"
      :style="{ '--pct': `${((local - 300) / 5700) * 100}%`, '--c': color }"
      title="Rate limit the mock marketplaces enforce (per minute, fixed window)"
      aria-label="Vendor rate limit per minute"
      @pointerdown="dragging = true"
      @pointerup="dragging = false"
      @pointercancel="dragging = false"
      @input="local = Number(($event.target as HTMLInputElement).value)"
      @change="commit(Number(($event.target as HTMLInputElement).value))"
    />
  </div>
</template>
