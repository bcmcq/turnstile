<script setup lang="ts">
import Label from '@/components/ui/Label.vue'
import Sparkline from '@/components/metrics/Sparkline.vue'

defineProps<{
  label: string
  value: string
  sub?: string
  subClass?: string
  series: number[]
  color: string
}>()
</script>

<template>
  <section class="flex flex-col gap-1.5 rounded-xl border border-border bg-panel px-4 pt-3 pb-3">
    <Label :text="label" />
    <div class="flex items-end justify-between gap-2">
      <span class="text-[26px] leading-none font-semibold tabular-nums">{{ value }}</span>
      <span v-if="sub" class="pb-0.5 text-[11px] font-medium whitespace-nowrap" :class="subClass ?? 'text-muted'">{{ sub }}</span>
    </div>
    <Sparkline :points="series" :color="color" :height="28" />
  </section>
</template>
