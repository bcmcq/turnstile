<script setup lang="ts">
import { ref, watch } from 'vue'
import Label from '@/components/ui/Label.vue'
import { fmtPct } from '@/lib/format'

const props = defineProps<{ modelValue: number; disabled?: boolean }>()
const emit = defineEmits<{ commit: [value: number] }>()
const local = ref(props.modelValue)
watch(() => props.modelValue, (v) => (local.value = v))
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
      class="chaos h-4 w-full cursor-pointer"
      :style="{ '--pct': `${local * 100}%`, '--c': local > 0.3 ? '#f87171' : '#fb923c' }"
      @input="local = Number(($event.target as HTMLInputElement).value)"
      @change="emit('commit', Number(($event.target as HTMLInputElement).value))"
    />
  </div>
</template>

<style scoped>
.chaos {
  appearance: none;
  background: transparent;
}
.chaos::-webkit-slider-runnable-track {
  height: 4px;
  border-radius: 2px;
  background: linear-gradient(to right, var(--c) var(--pct), #2a3644 var(--pct));
}
.chaos::-webkit-slider-thumb {
  appearance: none;
  margin-top: -5px;
  width: 14px;
  height: 14px;
  border-radius: 999px;
  background: #e6edf3;
  border: 2px solid var(--c);
}
.chaos::-moz-range-track {
  height: 4px;
  border-radius: 2px;
  background: linear-gradient(to right, var(--c) var(--pct), #2a3644 var(--pct));
}
.chaos::-moz-range-thumb {
  width: 14px;
  height: 14px;
  border-radius: 999px;
  background: #e6edf3;
  border: 2px solid var(--c);
}
</style>
