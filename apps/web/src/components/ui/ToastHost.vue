<script setup lang="ts">
import { useToastsStore } from '@/stores/toasts'

const store = useToastsStore()
const tone = { error: 'border-fail/40 bg-fail/10 text-fail', info: 'border-border bg-panel-2 text-fg', success: 'border-ok/40 bg-ok/10 text-ok' } as const
</script>

<template>
  <div class="pointer-events-none fixed right-5 bottom-5 z-50 flex w-80 flex-col gap-2" aria-live="polite">
    <div v-for="t in store.toasts" :key="t.id" class="pointer-events-auto rounded-lg border px-3 py-2 text-xs shadow-lg" :class="tone[t.kind]">
      <div class="flex items-start justify-between gap-2">
        <span class="font-medium">{{ t.text }}</span>
        <button type="button" class="opacity-60 hover:opacity-100" aria-label="Dismiss" @click="store.dismiss(t.id)">×</button>
      </div>
      <ul v-if="t.details.length" class="mt-1 list-disc pl-4 opacity-80">
        <li v-for="d in t.details" :key="d">{{ d }}</li>
      </ul>
    </div>
  </div>
</template>
