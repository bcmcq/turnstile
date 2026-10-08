import { acceptHMRUpdate, defineStore } from 'pinia'
import { ref } from 'vue'
import { api } from '@/api/client'
import type { PlatformCode } from '@/api/types'

/** Chaos and buyer controls. Values are mirrored back from the metrics snapshot; these are the pending writes. */
export const usePlatformsStore = defineStore('platforms', () => {
  const saving = ref(false)

  async function setChaos(rate: number, platform: PlatformCode | 'all' = 'all'): Promise<void> {
    saving.value = true
    try {
      await api.setChaos(platform, rate)
    } finally {
      saving.value = false
    }
  }

  async function setBuyers(on: boolean, platform: PlatformCode | 'all' = 'all'): Promise<void> {
    saving.value = true
    try {
      await api.setBuyers(platform, on ? 0.02 : 0)
    } finally {
      saving.value = false
    }
  }

  return { saving, setChaos, setBuyers }
})

if (import.meta.hot) import.meta.hot.accept(acceptHMRUpdate(usePlatformsStore, import.meta.hot))
