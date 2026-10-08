<script setup lang="ts">
import Panel from '@/components/ui/Panel.vue'
import { useMetricsStore } from '@/stores/metrics'

const metrics = useMetricsStore()
</script>

<template>
  <Panel title="Platforms · rate limits" subtitle="token bucket · shared by workers">
    <!-- 4.6: gauges -->
    <ul class="space-y-2 text-xs">
      <li v-for="p in metrics.platforms" :key="p.code" class="flex items-center justify-between">
        <span class="font-medium" :style="{ color: p.color }">{{ p.name }}</span>
        <span class="text-muted tabular-nums">{{ p.remainingTokens }} / {{ p.capacity }} tokens · {{ p.http429 }} ×429</span>
      </li>
    </ul>
  </Panel>
</template>
