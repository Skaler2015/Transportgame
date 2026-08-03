// Money is stored as integer minor units. These helpers convert to display
// strings so no component does raw math on currency. The symbol reflects the
// player's country and is set once the company loads (setCurrencySymbol).

let _symbol = '₹'

/** Set the active currency symbol (called when the company loads/changes). */
export function setCurrencySymbol(symbol?: string): void {
  if (symbol) _symbol = symbol
}

/** The active currency symbol, for templates that render prices directly. */
export function cur(): string {
  return _symbol
}

export function credits(cents: number, opts: { sign?: boolean; compact?: boolean } = {}): string {
  const value = cents / 100
  const sign = opts.sign && value > 0 ? '+' : ''
  const body = opts.compact
    ? compactNumber(value)
    : value.toLocaleString(undefined, { maximumFractionDigits: 0 })
  return sign + _symbol + body
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
  clear: '☀️', wind: '💨', rain: '🌧️', snow: '❄️', storm: '⛈️', fog: '🌫️', heat: '🔥', flood: '🌊', cyclone: '🌀',
}

// Weather v2 effects (mirrors config/transoria.php 'weather') for UI advisories.
// speed/fuel/accident are multipliers; spoilage is the perishable-loss fraction.
export interface WeatherFx { label: string; severity: string; speed: number; fuel: number; accident: number; spoilage: number }
export const WEATHER_FX: Record<string, WeatherFx> = {
  clear:   { label: 'Clear',    severity: 'calm',     speed: 1.0,  fuel: 1.0,  accident: 1.0, spoilage: 0 },
  wind:    { label: 'Windy',    severity: 'calm',     speed: 0.97, fuel: 1.06, accident: 1.1, spoilage: 0 },
  rain:    { label: 'Rain',     severity: 'moderate', speed: 0.9,  fuel: 1.05, accident: 1.4, spoilage: 0.02 },
  fog:     { label: 'Fog',      severity: 'moderate', speed: 0.85, fuel: 1.02, accident: 1.6, spoilage: 0 },
  heat:    { label: 'Heatwave', severity: 'moderate', speed: 0.95, fuel: 1.1,  accident: 1.1, spoilage: 0.1 },
  snow:    { label: 'Snow',     severity: 'severe',   speed: 0.75, fuel: 1.15, accident: 1.9, spoilage: 0.03 },
  storm:   { label: 'Storm',    severity: 'severe',   speed: 0.7,  fuel: 1.18, accident: 2.2, spoilage: 0.06 },
  flood:   { label: 'Flood',    severity: 'extreme',  speed: 0.6,  fuel: 1.2,  accident: 2.4, spoilage: 0.08 },
  cyclone: { label: 'Cyclone',  severity: 'extreme',  speed: 0.5,  fuel: 1.28, accident: 3.0, spoilage: 0.12 },
}

/** Short human advisory of how a weather state affects a trip. */
export function weatherAdvisory(w: string): string {
  const fx = WEATHER_FX[w]
  if (!fx || fx.severity === 'calm') return ''
  const parts: string[] = []
  if (fx.speed < 1) parts.push(`speed −${Math.round((1 - fx.speed) * 100)}%`)
  if (fx.fuel > 1) parts.push(`fuel +${Math.round((fx.fuel - 1) * 100)}%`)
  if (fx.accident > 1) parts.push(`risk ×${fx.accident.toFixed(1)}`)
  if (fx.spoilage > 0) parts.push(`spoilage ${Math.round(fx.spoilage * 100)}%`)
  return parts.join(' · ')
}

export const CATEGORY_COLOR: Record<string, string> = {
  food: '#84cc16', tech: '#38bdf8', industrial: '#a78bfa', raw: '#f59e0b',
  hazmat: '#fb7185', luxury: '#f0abfc', livestock: '#fbbf24',
}

/** A vehicle's fleet number, zero-padded, e.g. #0001. */
export function fleetTag(no?: number | null): string {
  return no ? `#${String(no).padStart(4, '0')}` : ''
}
