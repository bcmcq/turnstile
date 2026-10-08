<script setup lang="ts">
defineProps<{
  title?: string
  subtitle?: string
}>()
</script>

<template>
  <section class="flex min-h-0 flex-col rounded-xl border border-border bg-panel px-4 py-3.5">
    <header v-if="title || $slots.header" class="mb-2 flex items-center justify-between gap-3 whitespace-nowrap">
      <h2 v-if="title" class="text-[13px] font-semibold whitespace-nowrap">{{ title }}</h2>
      <span v-if="subtitle" class="text-[11px] text-muted">{{ subtitle }}</span>
      <slot name="header" />
    </header>
    <slot />
  </section>
</template>
