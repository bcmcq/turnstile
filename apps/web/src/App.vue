<script setup lang="ts">
import { onMounted, ref } from 'vue'

// ponytail: phase 0 smoke screen. Confirms Vite, Tailwind, API and Mercure are reachable.
const api = ref<string>('…')
const mercure = ref<string>('…')

onMounted(async () => {
  try {
    const r = await fetch(`${import.meta.env.VITE_API_URL}/api/health`)
    api.value = r.ok ? 'ok' : `http ${r.status}`
  } catch {
    api.value = 'unreachable'
  }
  const es = new EventSource(`${import.meta.env.VITE_MERCURE_URL}?topic=turnstile/health`)
  es.onopen = () => (mercure.value = 'connected')
  es.onerror = () => (mercure.value = 'error')
})
</script>

<template>
  <main class="min-h-full p-5">
    <header class="flex items-center gap-3">
      <span class="grid size-7 place-items-center rounded-md bg-tixhub font-bold text-bg">T</span>
      <span class="text-sm font-bold tracking-[0.12em]">TURNSTILE</span>
      <span class="text-xs text-muted">Bulk ticket sync</span>
    </header>
    <section class="mt-6 grid max-w-sm gap-2 rounded-xl border border-border bg-panel p-4 text-sm">
      <div class="flex justify-between"><span class="text-muted">API</span><span>{{ api }}</span></div>
      <div class="flex justify-between"><span class="text-muted">Mercure</span><span>{{ mercure }}</span></div>
    </section>
  </main>
</template>
