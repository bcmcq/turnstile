import type { ChipTone } from '@/components/ui/Chip.vue'

/** Chip tone for a platform code; house inventory and unknown codes read as muted. */
export function platformTone(code: string | null | undefined): ChipTone {
  switch (code) {
    case 'tixhub':
      return 'cyan'
    case 'seatswap':
      return 'violet'
    case 'passmarket':
      return 'amber'
    default:
      return 'muted'
  }
}
