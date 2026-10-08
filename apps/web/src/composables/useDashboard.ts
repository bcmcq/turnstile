import { onMounted, ref } from 'vue'
import { api } from '@/api/client'
import { useMercure } from '@/composables/useMercure'
import { liveBus } from '@/lib/liveBus'
import { useArenaStore } from '@/stores/arena'
import { useLogStore } from '@/stores/log'
import { useMetricsStore } from '@/stores/metrics'
import { useRunStore } from '@/stores/run'

/** Boots the dashboard: bootstrap + metrics for first paint, then the live stream feeding the stores. */
export function useDashboard() {
  const arena = useArenaStore()
  const run = useRunStore()
  const metrics = useMetricsStore()
  const log = useLogStore()
  const ready = ref(false)
  const error = ref<string | null>(null)
  const onReset = ref<(() => void) | null>(null)

  const mercure = useMercure({
    metrics: (s) => {
      metrics.set(s)
      if (s.run) run.set(s.run)
    },
    seats: (b) => liveBus.emit('seats', b.rows),
    log: (b) => {
      log.push(b.events)
      liveBus.emit('log', b.events)
    },
    run: (u) => {
      if ('reset' in u) {
        run.set(null)
        log.clear()
        void arena.loadSeats()
        liveBus.emit('reset', undefined)
        onReset.value?.()
      } else {
        run.set(u)
      }
    },
  })

  async function load(): Promise<void> {
    try {
      const [boot, snap] = await Promise.all([api.bootstrap(), api.metrics()])
      arena.setBootstrap(boot)
      metrics.set(snap)
      run.set(snap.run)
      ready.value = true
      error.value = null
      void arena.loadSeats()
    } catch (e) {
      error.value = e instanceof Error ? e.message : String(e)
    }
  }

  onMounted(async () => {
    await load()
    mercure.connect()
  })

  return { ready, error, connected: mercure.connected, eventsReceived: mercure.eventsReceived, reload: load, onReset }
}
