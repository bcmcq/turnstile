<script setup lang="ts">
const props = defineProps<{
  modelValue: boolean
  label: string
  disabled?: boolean
  title?: string
}>()
const emit = defineEmits<{
  'update:modelValue': [value: boolean]
}>()
</script>

<template>
  <button
    type="button"
    role="switch"
    :aria-checked="props.modelValue"
    :disabled="props.disabled"
    :title="props.title"
    class="flex items-center gap-2 text-[11px] font-medium whitespace-nowrap text-muted disabled:opacity-50"
    @click="emit('update:modelValue', !props.modelValue)"
  >
    <span class="relative h-4 w-7 rounded-full transition" :class="props.modelValue ? 'bg-tixhub' : 'bg-dim'">
      <span class="absolute top-0.5 size-3 rounded-full bg-fg transition" :class="props.modelValue ? 'left-3.5' : 'left-0.5'" />
    </span>
    {{ props.label }}
  </button>
</template>
