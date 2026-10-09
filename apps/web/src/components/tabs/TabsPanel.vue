<script setup lang="ts">
import { computed, ref } from 'vue'
import { api } from '@/api/client'
import EventsTab from '@/components/tabs/EventsTab.vue'
import FailedTab from '@/components/tabs/FailedTab.vue'
import WorkersTab from '@/components/tabs/WorkersTab.vue'
import Button from '@/components/ui/Button.vue'
import Panel from '@/components/ui/Panel.vue'
import { fmtInt } from '@/lib/format'
import { useMetricsStore } from '@/stores/metrics'
import { useRunStore } from '@/stores/run'
import { useToastsStore } from '@/stores/toasts'

type Tab = 'workers' | 'failed' | 'events'

const metrics = useMetricsStore()
const run = useRunStore()
const toasts = useToastsStore()
const active = ref<Tab>('workers')
const failedTab = ref<InstanceType<typeof FailedTab> | null>(null)
const retryingAll = ref(false)

const deadLettered = computed(() => run.current?.counters.dead_lettered ?? 0)
const tabs = computed<{ id: Tab; label: string; badge: string; badgeClass: string }[]>(() => [
  { id: 'workers', label: 'Workers', badge: String(metrics.workers.length), badgeClass: 'text-tixhub' },
  { id: 'failed', label: 'Failed', badge: fmtInt(deadLettered.value), badgeClass: deadLettered.value > 0 ? 'text-fail' : 'text-muted' },
  { id: 'events', label: 'Events', badge: `${fmtInt(metrics.eventsPerSec)}/s`, badgeClass: 'text-tixhub' },
])

async function retryAll(): Promise<void> {
  const id = run.current?.id
  if (!id) return
  retryingAll.value = true
  try {
    const r = await api.retryFailed(id)
    toasts.push('success', `${r.requeued} job${r.requeued === 1 ? '' : 's'} re-queued`)
    failedTab.value?.reload()
  } catch (e) {
    toasts.fromError(e)
  } finally {
    retryingAll.value = false
  }
}
</script>

<template>
  <Panel class="relative flex-1 overflow-hidden">
    <template #header>
      <div class="flex w-full items-center gap-1" role="tablist">
        <button
          v-for="t in tabs"
          :key="t.id"
          type="button"
          role="tab"
          :aria-selected="active === t.id"
          class="flex items-center gap-1.5 rounded-md px-2.5 py-1.5 text-xs transition focus:outline-none focus-visible:ring-2 focus-visible:ring-tixhub/60"
          :class="active === t.id ? 'bg-panel-2 font-semibold text-fg' : 'font-medium text-muted hover:bg-panel-2/60 hover:text-fg'"
          @click="active = t.id"
        >
          {{ t.label }}
          <span class="text-[10px] font-medium" :class="active === t.id ? t.badgeClass : 'text-muted'">{{ t.badge }}</span>
        </button>
        <span class="flex-1" />
        <Button v-if="active === 'failed' && deadLettered > 0" size="sm" :busy="retryingAll" @click="retryAll">Retry all</Button>
      </div>
    </template>
    <WorkersTab v-if="active === 'workers'" />
    <FailedTab v-else-if="active === 'failed'" ref="failedTab" />
    <EventsTab v-else />
  </Panel>
</template>
