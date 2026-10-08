/** Minimal typed pub/sub. Keeps the canvas decoupled from the dashboard boot code. */
export function createEmitter<Events extends Record<string, unknown>>() {
  const handlers = new Map<keyof Events, Set<(payload: never) => void>>()
  return {
    on<K extends keyof Events>(event: K, fn: (payload: Events[K]) => void): () => void {
      const set = handlers.get(event) ?? new Set()
      set.add(fn as (payload: never) => void)
      handlers.set(event, set)
      return () => set.delete(fn as (payload: never) => void)
    },
    emit<K extends keyof Events>(event: K, payload: Events[K]): void {
      handlers.get(event)?.forEach((fn) => (fn as (p: Events[K]) => void)(payload))
    },
  }
}
