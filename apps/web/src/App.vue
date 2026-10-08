<script setup lang="ts">
import ArenaPanel from '@/components/arena/ArenaPanel.vue'
import ControlsBar from '@/components/controls/ControlsBar.vue'
import HeaderBar from '@/components/layout/HeaderBar.vue'
import MetricsTiles from '@/components/metrics/MetricsTiles.vue'
import PlatformsPanel from '@/components/platforms/PlatformsPanel.vue'
import TabsPanel from '@/components/tabs/TabsPanel.vue'
import { useDashboard } from '@/composables/useDashboard'

const { ready, error, connected } = useDashboard()
</script>

<template>
  <div class="flex h-full min-h-0 flex-col gap-4 p-5">
    <HeaderBar :connected="connected" />
    <p v-if="error" class="rounded-lg border border-fail/40 bg-fail/10 px-3 py-2 text-xs text-fail">API unreachable: {{ error }}</p>
    <main v-if="ready" class="grid min-h-0 flex-1 grid-cols-[1fr_380px] gap-4">
      <div class="flex min-h-0 flex-col gap-4">
        <ArenaPanel />
        <ControlsBar />
      </div>
      <aside class="flex min-h-0 flex-col gap-3">
        <MetricsTiles />
        <PlatformsPanel />
        <TabsPanel />
      </aside>
    </main>
  </div>
</template>
