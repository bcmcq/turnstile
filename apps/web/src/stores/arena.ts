import { defineStore } from 'pinia'
import { computed, ref, shallowRef } from 'vue'
import { api } from '@/api/client'
import type { ArenaBootstrap, PlatformCode, PlatformView, SectionView } from '@/api/types'
import { SeatTable } from '@/lib/seatTable'

/** Static arena data from /api/bootstrap, the seat table from /api/seats, and the user's selection. */
export const useArenaStore = defineStore('arena', () => {
  const bootstrap = shallowRef<ArenaBootstrap | null>(null)
  const seats = shallowRef<SeatTable | null>(null)
  const seatsError = ref<string | null>(null)
  const selectedSections = ref<Set<number>>(new Set())
  const selectedTickets = ref<Set<number>>(new Set())
  /** Bumped by the section picker so the canvas zooms to the chosen sections; canvas clicks never trigger it. */
  const focus = ref<{ ids: number[]; seq: number }>({ ids: [], seq: 0 })

  const sections = computed<SectionView[]>(() => bootstrap.value?.sections ?? [])
  const platforms = computed<PlatformView[]>(() => bootstrap.value?.platforms ?? [])
  const sectionById = computed(() => new Map(sections.value.map((s) => [s.id, s])))
  const platformById = computed(() => new Map(platforms.value.map((p) => [p.id, p])))
  const platformByCode = computed(() => new Map(platforms.value.map((p) => [p.code, p])))
  const seatCount = computed(() => bootstrap.value?.seatCount ?? 0)
  const arenaSize = computed(() => ({ width: bootstrap.value?.arenaWidth ?? 880, height: bootstrap.value?.arenaHeight ?? 720 }))

  const selectedSeatTotal = computed(() => {
    let n = 0
    for (const id of selectedSections.value) n += sectionById.value.get(id)?.seatCount ?? 0
    return n + selectedTickets.value.size
  })

  const selectionSummary = computed(() => {
    const codes = [...selectedSections.value].map((id) => sectionById.value.get(id)?.code ?? String(id)).sort()
    const parts: string[] = []
    if (codes.length) parts.push(codes.length <= 4 ? `sections ${codes.join(', ')}` : `${codes.length} sections`)
    if (selectedTickets.value.size) parts.push(`${selectedTickets.value.size} seat${selectedTickets.value.size === 1 ? '' : 's'}`)
    return parts.length ? parts.join(' + ') : 'nothing'
  })

  function setBootstrap(data: ArenaBootstrap): void {
    bootstrap.value = data
  }

  /** ~1 MB gzipped; replaces the table wholesale (also after a demo reset). */
  async function loadSeats(): Promise<void> {
    seatsError.value = null
    try {
      seats.value = new SeatTable(await api.seats())
    } catch (e) {
      seatsError.value = e instanceof Error ? e.message : String(e)
    }
  }

  function toggleSection(id: number, additive: boolean): void {
    const next = new Set(additive ? selectedSections.value : [])
    if (selectedSections.value.has(id) && additive) next.delete(id)
    else next.add(id)
    selectedSections.value = next
    if (!additive) selectedTickets.value = new Set()
  }

  function toggleTicket(id: number, additive: boolean): void {
    const next = new Set(additive ? selectedTickets.value : [])
    if (selectedTickets.value.has(id) && additive) next.delete(id)
    else next.add(id)
    selectedTickets.value = next
    if (!additive) selectedSections.value = new Set()
  }

  /** Box select: replace or extend the seat selection with many tickets at once. */
  function selectTickets(ids: number[], additive: boolean): void {
    selectedTickets.value = new Set(additive ? [...selectedTickets.value, ...ids] : ids)
    if (!additive) selectedSections.value = new Set()
  }

  function setSections(ids: number[]): void {
    selectedSections.value = new Set(ids)
  }

  function clearSelection(): void {
    selectedSections.value = new Set()
    selectedTickets.value = new Set()
  }

  function focusSections(ids: number[]): void {
    focus.value = { ids, seq: focus.value.seq + 1 }
  }

  function platformColor(code: PlatformCode | null | undefined): string | null {
    return code ? (platformByCode.value.get(code)?.color ?? null) : null
  }

  return {
    bootstrap, seats, seatsError, loadSeats, sections, platforms, sectionById, platformById, platformByCode, seatCount, arenaSize,
    selectedSections, selectedTickets, selectedSeatTotal, selectionSummary, focus,
    setBootstrap, toggleSection, toggleTicket, selectTickets, setSections, clearSelection, focusSections, platformColor,
  }
})

if (import.meta.hot) import.meta.hot.accept(acceptHMRUpdate(useArenaStore, import.meta.hot))
