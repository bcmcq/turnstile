import { expect, it } from 'vitest'
import { platformTone } from '@/lib/platformTone'

it('maps each marketplace to its chip tone and everything else to muted', () => {
  expect(platformTone('tixhub')).toBe('cyan')
  expect(platformTone('seatswap')).toBe('violet')
  expect(platformTone('passmarket')).toBe('amber')
  expect(platformTone('house')).toBe('muted')
  expect(platformTone(null)).toBe('muted')
})
