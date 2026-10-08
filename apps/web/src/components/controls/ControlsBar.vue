<script setup lang="ts">
import { computed, ref } from 'vue'
import { api } from '@/api/client'
import type { PlatformCode, RunType } from '@/api/types'
import ChaosSlider from '@/components/controls/ChaosSlider.vue'
import SectionSelect from '@/components/controls/SectionSelect.vue'
import Button from '@/components/ui/Button.vue'
import Field from '@/components/ui/Field.vue'
import Panel from '@/components/ui/Panel.vue'
import Select from '@/components/ui/Select.vue'
import Toggle from '@/components/ui/Toggle.vue'
import { runTypeLabel } from '@/lib/format'
import { useArenaStore } from '@/stores/arena'
import { useMetricsStore } from '@/stores/metrics'
import { usePlatformsStore } from '@/stores/platforms'
import { useRunStore } from '@/stores/run'
import { useToastsStore } from '@/stores/toasts'

const arena = useArenaStore()
const run = useRunStore()
const metrics = useMetricsStore()
const platforms = usePlatformsStore()
const toasts = useToastsStore()

const ACTIONS: RunType[] = ['transfer', 'reprice', 'release', 'regenerate', 'close_section', 'open_section']
const action = ref<RunType>('transfer')
const target = ref<PlatformCode>('tixhub')
const repriceMode = ref<'percent' | 'absolute'>('percent')
const repriceDelta = ref(10)
const resetting = ref(false)

const actionOptions = ACTIONS.map((a) => ({ value: a, label: runTypeLabel[a] }))
const platformOptions = computed(() => arena.platforms.map((p) => ({ value: p.code, label: p.name })))
const hasSelection = computed(() => arena.selectedSections.size > 0 || arena.selectedTickets.size > 0)
const canStart = computed(() => hasSelection.value && !run.isActive && !run.busy)
const startLabel = computed(() => (action.value === 'transfer' ? `Transfer → ${arena.platformByCode.get(target.value)?.name ?? ''}` : runTypeLabel[action.value]))
const buyersOn = computed(() => metrics.buyersOn)

async function guarded(fn: () => Promise<unknown>): Promise<void> {
  try {
    await fn()
  } catch (e) {
    toasts.fromError(e)
  }
}

const startRun = () =>
  guarded(async () => {
    const view = await run.start({
      type: action.value,
      selection: { sections: [...arena.selectedSections], tickets: [...arena.selectedTickets] },
      targetPlatform: action.value === 'transfer' ? target.value : null,
      reprice: action.value === 'reprice' ? { mode: repriceMode.value, delta: repriceDelta.value } : null,
    })
    toasts.push('success', `Run #${view.number} started · ${runTypeLabel[view.type]}`)
    arena.clearSelection() // the run owns those seats now; the map shows their state, not the pick
  })
const pauseOrResume = () => guarded(() => (run.isPaused ? run.resume() : run.pause()))
// Two-click confirms instead of window.confirm: friendlier on video, and no native dialog to fight.
const confirming = ref<'cancel' | 'reset' | null>(null)
let confirmTimer: ReturnType<typeof setTimeout> | null = null
function arm(which: 'cancel' | 'reset'): boolean {
  if (confirming.value === which) {
    confirming.value = null
    return true
  }
  confirming.value = which
  if (confirmTimer) clearTimeout(confirmTimer)
  confirmTimer = setTimeout(() => (confirming.value = null), 4000)
  return false
}
const cancelRun = () => {
  if (arm('cancel')) void guarded(() => run.cancel())
}
const replay = () =>
  guarded(async () => {
    const view = await run.replay(500)
    toasts.push('info', `Replay #${view.number} started · every job should hit the idempotency guard`)
  })
const resetDemo = () => {
  if (!arm('reset')) return
  void guarded(async () => {
    resetting.value = true
    try {
      const r = await api.resetDemo()
      arena.clearSelection()
      toasts.push('success', `Arena reseeded · ${r.seats.toLocaleString()} seats`)
    } finally {
      resetting.value = false
    }
  })
}
const setChaos = (rate: number) => guarded(() => platforms.setChaos(rate))
const setBuyers = (on: boolean) => guarded(() => platforms.setBuyers(on))
</script>

<template>
  <Panel>
    <div class="flex flex-wrap items-end gap-3">
      <Field label="Section">
        <SectionSelect :disabled="run.isActive" />
      </Field>
      <Field label="Bulk action">
        <Select v-model="action" :options="actionOptions" :disabled="run.isActive" width="120px" />
      </Field>
      <Field v-if="action === 'transfer'" label="Target platform">
        <Select v-model="target" :options="platformOptions" :disabled="run.isActive" width="120px" />
      </Field>
      <template v-if="action === 'reprice'">
        <Field label="Mode">
          <Select v-model="repriceMode" :options="[{ value: 'percent', label: 'Percent' }, { value: 'absolute', label: 'Cents' }]" :disabled="run.isActive" width="110px" />
        </Field>
        <Field label="Delta">
          <input v-model.number="repriceDelta" type="number" step="1" :disabled="run.isActive" class="w-[90px] rounded-lg border border-border bg-panel-2 px-3 py-2 text-[13px] font-medium tabular-nums focus:border-tixhub/60 focus:outline-none disabled:opacity-50" />
        </Field>
      </template>
      <ChaosSlider :model-value="metrics.chaosRate" :disabled="platforms.saving" @commit="setChaos">
        <Toggle :model-value="buyersOn" label="Buyers" :disabled="platforms.saving" title="Mock buyers snipe 2% of listings and send signed webhooks" @update:model-value="setBuyers" />
      </ChaosSlider>
      <div class="ml-auto flex items-center gap-2">
        <Button :variant="confirming === 'reset' ? 'danger' : 'ghost'" :disabled="run.busy || resetting || run.isActive" :busy="resetting" title="Truncate and reseed the arena" @click="resetDemo">{{ confirming === 'reset' ? 'Wipe + reseed?' : 'Reset' }}</Button>
        <Button :disabled="run.isActive || run.busy" title="Re-dispatch the last 500 completed jobs; the idempotency ledger skips them all" @click="replay">Replay 500</Button>
        <template v-if="run.isActive">
          <Button variant="danger" :disabled="run.busy" @click="cancelRun">{{ confirming === 'cancel' ? 'Confirm cancel' : 'Cancel' }}</Button>
          <Button :busy="run.busy" @click="pauseOrResume">{{ run.isPaused ? 'Resume' : 'Pause' }}</Button>
        </template>
        <Button variant="primary" :disabled="!canStart" :busy="run.busy && !run.isActive" :title="hasSelection ? '' : 'Select sections or seats first'" @click="startRun">{{ startLabel }}</Button>
      </div>
    </div>
  </Panel>
</template>
