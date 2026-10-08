<script setup lang="ts">
import { computed, ref } from 'vue'
import { api } from '@/api/client'
import Toggle from '@/components/ui/Toggle.vue'
import { useMetricsStore } from '@/stores/metrics'
import { useToastsStore } from '@/stores/toasts'

const metrics = useMetricsStore()
const toasts = useToastsStore()
const busy = ref(false)
/** Target we last asked for; cards catch up as heartbeats arrive, so show it until they do. */
const pending = ref<number | null>(null)

const live = computed(() => metrics.workers.length)
const shown = computed(() => pending.value ?? live.value)
const min = computed(() => metrics.autoscale.min)
const max = computed(() => metrics.autoscale.max)

async function scale(delta: number): Promise<void> {
  const target = Math.max(1, Math.min(24, shown.value + delta))
  if (target === shown.value) return
  busy.value = true
  pending.value = target
  try {
    const r = await api.scaleWorkers(target)
    toasts.push('info', `${r.before} → ${r.target} workers · ${delta > 0 ? 'cloning containers' : 'stopping newest, in-flight jobs finish first'}`)
    setTimeout(() => (pending.value = null), 12_000)
  } catch (e) {
    pending.value = null
    toasts.fromError(e)
  } finally {
    busy.value = false
  }
}

async function toggleAuto(on: boolean): Promise<void> {
  try {
    await api.setAutoscale(on)
    toasts.push('info', on ? `Autoscale on · ${min.value}–${max.value} workers on queue depth` : 'Autoscale off')
  } catch (e) {
    toasts.fromError(e)
  }
}
</script>

<template>
  <div class="flex items-center gap-3 rounded-lg bg-panel-2/60 px-2.5 py-1.5">
    <div class="flex items-center gap-1">
      <button type="button" class="size-6 rounded border border-border text-sm leading-none hover:bg-border/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-tixhub/60 disabled:opacity-40" :disabled="busy || metrics.autoscale.enabled || shown <= 1" title="Stop one worker" @click="scale(-1)">−</button>
      <span class="w-8 text-center text-xs font-semibold tabular-nums" :class="pending !== null ? 'text-passmarket' : ''">{{ shown }}</span>
      <button type="button" class="size-6 rounded border border-border text-sm leading-none hover:bg-border/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-tixhub/60 disabled:opacity-40" :disabled="busy || metrics.autoscale.enabled || shown >= max" title="Start one more worker container" @click="scale(1)">+</button>
      <span class="ml-1 text-[10px] text-muted">workers</span>
    </div>
    <span class="h-4 w-px bg-border" />
    <Toggle :model-value="metrics.autoscale.enabled" label="Auto" :title="`Scale on queue depth: +4 above 25 queued per worker (5 s cooldown), −2 every 5 s when idle, between ${min} and ${max}`" @update:model-value="toggleAuto" />
    <span class="min-w-0 flex-1 truncate text-[10px] text-muted" :title="metrics.autoscale.lastDecision ?? ''">{{ metrics.autoscale.lastDecision ?? '' }}</span>
  </div>
</template>
