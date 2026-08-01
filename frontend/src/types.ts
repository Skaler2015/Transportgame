// Shared API types. Money fields are integer CENTS of the in-game Credit (₡).

export interface City {
  id: number
  name: string
  region: string
  country_code: string
  lat: number
  lng: number
  population: number
  gdp_per_capita?: number | null
  crime_index?: number | null
  road_quality?: number | null
  toll_per_km?: number | null
  industrial_growth?: number | null
  traffic: number
  tax_rate: number
  fuel_price: number
  weather: string
  unlock_level: number
  has_port: boolean
  has_airport: boolean
  has_rail: boolean
}

export interface Commodity {
  id: number
  key: string
  name: string
  category: string
  icon: string
  base_price: number
  weight_per_unit: number
  volume_per_unit: number
  requires_reefer: boolean
  requires_tanker: boolean
  is_hazardous: boolean
  is_perishable: boolean
  risk: number
  unlock_level: number
}

export interface Company {
  id: number
  name: string
  slug: string
  country?: string
  country_name?: string
  motto: string | null
  logo_color: string
  cash: number
  debt: number
  value: number
  level: number
  xp: number
  xp_to_next: number
  reputation: number
  research_points: number
  shipments_completed: number
  shipments_failed: number
  lifetime_revenue: number
  lifetime_expenses: number
  headquarters?: City
  fleet_size?: number
  driver_count?: number
  onboarded_at?: string | null
  tutorial_step?: number
}

export interface VehicleModel {
  id: number
  key: string
  name: string
  brand: string
  class: string
  mode: string // road | rail | sea | air
  needs_trailer: boolean
  price: number
  capacity_weight: number
  capacity_volume: number
  top_speed: number
  fuel_capacity: number
  fuel_economy: number
  powertrain: string
  can_reefer: boolean
  can_tanker: boolean
  can_hazmat: boolean
  reliability: number
  upgrade_slots: number
  unlock_level: number
  locked: boolean
  affordable: boolean
}

export interface Vehicle {
  id: number
  fleet_no?: number | null
  nickname: string | null
  livery_color: string
  status: string
  condition: number
  tire_wear: number
  oil_level?: number
  battery?: number
  insured_until?: string | null
  registered_until?: string | null
  is_insured?: boolean
  is_registered?: boolean
  fuel: number
  fuel_capacity?: number
  fuel_pct?: number
  odometer: number
  available: boolean
  model?: VehicleModel
  city?: City
}

export interface TrailerModel {
  id: number
  key: string
  name: string
  type: string // box | reefer | tanker | flatbed | container | car_carrier
  price: number
  capacity_weight: number
  capacity_volume: number
  can_reefer: boolean
  can_tanker: boolean
  can_hazmat: boolean
  unlock_level: number
  locked: boolean
  affordable: boolean
}

export interface Trailer {
  id: number
  nickname: string | null
  status: string
  condition: number
  available: boolean
  model?: TrailerModel
  city?: City
}

export interface Driver {
  id: number
  crew_no?: number | null
  name: string
  age?: number
  avatar_seed: string | null
  status: string
  rank?: string
  experience?: number
  skill: number
  rain_skill?: number
  eco_skill?: number
  morale: number
  fatigue: number
  health?: number
  loyalty: number
  hazmat_licence: boolean
  licence_until?: string | null
  is_licensed?: boolean
  salary: number
  shipments_done: number
  available: boolean
}

export interface Contract {
  id: number
  status: string
  units: number
  distance_km: number
  payout: number
  penalty: number
  reputation_reward: number
  difficulty: number
  is_rush: boolean
  is_fragile: boolean
  deadline_at: string
  expires_at: string
  commodity?: Commodity
  origin?: City
  destination?: City
  total_weight?: number
  total_volume?: number
  at_fleet_city?: boolean
  fleet_arriving?: boolean
}

export interface Shipment {
  id: number
  status: string
  distance_km: number
  avg_speed: number
  projected_payout: number
  service_cost?: number
  weather_snapshot: string
  progress_percent: number
  departed_at: string
  eta_at: string
  arrived_at: string | null
  event_log: { at: string; text: string }[] | null
  outcome_note?: string | null
  contract?: Contract
  vehicle?: Vehicle
  trailer?: Trailer
  driver?: Driver
}

export interface WorldEvent {
  id: number
  type: string
  title: string
  description: string
  severity: string
  region: string | null
  starts_at: string
  ends_at: string
}

export interface LedgerEntry {
  id: number
  category: string
  description: string
  amount: number
  balance_after: number
  occurred_at: string
}

export interface AchievementUnlock {
  key: string
  name: string
  icon: string
  reward_cash: number
  reward_xp: number
}

export interface Achievement {
  key: string
  name: string
  description: string
  icon: string
  category: string
  metric: string
  threshold: number
  progress: number
  progress_pct: number
  reward_cash: number
  reward_xp: number
  unlocked: boolean
  unlocked_at: string | null
}

export interface NewsItem {
  id: number
  category: string
  severity: string
  icon: string
  headline: string
  body: string | null
  country: string | null
  occurred_at: string
}

export interface Stock {
  id: number
  key: string
  name: string
  sector: string
  share_price: number
  change_pct: number
  dividend_yield: number
  shares_held: number
  avg_cost: number
  position_value: number
  history: number[]
}

export interface Dashboard {
  company: Company
  active_shipments: Shipment[]
  fleet_summary: { idle: number; en_route: number; maintenance: number; total: number }
  pnl: Record<string, number>
  recent_ledger: LedgerEntry[]
  open_contracts: number
  my_open_contracts: number
  world_news: WorldEvent[]
  unlocked_achievements?: AchievementUnlock[]
}
