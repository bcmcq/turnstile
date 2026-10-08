<script setup lang="ts">
import Panel from '@/components/ui/Panel.vue'
import { useMetricsStore } from '@/stores/metrics'
import { useLogStore } from '@/stores/log'

const metrics = useMetricsStore()
const log = useLogStore()
</script>

<template>
  <Panel class="flex-1">
    <!-- 4.7 / 4.8: Workers, Failed, Events tabs -->
    <div class="text-xs text-muted">
      tabs · subtasks 4.7–4.8 · {{ metrics.workers.length }} workers live · {{ log.total }} events received
    </div>
  </Panel>
</template>
