// Money is stored as integer CENTS of the Credit (₡). These helpers convert
// to display strings so no component does raw math on currency.

export function credits(cents: number, opts: { sign?: boolean; compact?: boolean } = {}): string {
  const value = cents / 100
  const sign = opts.sign && value > 0 ? '+' : ''
  if (opts.compact) {
    return sign + '₡' + compactNumber(value)
  }
  return sign + '₡' + value.toLocaleString(undefined, { maximumFractionDigits: 0 })
}

export function compactNumber(n: number): string {
  const abs = Math.abs(n)
  if (abs >= 1_000_000_000) return (n / 1_000_000_000).toFixed(2) + 'B'
  if (abs >= 1_000_000) return (n / 1_000_000).toFixed(2) + 'M'
  if (abs >= 1_000) return (n / 1_000).toFixed(1) + 'k'
  return n.toLocaleString(undefined, { maximumFractionDigits: 0 })
}

export function num(n: number, digits = 0): string {
  return n.toLocaleString(undefined, { maximumFractionDigits: digits })
}

/** Countdown string from now until an ISO timestamp. */
export function untilString(iso: string): string {
  const ms = new Date(iso).getTime() - Date.now()
  if (ms <= 0) return 'arriving…'
  const s = Math.floor(ms / 1000)
  const m = Math.floor(s / 60)
  const rem = s % 60
  if (m >= 60) {
    const h = Math.floor(m / 60)
    return `${h}h ${m % 60}m`
  }
  return m > 0 ? `${m}m ${rem}s` : `${rem}s`
}

export const WEATHER_ICON: Record<string, string> = {
  clear: '☀️', rain: '🌧️', snow: '❄️', storm: '⛈️', fog: '🌫️', heat: '🔥', flood: '🌊',
}

export const CATEGORY_COLOR: Record<string, string> = {
  food: '#84cc16', tech: '#38bdf8', industrial: '#a78bfa', raw: '#f59e0b',
  hazmat: '#fb7185', luxury: '#f0abfc', livestock: '#fbbf24',
}
