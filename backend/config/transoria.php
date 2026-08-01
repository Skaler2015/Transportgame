<?php

/**
 * Transoria Online — central game-balance configuration.
 *
 * Every tunable number that shapes the economy and progression lives here so
 * designers can rebalance without touching game logic. Values are deliberately
 * original and not derived from any existing title.
 */
return [

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
