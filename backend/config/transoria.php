<?php

/**
 * Transoria Online — central game-balance configuration.
 *
 * Every tunable number that shapes the economy and progression lives here so
 * designers can rebalance without touching game logic. Values are deliberately
 * original and not derived from any existing title.
 */
return [

    // Deploy/schema signature. Bump this string whenever a release adds a
    // migration or changes seeded world data. On the first web request after a
    // deploy the app notices the stored marker no longer matches and runs
    // `migrate --force` + `transoria:worldsync` once, so shared hosts that
    // never run the CLI still stay fully migrated. See EnsureSchemaUpToDate.
    'schema_version' => '2026.08.17-manufacturing',

    // In-game currency label.
    'currency' => ['code' => 'CR', 'symbol' => '₡', 'name' => 'Credits'],

    // Playable countries. Each player's world (cities, contracts, markets) is
    // scoped to their chosen country. 'IN' (India) is the default for existing
    // and new players. Keys are ISO-3166 alpha-2 codes.
    'countries' => [
        'IN' => 'India',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'AE' => 'United Arab Emirates',
    ],
    'default_country' => 'IN',

    // Each country's real currency. Money is stored on the same numeric scale
    // everywhere (game balance); only the display symbol/code changes.
    'country_currency' => [
        'IN' => ['symbol' => '₹', 'code' => 'INR'],
        'US' => ['symbol' => '$', 'code' => 'USD'],
        'GB' => ['symbol' => '£', 'code' => 'GBP'],
        'AE' => ['symbol' => 'د.إ ', 'code' => 'AED'],
    ],

    // Starting loadout for a brand-new company.
    'starter' => [
        'cash' => 250_000_00,        // ₡2,500.00 in cents (stored *100)
        'reputation' => 500,          // mid-scale (0..1000)
        'vehicle_model' => 'dart-mv3', // the free first truck (a nimble mini hauler)
        'drivers' => 1,
    ],

    // World simulation cadence. One "tick" advances prices, shipments and events.
    'tick' => [
        'minutes' => 15,              // wall-clock minutes represented by one tick
        'game_hours_per_tick' => 1,   // in-world hours advanced per tick
        'seconds_per_game_hour' => 60, // time compression: 1 in-world hour = 60 real seconds
        'price_history_keep' => 96,   // rows kept per city/commodity (~4 days at 1h)
    ],

    // Economy model.
    'economy' => [
        // Local price = base * (1 + elasticity * (demand_index - 1)), clamped.
        'price_elasticity' => 0.9,
        'price_floor_pct' => 0.45,    // never below 45% of base
        'price_ceiling_pct' => 2.6,   // never above 260% of base
        'demand_smoothing' => 0.35,   // EMA factor for demand index
        'fuel_drift' => 0.04,         // max fractional fuel-price wander per tick

        // Living-economy v2:
        // A gentle deterministic wander (bounded fraction) so even a quiet
        // commodity's price history breathes hour to hour, never flat-lines.
        'market_noise_pct' => 0.03,
        // Agglomeration: the more firms (players + rival AI) headquartered in a
        // region, the hotter its local demand — so the rival companies you
        // compete with visibly bid up prices where they cluster. Capped uplift.
        'commercial_demand_per_firm' => 0.006,
        'commercial_demand_cap' => 0.15,
    ],

    // Seasonal demand. The current calendar month shifts demand for whole
    // commodity categories, so prices breathe with the year (Diwali, monsoon,
    // winter holidays…). Multipliers apply to the demand index before pricing.
    'seasons' => [
        ['name' => 'Festival Season', 'months' => [10, 11], 'demand' => ['luxury' => 1.35, 'food' => 1.20, 'tech' => 1.25, 'livestock' => 1.15]],
        ['name' => 'Winter Holidays', 'months' => [12, 1], 'demand' => ['luxury' => 1.25, 'food' => 1.15, 'tech' => 1.20]],
        ['name' => 'Spring Build', 'months' => [2, 3], 'demand' => ['industrial' => 1.12, 'raw' => 1.08]],
        ['name' => 'Summer Peak', 'months' => [4, 5], 'demand' => ['food' => 1.12, 'industrial' => 1.05]],
        ['name' => 'Monsoon', 'months' => [6, 7, 8, 9], 'demand' => ['food' => 1.15, 'industrial' => 0.90, 'raw' => 0.92]],
    ],

    /*
    |--------------------------------------------------------------------------
    | Weather v2 — conditions that bite
    |--------------------------------------------------------------------------
    | Each state carries multipliers a shipment actually feels along its lane:
    | speed (ETA), fuel (burn), accident (incident odds), wear (extra vehicle
    | condition/tyre loss) and spoilage (fraction of perishable cargo lost in
    | that weather). `severity` groups them for the map colouring, `icon` for
    | tooltips. Weather is generated with seasonal bias, regional coherence and
    | persistence in EventService::driftCities.
    */
    'weather' => [
        'clear'   => ['label' => 'Clear',    'icon' => '☀️', 'severity' => 'calm',    'speed' => 1.00, 'fuel' => 1.00, 'accident' => 1.0, 'wear' => 1.00, 'spoilage' => 0.00],
        'wind'    => ['label' => 'Windy',    'icon' => '💨', 'severity' => 'calm',    'speed' => 0.97, 'fuel' => 1.06, 'accident' => 1.1, 'wear' => 1.02, 'spoilage' => 0.00],
        'rain'    => ['label' => 'Rain',     'icon' => '🌧️', 'severity' => 'moderate','speed' => 0.90, 'fuel' => 1.05, 'accident' => 1.4, 'wear' => 1.05, 'spoilage' => 0.02],
        'fog'     => ['label' => 'Fog',      'icon' => '🌫️', 'severity' => 'moderate','speed' => 0.85, 'fuel' => 1.02, 'accident' => 1.6, 'wear' => 1.00, 'spoilage' => 0.00],
        'heat'    => ['label' => 'Heatwave', 'icon' => '🔥', 'severity' => 'moderate','speed' => 0.95, 'fuel' => 1.10, 'accident' => 1.1, 'wear' => 1.08, 'spoilage' => 0.10],
        'snow'    => ['label' => 'Snow',     'icon' => '❄️', 'severity' => 'severe',  'speed' => 0.75, 'fuel' => 1.15, 'accident' => 1.9, 'wear' => 1.12, 'spoilage' => 0.03],
        'storm'   => ['label' => 'Storm',    'icon' => '⛈️', 'severity' => 'severe',  'speed' => 0.70, 'fuel' => 1.18, 'accident' => 2.2, 'wear' => 1.15, 'spoilage' => 0.06],
        'flood'   => ['label' => 'Flood',    'icon' => '🌊', 'severity' => 'extreme', 'speed' => 0.60, 'fuel' => 1.20, 'accident' => 2.4, 'wear' => 1.20, 'spoilage' => 0.08],
        'cyclone' => ['label' => 'Cyclone',  'icon' => '🌀', 'severity' => 'extreme', 'speed' => 0.50, 'fuel' => 1.28, 'accident' => 3.0, 'wear' => 1.25, 'spoilage' => 0.12],
    ],

    // How weather is generated each tick. `persistence` = chance a city keeps
    // its current weather; otherwise it re-rolls from the season's weighted bag,
    // biased toward the region's prevailing weather for that day.
    'weather_gen' => [
        'persistence' => 0.68,
        'regional_pull' => 0.6,   // when re-rolling, chance to match the region
        // Weighted weather bags by calendar month (1–12).
        'season_bags' => [
            'monsoon' => ['clear' => 2, 'rain' => 5, 'storm' => 3, 'flood' => 2, 'wind' => 2, 'fog' => 1],
            'winter'  => ['clear' => 4, 'fog' => 3, 'snow' => 2, 'rain' => 1, 'wind' => 1],
            'summer'  => ['clear' => 4, 'heat' => 4, 'wind' => 2, 'storm' => 1],
            'default' => ['clear' => 5, 'rain' => 2, 'wind' => 2, 'fog' => 1, 'heat' => 1],
        ],
        // month → bag key
        'month_season' => [1 => 'winter', 2 => 'winter', 3 => 'summer', 4 => 'summer', 5 => 'summer',
            6 => 'monsoon', 7 => 'monsoon', 8 => 'monsoon', 9 => 'monsoon', 10 => 'default', 11 => 'default', 12 => 'winter'],
        // Active event types that force harsher weather over their scope.
        'event_weather' => ['storm' => 'storm', 'cyclone' => 'cyclone', 'flood' => 'flood'],
    ],

    // Contract market generation.
    'contracts' => [
        'target_open_per_hub' => 12,  // keep roughly this many open per producing city
        'min_payout' => 2_000_00,     // floor on contract payout (cents) — no job pays under ₹2,000
        'rate_per_km_min' => 5.0,     // freight priced per km — lower bound (₹/km)
        'rate_per_km_max' => 5.0,     // freight priced per km — upper bound (₹/km)
        'base_margin' => 0.28,        // (legacy) payout premium over raw cargo value spread
        'rush_margin_bonus' => 0.35,  // extra premium for rush jobs
        'penalty_pct' => 0.4,         // failure penalty as fraction of payout
        'deadline_speed_kmh' => 62,   // reference speed used to set deadlines
        'deadline_slack' => 1.6,      // multiplier of ideal time allowed
        'ttl_hours' => 8,             // how long an unclaimed offer lives
    ],

    // Shipment resolution.
    'shipment' => [
        // Base travel speed in km per REAL minute (before weather/traffic/road/
        // driver factors). Higher = faster deliveries.
        'km_per_minute' => 225,
        'fuel_price_weight' => 1.0,
        'late_payout_pct' => 0.55,    // fraction of payout still paid if late
        'accident_base_chance' => 0.03,
        'breakdown_base_chance' => 0.02,
        'condition_loss_per_1000km' => 6.5,
        'tire_loss_per_1000km' => 9.0,
        'fatigue_gain_per_trip' => 12,
        'xp_per_shipment' => 40,
        // Auto-refuel (fuel only) the truck the moment a run ends, so the tank
        // is ready for the next dispatch. Servicing stays a manual choice.
        'auto_refuel_on_arrival' => true,
    ],

    // Research tree. Each node: cost in research points, prerequisites, and the
    // operational bonus it grants. Bonuses are summed across unlocked nodes.
    'research' => [
        'eco_tuning' => [
            'name' => 'Eco Engine Tuning',
            'branch' => 'efficiency',
            'cost' => 30,
            'requires' => [],
            'bonus' => ['fuel_economy' => -0.08], // burns 8% less fuel
        ],
        'route_ai' => [
            'name' => 'Adaptive Route AI',
            'branch' => 'efficiency',
            'cost' => 55,
            'requires' => ['eco_tuning'],
            'bonus' => ['distance_factor' => -0.05], // 5% shorter effective routes
        ],
        'telematics' => [
            'name' => 'Fleet Telematics',
            'branch' => 'efficiency',
            'cost' => 80,
            'requires' => ['route_ai'],
            'bonus' => ['breakdown_chance' => -0.4], // 40% fewer breakdowns
        ],
        'reefer_tech' => [
            'name' => 'Cryo Reefer Systems',
            'branch' => 'capability',
            'cost' => 60,
            'requires' => [],
            'bonus' => ['perishable_risk' => -0.35],
        ],
        'hazmat_program' => [
            'name' => 'Hazmat Certification Program',
            'branch' => 'capability',
            'cost' => 70,
            'requires' => [],
            'bonus' => ['hazmat_payout' => 0.15],
        ],
        'autonomy' => [
            'name' => 'Autonomous Convoys',
            'branch' => 'capability',
            'cost' => 140,
            'requires' => ['telematics'],
            'bonus' => ['driver_fatigue' => -0.5, 'speed_factor' => 0.06],
        ],
        'brand_marketing' => [
            'name' => 'Brand Marketing',
            'branch' => 'business',
            'cost' => 45,
            'requires' => [],
            'bonus' => ['reputation_gain' => 0.25],
        ],
        'logistics_school' => [
            'name' => 'Logistics Academy',
            'branch' => 'business',
            'cost' => 65,
            'requires' => ['brand_marketing'],
            'bonus' => ['driver_skill_gain' => 0.3],
        ],
    ],

    // Warehouses & direct commodity trading.
    'warehouse' => [
        'build_cost' => 120_000_00,   // ₡ cents to build a warehouse
        'base_capacity' => 5000,      // units at tier 1
        'buy_spread' => 0.03,         // you buy 3% above local price
        'sell_spread' => 0.03,        // you sell 3% below local price

        // Expansion (tier) — bigger footprint, higher upkeep.
        'expand_cost' => 100_000_00,  // × current tier
        'capacity_per_tier' => 5000,
        'upkeep_per_tier' => 300_00,
        'max_tier' => 6,

        // One-off facilities.
        'cold_cost' => 80_000_00,     // cold storage → store perishables
        'hazmat_cost' => 90_000_00,   // hazmat bay → store hazardous goods
        'automation_cost' => 150_000_00, // tighter trade spreads

        // Levelled facilities (workers/forklifts, CCTV/security).
        'staff_cost' => 60_000_00,    // × next level
        'security_cost' => 50_000_00, // × next level
        'max_staff_level' => 3,
        'max_security_level' => 3,

        // Effects.
        'automation_spread_cut' => 0.010, // −1% each side
        'staff_spread_cut' => 0.004,      // −0.4% each side per staff level
        'staff_capacity_bonus' => 0.15,   // +15% effective capacity per staff level
        'security_capacity_bonus' => 0.08, // +8% effective capacity per security level
        'min_spread' => 0.005,
    ],

    // Vehicle repair, service & upgrades.
    'garage' => [
        'repair_cost_per_point' => 900,     // ₡ cents per condition point restored
        'tire_cost_per_point' => 500,       // ₡ cents per tire-wear point restored
        'upgrade_base_cost' => 60_000_00,   // ₡ cents for level 1, scales with level
        'max_upgrade_level' => 3,

        // Engine oil & battery wear per 1000 km, and the cost to restore them.
        'oil_loss_per_1000km' => 14.0,
        'battery_loss_per_1000km' => 6.0,
        'oil_change_cost' => 8_000_00,
        'battery_cost' => 16_000_00,

        // Papers: how long a renewal lasts (real days) and what it costs.
        'insurance_days' => 7,
        'insurance_cost' => 22_000_00,
        'registration_days' => 30,
        'registration_cost' => 45_000_00,
    ],

    // Financing.
    'finance' => [
        'max_loan_multiple' => 3.0,   // can borrow up to 3x current cash
        'interest_per_tick' => 0.006, // ~0.6% per tick on outstanding balance
        'min_loan' => 50_000_00,
    ],

    // Missions: templates rolled per period. reward scales lightly with level.
    'missions' => [
        'daily_count' => 3,
        'weekly_count' => 2,
        'templates' => [
            ['period' => 'daily', 'metric' => 'deliveries', 'title' => 'Keep Rolling', 'target' => 3, 'cash' => 8_000_00, 'xp' => 60],
            ['period' => 'daily', 'metric' => 'on_time', 'title' => 'On the Dot', 'target' => 2, 'cash' => 10_000_00, 'xp' => 70],
            ['period' => 'daily', 'metric' => 'revenue', 'title' => 'Daily Earner', 'target' => 40_000, 'cash' => 9_000_00, 'xp' => 50],
            ['period' => 'daily', 'metric' => 'distance', 'title' => 'Long Haul', 'target' => 1500, 'cash' => 9_000_00, 'xp' => 55],
            ['period' => 'weekly', 'metric' => 'deliveries', 'title' => 'Freight Baron', 'target' => 20, 'cash' => 60_000_00, 'xp' => 400],
            ['period' => 'weekly', 'metric' => 'revenue', 'title' => 'Big Money Week', 'target' => 300_000, 'cash' => 70_000_00, 'xp' => 450],
        ],
    ],

    // Trailers & fuel — the LogiTycoon-style haulage loop: a road tractor pulls
    // a trailer whose type must match the cargo, and every trip burns fuel from
    // the vehicle's own tank (refuel to top it up).
    'equipment' => [
        // Trailer condition wear per 1000 km hauled.
        'trailer_wear_per_1000km' => 5.0,
        // A brand-new company (and legacy companies) get this starter trailer so
        // their bigger tractors can haul from day one.
        'starter_trailer' => 'box-std',
    ],

    'fuel' => [
        // When a tank drops below this fraction, the UI nudges a refuel.
        'low_warning_pct' => 0.25,
        // Small handling margin added when refuelling to be safe on a trip.
        'refuel_headroom' => 1.0,
    ],

    // Driver careers.
    'driver' => [
        'train_cost' => 12_000_00,     // ₡ cents to send a driver on a training course
        'train_skill_gain' => 6,       // skill points per course
        'train_specialty_gain' => 5,   // rain/eco points per course (rotates)
        'licence_days' => 30,          // how long a licence renewal lasts (real days)
        'licence_cost' => 15_000_00,   // ₡ cents to renew a driving licence
        'vacation_cost' => 8_000_00,   // ₡ cents — restores health, morale, fatigue
        'ranks' => [
            ['name' => 'Rookie', 'xp' => 0],
            ['name' => 'Pro', 'xp' => 50],
            ['name' => 'Veteran', 'xp' => 150],
            ['name' => 'Elite', 'xp' => 400],
            ['name' => 'Legend', 'xp' => 1000],
        ],
    ],

    // Guilds.
    'guild' => [
        'create_cost' => 80_000_00,   // ₡ cents to found a guild
        'max_members' => 30,
    ],

    // Stock exchange.
    'stocks' => [
        'price_tick_seconds' => 60,        // min gap between lazy price walks
        'dividend_interval_minutes' => 20, // how often a holding pays out
        'price_floor_mult' => 0.3,         // vs base price
        'price_ceiling_mult' => 4.0,
    ],

    // Player exchange.
    'exchange' => [
        'listing_ttl_hours' => 24,
        'fee_pct' => 0.02,            // 2% market fee on sale, taken from seller
    ],

    // Achievements. Each is unlocked when a company metric crosses a threshold;
    // metric ∈ deliveries, revenue(cents), cash(cents), level, reputation,
    // fleet, drivers, trailers, warehouses. Reward = cash(cents) + xp. Add more
    // freely — the system is fully config-driven.
    'achievements' => [
        // Deliveries
        ['key' => 'deliver_1', 'name' => 'First Haul', 'desc' => 'Complete your first delivery.', 'icon' => '📦', 'category' => 'Deliveries', 'metric' => 'deliveries', 'threshold' => 1, 'cash' => 2_000_00, 'xp' => 50],
        ['key' => 'deliver_10', 'name' => 'Getting Rolling', 'desc' => 'Complete 10 deliveries.', 'icon' => '🚚', 'category' => 'Deliveries', 'metric' => 'deliveries', 'threshold' => 10, 'cash' => 8_000_00, 'xp' => 120],
        ['key' => 'deliver_50', 'name' => 'Road Warrior', 'desc' => 'Complete 50 deliveries.', 'icon' => '🛣️', 'category' => 'Deliveries', 'metric' => 'deliveries', 'threshold' => 50, 'cash' => 30_000_00, 'xp' => 400],
        ['key' => 'deliver_250', 'name' => 'Freight Baron', 'desc' => 'Complete 250 deliveries.', 'icon' => '👑', 'category' => 'Deliveries', 'metric' => 'deliveries', 'threshold' => 250, 'cash' => 120_000_00, 'xp' => 1500],
        ['key' => 'deliver_1000', 'name' => 'Logistics King', 'desc' => 'Complete 1,000 deliveries.', 'icon' => '🏆', 'category' => 'Deliveries', 'metric' => 'deliveries', 'threshold' => 1000, 'cash' => 600_000_00, 'xp' => 6000],

        // Revenue (lifetime, in cents)
        ['key' => 'rev_500k', 'name' => 'Half a Million', 'desc' => 'Earn ₹500,000 in lifetime revenue.', 'icon' => '💰', 'category' => 'Wealth', 'metric' => 'revenue', 'threshold' => 500_000_00, 'cash' => 10_000_00, 'xp' => 200],
        ['key' => 'rev_5m', 'name' => 'Big Earner', 'desc' => 'Earn ₹5,000,000 in lifetime revenue.', 'icon' => '💵', 'category' => 'Wealth', 'metric' => 'revenue', 'threshold' => 5_000_000_00, 'cash' => 60_000_00, 'xp' => 800],
        ['key' => 'rev_50m', 'name' => 'Tycoon', 'desc' => 'Earn ₹50,000,000 in lifetime revenue.', 'icon' => '🏦', 'category' => 'Wealth', 'metric' => 'revenue', 'threshold' => 50_000_000_00, 'cash' => 400_000_00, 'xp' => 4000],
        ['key' => 'rev_500m', 'name' => 'Magnate', 'desc' => 'Earn ₹500,000,000 in lifetime revenue.', 'icon' => '💎', 'category' => 'Wealth', 'metric' => 'revenue', 'threshold' => 500_000_000_00, 'cash' => 3_000_000_00, 'xp' => 25000],

        // Cash on hand
        ['key' => 'millionaire', 'name' => 'Millionaire', 'desc' => 'Hold ₹1,000,000 in cash.', 'icon' => '🤑', 'category' => 'Wealth', 'metric' => 'cash', 'threshold' => 1_000_000_00, 'cash' => 0, 'xp' => 300],
        ['key' => 'multimillionaire', 'name' => 'Multi-Millionaire', 'desc' => 'Hold ₹10,000,000 in cash.', 'icon' => '💸', 'category' => 'Wealth', 'metric' => 'cash', 'threshold' => 10_000_000_00, 'cash' => 0, 'xp' => 1500],

        // Fleet
        ['key' => 'fleet_3', 'name' => 'Small Fleet', 'desc' => 'Own 3 vehicles.', 'icon' => '🚐', 'category' => 'Fleet', 'metric' => 'fleet', 'threshold' => 3, 'cash' => 5_000_00, 'xp' => 100],
        ['key' => 'fleet_10', 'name' => 'Fleet Master', 'desc' => 'Own 10 vehicles.', 'icon' => '🚛', 'category' => 'Fleet', 'metric' => 'fleet', 'threshold' => 10, 'cash' => 40_000_00, 'xp' => 500],
        ['key' => 'fleet_25', 'name' => 'Mega Fleet', 'desc' => 'Own 25 vehicles.', 'icon' => '🚢', 'category' => 'Fleet', 'metric' => 'fleet', 'threshold' => 25, 'cash' => 150_000_00, 'xp' => 2000],
        ['key' => 'fleet_50', 'name' => 'Fleet Emperor', 'desc' => 'Own 50 vehicles.', 'icon' => '✈️', 'category' => 'Fleet', 'metric' => 'fleet', 'threshold' => 50, 'cash' => 500_000_00, 'xp' => 5000],

        // Trailers / Drivers / Warehouses
        ['key' => 'trailers_3', 'name' => 'Trailer Park', 'desc' => 'Own 3 trailers.', 'icon' => '🚋', 'category' => 'Equipment', 'metric' => 'trailers', 'threshold' => 3, 'cash' => 6_000_00, 'xp' => 120],
        ['key' => 'trailers_10', 'name' => 'Trailer Tycoon', 'desc' => 'Own 10 trailers.', 'icon' => '🧲', 'category' => 'Equipment', 'metric' => 'trailers', 'threshold' => 10, 'cash' => 40_000_00, 'xp' => 600],
        ['key' => 'drivers_5', 'name' => 'Team Builder', 'desc' => 'Employ 5 drivers.', 'icon' => '🧑‍✈️', 'category' => 'Crew', 'metric' => 'drivers', 'threshold' => 5, 'cash' => 10_000_00, 'xp' => 200],
        ['key' => 'drivers_20', 'name' => 'HR Boss', 'desc' => 'Employ 20 drivers.', 'icon' => '👥', 'category' => 'Crew', 'metric' => 'drivers', 'threshold' => 20, 'cash' => 80_000_00, 'xp' => 1000],
        ['key' => 'warehouse_1', 'name' => 'Store Keeper', 'desc' => 'Build your first warehouse.', 'icon' => '🏬', 'category' => 'Trade', 'metric' => 'warehouses', 'threshold' => 1, 'cash' => 8_000_00, 'xp' => 150],
        ['key' => 'warehouse_5', 'name' => 'Warehouse Mogul', 'desc' => 'Own 5 warehouses.', 'icon' => '🏭', 'category' => 'Trade', 'metric' => 'warehouses', 'threshold' => 5, 'cash' => 120_000_00, 'xp' => 1500],

        // Level
        ['key' => 'level_5', 'name' => 'Rising Star', 'desc' => 'Reach level 5.', 'icon' => '⭐', 'category' => 'Progression', 'metric' => 'level', 'threshold' => 5, 'cash' => 15_000_00, 'xp' => 0],
        ['key' => 'level_10', 'name' => 'Seasoned CEO', 'desc' => 'Reach level 10.', 'icon' => '🌟', 'category' => 'Progression', 'metric' => 'level', 'threshold' => 10, 'cash' => 50_000_00, 'xp' => 0],
        ['key' => 'level_20', 'name' => 'Industry Veteran', 'desc' => 'Reach level 20.', 'icon' => '💫', 'category' => 'Progression', 'metric' => 'level', 'threshold' => 20, 'cash' => 200_000_00, 'xp' => 0],
        ['key' => 'level_30', 'name' => 'Living Legend', 'desc' => 'Reach level 30.', 'icon' => '🔥', 'category' => 'Progression', 'metric' => 'level', 'threshold' => 30, 'cash' => 750_000_00, 'xp' => 0],

        // Reputation
        ['key' => 'rep_700', 'name' => 'Trusted Carrier', 'desc' => 'Reach 700 reputation.', 'icon' => '🤝', 'category' => 'Reputation', 'metric' => 'reputation', 'threshold' => 700, 'cash' => 20_000_00, 'xp' => 300],
        ['key' => 'rep_900', 'name' => 'Elite Carrier', 'desc' => 'Reach 900 reputation.', 'icon' => '🎖️', 'category' => 'Reputation', 'metric' => 'reputation', 'threshold' => 900, 'cash' => 80_000_00, 'xp' => 800],
        ['key' => 'rep_1000', 'name' => 'Flawless', 'desc' => 'Reach maximum reputation.', 'icon' => '💠', 'category' => 'Reputation', 'metric' => 'reputation', 'threshold' => 1000, 'cash' => 250_000_00, 'xp' => 2500],
    ],

    // World events the tick engine can roll. Weight = relative likelihood.
    'events' => [
        'fuel_crisis' => [
            'title' => 'Regional Fuel Crisis',
            'weight' => 3,
            'severity' => 'major',
            'duration_hours' => [6, 18],
            'modifiers' => ['fuel_modifier' => 1.45],
        ],
        'festival' => [
            'title' => 'Harvest Festival Surge',
            'weight' => 5,
            'severity' => 'minor',
            'duration_hours' => [4, 12],
            'modifiers' => ['demand_modifier' => 1.4, 'price_modifier' => 1.2],
        ],
        'storm' => [
            'title' => 'Coastal Storm Front',
            'weight' => 4,
            'severity' => 'major',
            'duration_hours' => [3, 10],
            'modifiers' => ['risk_modifier' => 1.6],
        ],
        'boom' => [
            'title' => 'Industrial Boom',
            'weight' => 3,
            'severity' => 'minor',
            'duration_hours' => [8, 24],
            'modifiers' => ['demand_modifier' => 1.5],
        ],
        'embargo' => [
            'title' => 'Trade Embargo',
            'weight' => 2,
            'severity' => 'critical',
            'duration_hours' => [12, 36],
            'modifiers' => ['price_modifier' => 1.7, 'demand_modifier' => 0.6],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI companies (Phase 1 — autonomous competitors)
    |--------------------------------------------------------------------------
    | Rival logistics firms that live in the same shared world as players. They
    | are ordinary `companies` rows flagged `is_ai`, owned by one system user,
    | with their fleet modelled as a single `ai_fleet_size` counter (NOT one
    | Vehicle row each) so hundreds of rivals cost almost nothing on shared
    | hosting. Their economy is stepped in game-hours during the lazy world
    | tick, deterministically (crc32 of id+hour) so catch-up is reproducible.
    */
    'ai' => [
        // Target number of live (non-bankrupt) rivals per country.
        'roster_size' => 24,
        // Real seconds between roster/step passes (rate-limits the lazy hook).
        'step_cooldown_seconds' => 20,
        // Cap how many game-hours a single catch-up may simulate, so a long
        // quiet period can't spike one request into a huge loop.
        'max_catchup_hours' => 12,

        // Starting endowment for a freshly founded rival.
        'seed_cash' => [180000_00, 900000_00],   // cents, [min, max]
        'seed_fleet' => [3, 14],
        'seed_reputation' => [420, 760],

        // Per-truck economics per simulated game-hour (cents).
        'revenue_per_truck_hour' => 4200_00,
        'cost_per_truck_hour' => 2650_00,
        'overhead_per_hour' => 5200_00,          // head-office burn, flat
        'deliveries_per_truck_day' => 6,         // for shipments_completed drift

        // Expansion: when cash clears this, a growth-minded rival buys trucks.
        'expand_cash_floor' => 600000_00,
        'truck_capex' => 220000_00,              // cost to add one truck
        'expand_batch' => [1, 3],

        // Bankruptcy: rivals in the red for this many consecutive game-hours
        // fold, then a fresh firm is founded to keep the roster full.
        'bankrupt_after_hours' => 18,

        // Behaviour archetypes and how they bend the numbers. Weighted pick.
        'strategies' => [
            'expander'   => ['weight' => 3, 'revenue' => 1.05, 'cost' => 1.08, 'expand' => 1.6, 'rep_target' => 600],
            'undercutter'=> ['weight' => 3, 'revenue' => 0.88, 'cost' => 0.82, 'expand' => 1.0, 'rep_target' => 520],
            'premium'    => ['weight' => 2, 'revenue' => 1.22, 'cost' => 1.12, 'expand' => 0.7, 'rep_target' => 820],
            'regional'   => ['weight' => 2, 'revenue' => 0.98, 'cost' => 0.95, 'expand' => 0.9, 'rep_target' => 640],
        ],

        // Name generator parts for founding believable rivals.
        'name_prefixes' => ['Apex', 'Meridian', 'Vanguard', 'Orient', 'Summit', 'Ironclad', 'Falcon',
            'Continental', 'Pioneer', 'Crest', 'Nexus', 'Titan', 'Horizon', 'Cargo', 'Bharat', 'Indus',
            'Coromandel', 'Deccan', 'Ganges', 'Sahyadri', 'Konkan', 'Aravalli'],
        'name_suffixes' => ['Logistics', 'Freightways', 'Carriers', 'Transport', 'Haulage', 'Movers',
            'Cargo Lines', 'Roadways', 'Supply Co', 'Distribution', 'Forwarders'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Manufacturing (Phase — factories & supply chains)
    |--------------------------------------------------------------------------
    | A factory is built at one of the company's warehouses and, each cycle,
    | turns cash (+ optional input commodities drawn from that warehouse) into
    | output units deposited back into the warehouse — which the player then
    | sells locally or hauls to a dearer market. Extractors have no commodity
    | input (they bootstrap the chain); factories consume the outputs of other
    | factories, so real chains emerge: sawmill → timber → furniture, or
    | mine + electronics plant → auto plant. Production runs on the lazy tick.
    |
    | Costs are in integer cents (₡). Output/inputs/op-cost scale with level.
    */
    'manufacturing' => [
        'cycle_seconds' => 300,       // one production cycle per 5 real minutes
        'max_catchup_cycles' => 12,   // bound lazy catch-up after a quiet spell
        'level_step' => 0.6,          // +60% throughput & cost per level above 1
        'max_level' => 5,
        'upgrade_cost_mult' => 0.75,  // upgrade costs 75% of build cost × level

        'recipes' => [
            // ---- Extractors (cash → raw output, no commodity input) ----------
            'farm'            => ['name' => 'Grain Farm',        'icon' => '🌾', 'output' => 'grain',        'output_qty' => 40, 'inputs' => [], 'op_cost' => 800_00,  'build_cost' => 400000_00,  'upkeep' => 1200_00, 'unlock' => 1],
            'water_plant'     => ['name' => 'Bottling Plant',    'icon' => '💧', 'output' => 'bottled_water','output_qty' => 60, 'inputs' => [], 'op_cost' => 600_00,  'build_cost' => 300000_00,  'upkeep' => 900_00,  'unlock' => 1],
            'textile_mill'    => ['name' => 'Textile Mill',      'icon' => '🧵', 'output' => 'textiles',     'output_qty' => 22, 'inputs' => [], 'op_cost' => 1600_00, 'build_cost' => 700000_00,  'upkeep' => 1800_00, 'unlock' => 1],
            'quarry'          => ['name' => 'Cement Quarry',     'icon' => '⛏️', 'output' => 'cement',       'output_qty' => 30, 'inputs' => [], 'op_cost' => 1000_00, 'build_cost' => 500000_00,  'upkeep' => 1500_00, 'unlock' => 2],
            'sawmill'         => ['name' => 'Sawmill',           'icon' => '🪵', 'output' => 'timber',       'output_qty' => 26, 'inputs' => [], 'op_cost' => 1200_00, 'build_cost' => 550000_00,  'upkeep' => 1600_00, 'unlock' => 2],
            'mine'            => ['name' => 'Steel Mine',        'icon' => '⚒️', 'output' => 'steel_coil',   'output_qty' => 16, 'inputs' => [], 'op_cost' => 2400_00, 'build_cost' => 1100000_00, 'upkeep' => 2600_00, 'unlock' => 3],
            'electronics_plant' => ['name' => 'Electronics Plant','icon' => '🔌','output' => 'electronics',  'output_qty' => 12, 'inputs' => [], 'op_cost' => 5200_00, 'build_cost' => 1600000_00, 'upkeep' => 3200_00, 'unlock' => 3],
            'pharma_lab'      => ['name' => 'Pharma Lab',        'icon' => '💊', 'output' => 'medicine',     'output_qty' => 8,  'inputs' => [], 'op_cost' => 6000_00, 'build_cost' => 1800000_00, 'upkeep' => 3600_00, 'unlock' => 4, 'needs' => 'cold'],
            'oil_well'        => ['name' => 'Oil Well',          'icon' => '🛢️', 'output' => 'crude_oil',    'output_qty' => 20, 'inputs' => [], 'op_cost' => 2600_00, 'build_cost' => 1200000_00, 'upkeep' => 2800_00, 'unlock' => 5, 'needs' => 'hazmat'],

            // ---- Factories (consume other outputs → finished goods) ----------
            'furniture_factory' => ['name' => 'Furniture Factory','icon' => '🪑','output' => 'furniture',   'output_qty' => 14, 'inputs' => ['timber' => 20],                       'op_cost' => 1400_00, 'build_cost' => 800000_00,  'upkeep' => 2000_00, 'unlock' => 2],
            'machinery_works' => ['name' => 'Machinery Works',   'icon' => '⚙️', 'output' => 'machinery',    'output_qty' => 10, 'inputs' => ['steel_coil' => 14],                  'op_cost' => 3000_00, 'build_cost' => 1700000_00, 'upkeep' => 3400_00, 'unlock' => 4],
            'refinery'        => ['name' => 'Oil Refinery',      'icon' => '🏭', 'output' => 'fuel',         'output_qty' => 16, 'inputs' => ['crude_oil' => 18],                   'op_cost' => 1800_00, 'build_cost' => 1500000_00, 'upkeep' => 3000_00, 'unlock' => 5, 'needs' => 'hazmat'],
            'chemical_plant'  => ['name' => 'Chemical Plant',    'icon' => '⚗️', 'output' => 'chemicals',    'output_qty' => 14, 'inputs' => ['crude_oil' => 16],                   'op_cost' => 2000_00, 'build_cost' => 1500000_00, 'upkeep' => 3000_00, 'unlock' => 6, 'needs' => 'hazmat'],
            'solar_factory'   => ['name' => 'Solar Factory',     'icon' => '☀️', 'output' => 'solar_panels', 'output_qty' => 10, 'inputs' => ['electronics' => 12],                 'op_cost' => 2400_00, 'build_cost' => 1600000_00, 'upkeep' => 3200_00, 'unlock' => 5],
            'auto_plant'      => ['name' => 'Auto Plant',        'icon' => '🚗', 'output' => 'automobiles',  'output_qty' => 4,  'inputs' => ['steel_coil' => 10, 'electronics' => 8],'op_cost' => 6000_00, 'build_cost' => 2500000_00, 'upkeep' => 5000_00, 'unlock' => 6],
        ],
    ],
];
