<script setup lang="ts">
defineProps<{
  text: string
}>()
</script>

<template>
  <span class="text-[10px] font-medium tracking-[0.08em] whitespace-nowrap text-muted uppercase">{{ text }}</span>
</template>
