<script setup lang="ts">
import { computed } from 'vue'
import PlatformGaugeRow from '@/components/platforms/PlatformGaugeRow.vue'
import Panel from '@/components/ui/Panel.vue'
import { fmtInt } from '@/lib/format'
import { useMetricsStore } from '@/stores/metrics'

const metrics = useMetricsStore()
const errors = computed(() => metrics.platforms.reduce((n, p) => n + p.http5xx + p.timeouts, 0))
</script>

<template>
  <Panel title="Platforms · rate limits" subtitle="token bucket · shared by all workers">
    <div class="flex flex-col gap-2.5">
      <PlatformGaugeRow v-for="p in metrics.platforms" :key="p.code" :gauge="p" />
    </div>
    <p class="mt-2.5 text-[10px] text-muted">
      Workers pace at 80% of each vendor's limit · a 429 triggers backoff, not failure
      <template v-if="errors > 0"> · {{ fmtInt(errors) }} 5xx/timeouts absorbed by retries</template>
    </p>
  </Panel>
</template>
