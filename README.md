<div align="center">

# 🛰️ Transoria Online

### A persistent MMO logistics & transport-company simulation

*Start with one truck and a garage. Route cargo through a living economy,
hire crews, research autonomy, and grow into a continent-spanning freight empire —
in a world that never stops.*

**Vue 3 · TypeScript · TailwindCSS · Pinia · Leaflet  ·  Laravel · PHP 8.4 · Sanctum · SQLite/MySQL**

</div>

---

> **Original work.** Transoria Online is an original game — its world, brands,
> economy model, mechanics, UI and branding are invented for this project. It is
> **not** a clone of LogiTycoon or any other existing title.

## What this repository contains

This is a **working vertical slice** of the full game vision described in the design
brief: a real, playable core loop on the exact requested stack, plus the
architecture and design docs to grow into the larger MMO. It is honest about
what is built today versus what is on the roadmap — see
[Implemented vs. roadmap](#-implemented-vs-roadmap).

```
Transportgame/
├── backend/     Laravel API — domain, economy engine, world tick, REST + auth
├── frontend/    Vue 3 SPA   — dashboard, contract market, fleet, live map, markets, R&D
├── docs/        Game design document & deeper design notes
└── README.md    You are here
```

## 🎮 The core loop (playable today)

1. **Found a company** — register and you're handed ₡250,000, a garage in a
   home city, one mini-hauler and a driver.
2. **Claim a contract** — the world continuously mints haulage jobs: move *N*
   units of a commodity from a surplus city to a city in shortage, before a
   deadline, for a payout (with a penalty for failure).
3. **Dispatch** — assign a compatible truck + driver. Fuel is charged up front;
   speed depends on the driver's skill/fatigue, the weather and traffic.
4. **Deliver** — the world tick advances every truck and, on arrival, settles
   the outcome: on-time, late (reduced payout) or failed. Incidents (breakdowns,
   accidents) can strike en route.
5. **Grow** — payouts, reputation and XP flow in. Level up to earn research
   points, unlock better vehicles, research fuel economy / autonomy, and expand.

Everything monetary is computed **server-side and journaled** in a ledger — the
client never decides money (anti-cheat by design).

## 🌍 The living world

- **18 cities** across 5 fictional regions (Coreland, Ironvale, Marisands,
  Sunbelt, Northreach), each with population, fuel price, tax, traffic and weather.
- **20 commodities** in 7 categories (food, tech, industrial, raw, hazmat,
  luxury, livestock) with weight, volume and special handling (reefer, tanker, hazmat).
- **A dynamic economy** — every tick moves city stock, recomputes local prices
  from supply/demand, and records price history. Producers are cheap; starved
  consumers are dear; the spread is your margin.
- **World events** — fuel crises, festivals, storms, booms and embargoes spawn
  probabilistically, reshaping prices, demand, fuel and risk while active, and
  feeding a live news ticker.
- **A dealership** of 12 original vehicle models (Dart, Aurox, Kestrel, Voltra,
  Nimbus) across pickup → autonomous classes and diesel/electric/hydrogen powertrains.
- **An 8-node research tree** across Efficiency, Capability and Business branches.

## 🚀 Running it locally

**Requirements:** PHP 8.4+, Composer, Node 20+, npm. (No database server needed —
it ships with zero-config SQLite; switch to MySQL by editing `backend/.env`.)

### 1. Backend (Laravel API)

```bash
cd backend
composer install
cp .env.example .env          # if you don't already have a .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed    # builds the world: cities, commodities, dealership
php artisan world:tick        # mint the first batch of contracts & prices
php artisan serve --port=8811 # API now at http://127.0.0.1:8811/api
```

Keep the world advancing (economy, events, deliveries) with the scheduler:

```bash
php artisan schedule:work     # runs `world:tick` every minute
```

> During local play you can also advance the world on demand from the UI's
> **“Advance World”** button (calls `POST /api/dev/tick`, disabled in production).

### 2. Frontend (Vue SPA)

```bash
cd frontend
npm install
npm run dev                   # app at http://127.0.0.1:5188
```

The dev server proxies `/api` to `http://127.0.0.1:8811` (override with the
`VITE_API_PROXY` env var). Open the app, **Found a Company**, and start hauling.

### Tests

```bash
cd backend && php artisan test     # domain + full gameplay-loop feature tests
cd frontend && npm run build       # type-checks the whole SPA
```

## 🔌 API overview

Token auth via Laravel Sanctum (`Authorization: Bearer <token>`).
**All money fields are integer cents of the Credit (₡).**

| Method | Endpoint | Purpose |
|---|---|---|
| `POST` | `/api/register` | Create user **and** found starter company |
| `POST` | `/api/login` · `/api/logout` · `GET /api/me` | Session |
| `GET` | `/api/dashboard` | Aggregated command-center payload |
| `GET` | `/api/company` · `/api/ledger` | Company + financial journal |
| `GET` | `/api/contracts` | Open contract market (filter/sort) |
| `POST` | `/api/contracts/{id}/accept` | Claim a contract |
| `GET` | `/api/contracts/mine` | Your accepted / running contracts |
| `POST` | `/api/shipments/dispatch` | Send a truck + driver on a contract |
| `GET` | `/api/shipments` | Active + recent shipments (live progress) |
| `GET` | `/api/fleet` · `/api/dealership` · `POST /api/dealership/{model}/buy` | Vehicles |
| `GET` | `/api/drivers` · `POST /api/drivers/hire` | Crew |
| `GET` | `/api/market` · `/api/market/history` | Prices by city + history |
| `GET` | `/api/research` · `POST /api/research/{node}/unlock` | R&D tree |
| `GET` | `/api/world/cities` · `/commodities` · `/events` · `/leaderboard` | World data |

## 🧱 Architecture highlights

- **Server-authoritative simulation.** All game logic lives in dedicated
  services — `EconomyService` (prices, stock, contract minting),
  `ShipmentService` (dispatch validation, incident rolls, settlement),
  `EventService` (world events, weather drift), `CompanyService`,
  `ResearchService`, `LedgerService`. The `world:tick` command orchestrates them.
- **One knob-file.** `config/transoria.php` holds every balance constant, the
  research tree and the event catalog, so designers can rebalance without touching logic.
- **Money as an audit trail.** No code mutates `Company::cash` directly; every
  movement goes through `LedgerService` and is journaled with a running balance.
- **Time compression.** In-world hours map to real seconds
  (`seconds_per_game_hour`), so shipments complete in minutes while the world
  keeps advancing between ticks. The frontend interpolates truck progress live.
- **Typed frontend.** Pinia stores (`auth`, `game`, `toast`), an axios client
  with token interception, a router with auth guards, and a component library
  themed with a Tailwind design system (glassmorphism, dark theme).

## ✅ Implemented vs. roadmap

**Implemented and playable now**

- Auth + company founding, server-authoritative economy & world tick
- Contract market, dispatch, shipment resolution (on-time/late/failed + incidents)
- Fleet & dealership, drivers & hiring, research tree, financial ledger
- Dynamic prices + history, world events, weather, leaderboard
- Full premium SPA: dashboard, contracts, operations, fleet, crew, live map, markets, R&D
- Feature + unit tests for the core loop

**On the roadmap** (architecture is in place to add these)

- Warehouses & inventory arbitrage (schema present), guilds/corporations, player-to-player
  trade & auctions, WebSocket live push (currently polling), rail/air/sea multimodal
  legs, seasons & missions, cosmetics store, admin panel, 2FA.

See [`docs/GAME_DESIGN.md`](docs/GAME_DESIGN.md) for the full design document.

## 🧾 Tech notes

- Laravel is on the current stable release (13.x on PHP 8.4). The brief targeted
  Laravel 12; the newer line is API-compatible for this codebase and is the
  supported version for PHP 8.4 at time of writing.
- SQLite is the zero-config default so the game runs anywhere. For production/MMO
  scale, point `DB_CONNECTION=mysql` at MySQL and add Redis for cache/queues
  (the queue/cache drivers are already wired through Laravel's config).

## 📜 License

Original work created for this project. No third-party game assets or content
are reused.
