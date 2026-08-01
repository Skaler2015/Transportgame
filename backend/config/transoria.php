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
    'schema_version' => '2026.08.03-news',

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
    ],

    // Contract market generation.
    'contracts' => [
        'target_open_per_hub' => 12,  // keep roughly this many open per producing city
        'base_margin' => 0.28,        // payout premium over raw cargo value spread
        'rush_margin_bonus' => 0.35,  // extra premium for rush jobs
        'penalty_pct' => 0.4,         // failure penalty as fraction of payout
        'deadline_speed_kmh' => 62,   // reference speed used to set deadlines
        'deadline_slack' => 1.6,      // multiplier of ideal time allowed
        'ttl_hours' => 8,             // how long an unclaimed offer lives
    ],

    // Shipment resolution.
    'shipment' => [
        'fuel_price_weight' => 1.0,
        'late_payout_pct' => 0.55,    // fraction of payout still paid if late
        'accident_base_chance' => 0.03,
        'breakdown_base_chance' => 0.02,
        'condition_loss_per_1000km' => 6.5,
        'tire_loss_per_1000km' => 9.0,
        'fatigue_gain_per_trip' => 12,
        'xp_per_shipment' => 40,
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
    ],

    // Vehicle repair & upgrades.
    'garage' => [
        'repair_cost_per_point' => 900,     // ₡ cents per condition point restored
        'tire_cost_per_point' => 500,       // ₡ cents per tire-wear point restored
        'upgrade_base_cost' => 60_000_00,   // ₡ cents for level 1, scales with level
        'max_upgrade_level' => 3,
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

    // Guilds.
    'guild' => [
        'create_cost' => 80_000_00,   // ₡ cents to found a guild
        'max_members' => 30,
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
];
