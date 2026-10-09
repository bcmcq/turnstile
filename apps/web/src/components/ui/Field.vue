<script setup lang="ts">
import Label from '@/components/ui/Label.vue'

defineProps<{
  label: string
}>()
</script>

<template>
  <label class="flex flex-col gap-1.5">
    <Label :text="label" />
    <slot />
  </label>
</template>
