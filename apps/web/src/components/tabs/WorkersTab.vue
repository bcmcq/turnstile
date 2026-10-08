<script setup lang="ts">
import { gsap } from 'gsap'
import { computed } from 'vue'
import WorkerCard from '@/components/tabs/WorkerCard.vue'
import WorkerControls from '@/components/tabs/WorkerControls.vue'
import { useMetricsStore } from '@/stores/metrics'

const metrics = useMetricsStore()
const maxJobsPerMin = computed(() => Math.max(0, ...metrics.workers.map((w) => w.jobsPerMin)))

function onEnter(el: Element, done: () => void): void {
  gsap.fromTo(el, { opacity: 0, y: 10, scale: 0.96 }, { opacity: 1, y: 0, scale: 1, duration: 0.35, ease: 'power2.out', onComplete: done })
}
function onLeave(el: Element, done: () => void): void {
  gsap.to(el, { opacity: 0, scale: 0.95, duration: 0.25, ease: 'power2.in', onComplete: done })
}
</script>

<template>
  <div class="flex min-h-0 flex-1 flex-col gap-2">
    <WorkerControls />
    <p v-if="metrics.workers.length === 0" class="rounded-lg border border-dashed border-border px-3 py-6 text-center text-[11px] text-muted">No workers reporting. Start one with <code>docker compose up -d --scale worker=2</code>.</p>
    <TransitionGroup v-else tag="div" :css="false" class="grid min-h-0 flex-1 grid-cols-2 content-start gap-2 overflow-y-auto pr-0.5" @enter="onEnter" @leave="onLeave">
      <WorkerCard v-for="w in metrics.workers" :key="w.id" :worker="w" :max-jobs-per-min="maxJobsPerMin" />
    </TransitionGroup>
    <p class="mt-auto text-[10px] text-muted">Bars are jobs/min relative to the fastest worker · the stepper clones real containers through the Docker socket (demo only)</p>
  </div>
</template>
