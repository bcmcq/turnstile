<script setup lang="ts">
import { ref, watch } from 'vue'
import Label from '@/components/ui/Label.vue'
import { COLORS } from '@/lib/arenaPalette'
import { fmtPct } from '@/lib/format'

const props = defineProps<{
  modelValue: number
  disabled?: boolean
}>()
const emit = defineEmits<{
  commit: [value: number]
}>()
const local = ref(props.modelValue)
const dragging = ref(false)
// the store value follows the metrics stream; it must not move the thumb while the user holds it
watch(() => props.modelValue, (v) => {
  if (!dragging.value) local.value = v
})
</script>

<template>
  <div class="flex min-w-[150px] flex-1 flex-col gap-1.5">
    <div class="flex items-center justify-between gap-3">
      <span class="flex items-center gap-2"><Label text="Chaos" /><span class="text-[11px] font-semibold tabular-nums" :class="local > 0.3 ? 'text-fail' : 'text-inflight'">{{ fmtPct(local) }}</span></span>
      <slot />
    </div>
    <input
      type="range"
      min="0"
      max="1"
      step="0.05"
      :value="local"
      :disabled="props.disabled"
      class="range-slider h-4 w-full"
      :style="{ '--pct': `${local * 100}%`, '--c': local > 0.3 ? COLORS.fail : COLORS.inflight }"
      aria-label="Chaos: share of vendor calls that fail"
      @pointerdown="dragging = true"
      @pointerup="dragging = false"
      @pointercancel="dragging = false"
      @input="local = Number(($event.target as HTMLInputElement).value)"
      @change="emit('commit', Number(($event.target as HTMLInputElement).value))"
    />
  </div>
</template>
