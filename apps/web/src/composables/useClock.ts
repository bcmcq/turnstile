import { onBeforeUnmount, onMounted, ref } from 'vue'

export function useClock() {
  const now = ref(new Date())
  let timer: ReturnType<typeof setInterval> | null = null
  onMounted(() => {
    timer = setInterval(() => (now.value = new Date()), 1000)
  })
  onBeforeUnmount(() => {
    if (timer) clearInterval(timer)
  })
  return { now }
}
