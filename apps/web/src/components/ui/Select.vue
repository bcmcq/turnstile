<script setup lang="ts">
export interface SelectOption<T extends string> {
  value: T
  label: string
  disabled?: boolean
}

const props = defineProps<{
  modelValue: string
  options: SelectOption<string>[]
  disabled?: boolean
  width?: string
}>()
const emit = defineEmits<{
  'update:modelValue': [value: string]
}>()
</script>

<template>
  <div class="relative" :style="{ width: props.width ?? '150px' }">
    <select
      :value="props.modelValue"
      :disabled="props.disabled"
      class="w-full cursor-pointer appearance-none rounded-lg border border-border bg-panel-2 py-2 pr-7 pl-3 text-[13px] font-medium text-fg focus:border-tixhub/60 focus:outline-none disabled:cursor-not-allowed disabled:opacity-50"
      @change="emit('update:modelValue', ($event.target as HTMLSelectElement).value)"
    >
      <option v-for="o in props.options" :key="o.value" :value="o.value" :disabled="o.disabled">{{ o.label }}</option>
    </select>
    <span class="pointer-events-none absolute top-1/2 right-2.5 -translate-y-1/2 text-xs text-muted">▾</span>
  </div>
</template>
