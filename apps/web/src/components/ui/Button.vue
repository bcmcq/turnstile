<script setup lang="ts">
export type ButtonVariant = 'primary' | 'ghost' | 'danger'

const props = withDefaults(
  defineProps<{
    variant?: ButtonVariant
    disabled?: boolean
    busy?: boolean
    title?: string
    size?: 'sm' | 'md'
  }>(),
  {
    variant: 'ghost',
    disabled: false,
    busy: false,
    size: 'md',
  },
)
const emit = defineEmits<{
  click: [event: MouseEvent]
}>()

const classes: Record<ButtonVariant, string> = {
  primary: 'bg-tixhub text-bg hover:bg-tixhub/85 focus-visible:ring-tixhub/60',
  ghost: 'border border-border bg-panel-2 text-fg hover:bg-border/60 focus-visible:ring-fg/30',
  danger: 'border border-fail/40 bg-fail/10 text-fail hover:bg-fail/20 focus-visible:ring-fail/50',
}
</script>

<template>
  <button
    type="button"
    :title="props.title"
    :disabled="props.disabled || props.busy"
    class="inline-flex items-center justify-center gap-1.5 rounded-lg font-semibold whitespace-nowrap transition focus:outline-none focus-visible:ring-2 disabled:cursor-not-allowed disabled:opacity-40"
    :class="[classes[props.variant], props.size === 'sm' ? 'px-2.5 py-1 text-[11px]' : 'px-3.5 py-2 text-xs']"
    @click="emit('click', $event)"
  >
    <span v-if="props.busy" class="size-3 animate-spin rounded-full border-2 border-current border-t-transparent" />
    <slot />
  </button>
</template>
