import { describe, expect, it } from 'vitest'
import { fmtEta, fmtMoney, fmtMs, jobReason } from '@/lib/format'

describe('format', () => {
  it('formats money in whole dollars and cents', () => {
    expect(fmtMoney(12345)).toBe('$123.45')
    expect(fmtMoney(5)).toBe('$0.05')
  })

  it('switches milliseconds to seconds at one second', () => {
    expect(fmtMs(999)).toBe('999 ms')
    expect(fmtMs(1500)).toBe('1.5 s')
    expect(fmtMs(null)).toBe('—')
  })

  it('rounds an ETA up so it never reads zero while work remains', () => {
    expect(fmtEta(0.2)).toBe('1s')
    expect(fmtEta(125)).toBe('2m 5s')
    expect(fmtEta(null)).toBe('—')
  })

  it('names the failure and its attempt count', () => {
    expect(jobReason({ lastOutcome: 'http_429', attempts: 5 })).toBe('429 ×5')
    expect(jobReason({ lastOutcome: null, attempts: 1 })).toBe('failed')
  })
})
