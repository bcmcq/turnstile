<script setup lang="ts">
import { computed } from 'vue'

/** 60-sample line with a soft area fill. Pure SVG; no library, no axes. */
const props = withDefaults(
  defineProps<{
    points: number[]
    color: string
    area?: boolean
    height?: number
    strokeWidth?: number
  }>(),
  {
    area: true,
    height: 28,
    strokeWidth: 1.5,
  },
)

const W = 100 // viewBox width; preserveAspectRatio="none" stretches it to the box
const paths = computed(() => {
  const pts = props.points.length >= 2 ? props.points : [0, 0]
  const h = props.height
  const max = Math.max(...pts)
  const min = Math.min(...pts)
  const range = max - min || 1
  const step = W / (pts.length - 1)
  const xy = pts.map((v, i) => [i * step, h - 2 - ((v - min) / range) * (h - 4)] as const)
  const line = xy.map(([x, y], i) => `${i ? 'L' : 'M'}${x.toFixed(2)} ${y.toFixed(2)}`).join(' ')
  return { line, area: `${line} L${W} ${h} L0 ${h} Z` }
})
</script>

<template>
  <svg :viewBox="`0 0 ${W} ${props.height}`" :height="props.height" width="100%" preserveAspectRatio="none" class="block overflow-visible" aria-hidden="true">
    <path v-if="props.area" :d="paths.area" :fill="props.color" fill-opacity="0.15" />
    <path :d="paths.line" fill="none" :stroke="props.color" :stroke-width="props.strokeWidth" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" />
  </svg>
</template>
