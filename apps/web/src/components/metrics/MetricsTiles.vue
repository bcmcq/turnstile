<script setup lang="ts">
import Panel from '@/components/ui/Panel.vue'
import Label from '@/components/ui/Label.vue'
import { fmtInt } from '@/lib/format'
import { useMetricsStore } from '@/stores/metrics'

const metrics = useMetricsStore()
</script>

<template>
  <!-- 4.6: sparklines. Numbers are live already. -->
  <div class="grid grid-cols-2 gap-3">
    <Panel><Label text="Jobs / sec" /><span class="text-[26px] leading-tight font-semibold">{{ metrics.jobsPerSec }}</span></Panel>
    <Panel><Label text="In flight" /><span class="text-[26px] leading-tight font-semibold">{{ fmtInt(metrics.counts?.in_flight ?? 0) }}</span></Panel>
    <Panel><Label text="Queued" /><span class="text-[26px] leading-tight font-semibold">{{ fmtInt((metrics.counts?.queued ?? 0) + (metrics.counts?.retry_wait ?? 0)) }}</span></Panel>
    <Panel><Label text="Failed" /><span class="text-[26px] leading-tight font-semibold">{{ fmtInt(metrics.counts?.dead_lettered ?? 0) }}</span></Panel>
  </div>
</template>
