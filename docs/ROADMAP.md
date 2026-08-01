# Transoria Online — Production Roadmap

**From "logistics simulator" → "Logistics Business Empire MMO."**
This is an *enhancement* plan, not a rewrite. Roughly 40% of the target vision
already ships today; this document deepens what exists and sequences what's new,
in shippable phases, without breaking working features.

---

## 0. Where the project actually stands today

### Already built and live (do NOT rebuild — extend)

| Domain | Current state |
|---|---|
| **Company** | Founding, country, HQ, cash (integer cents), level/XP, reputation, logo colour, reset-to-fresh |
| **Fleet** | Vehicle models + owned vehicles, condition, tyre wear, **fuel-as-resource + refuel**, engine/tyres/rig upgrades, dealership with lock/afford + "you own N" |
| **Trailers** | Full trailer catalogue (box/reefer/tanker/flatbed/container/car-carrier), buy/attach, capacity + cargo-type gating |
| **Multi-modal** | Road tractors vs rigid vans; **rail / sea / air** craft with port/airport constraints |
| **Drivers/Crew** | Skill, morale, fatigue, loyalty, hazmat licence, salary, hiring |
| **Contracts** | Country-scoped market, haulable filter, rush/difficulty/deadline/penalty, **self-replenishing on view** |
| **Shipments** | Dispatch (truck+trailer+driver+fuel), real-time ETA, incidents (accident/breakdown), wear, weather+traffic speed factors, **lazy resolve on view** |
| **Economy** | Dynamic local prices from supply/demand/stock + events, price history, elasticity floors/ceilings |
| **Warehouses** | Build, capacity, buy/store/sell arbitrage on live prices |
| **Finance/Accounts** | Loans, **P&L + Balance Sheet + full transaction ledger** (double-entry-style), 7d/30d/all periods |
| **Missions** | Daily/weekly templates, progress tracking, claim rewards |
| **Guilds / Exchange** | Guild create/join/contribute, player trade listings with fees |
| **Research** | Tech tree with efficiency/capability/business branches, summed bonuses |
| **World** | Cities (pop, traffic, tax, fuel price, weather, port/airport/rail), world events, Leaflet **live map** with animated trucks, leaderboard |
| **Platform** | Single Laravel 13 app serving compiled Vue SPA; **PWA** (installable, bottom nav, offline shell); per-country currency (₹/$/£/د.إ) |
| **Resilience** | Self-healing schema migration on deploy; self-healing lazy world-tick (deliveries + market) because the host cron doesn't run |

### Tech stack
- **Backend:** Laravel 13 (PHP 8.4), Sanctum, Eloquent, config-driven balance (`config/transoria.php`), service layer (`EconomyService`, `ShipmentService`, `CompanyService`, `LedgerService`, `ResearchService`, `MissionService`).
- **Frontend:** Vue 3 + TypeScript, Pinia, vue-router, Tailwind v3, Leaflet, Chart.js. Money = integer cents; server-authoritative.
- **Deploy:** GitHub Actions builds a fully-vendored `hostinger-live` branch; Hostinger native Git pulls it.

---

## 1. The infrastructure decision (READ FIRST — it gates half the vision)

Several requested systems — **thousands of real-time AI companies, WebSockets,
Redis, queues, Docker, background jobs** — are **not achievable on the current
Hostinger *shared* hosting**. Today even a 1-minute cron isn't running, which is
why we self-heal world state on page-view. That pattern scales to a few hundred
players but not to a persistent real-time MMO.

There are two viable tracks. Pick one before Phase 6.

### Track A — Stay on shared hosting ("serverless-ish", request-driven world)
- World advances lazily when players act (already in place).
- AI companies simulated in **capped batches per request** (tens, not thousands).
- No WebSockets → near-real-time via polling (already used).
- **Pros:** zero new cost, no ops. **Cons:** caps AI scale + real-time depth.

### Track B — Move to a VPS (recommended for the full MMO vision)
- Hostinger **KVM VPS** (or similar). Unlocks: persistent **queue worker**,
  real **cron** (world tick every minute), **Redis** (cache/queues/locks),
  optional **Laravel Reverb** WebSockets, horizontal headroom.
- Migration is low-risk: same Laravel app + a `.env` + supervisor config.
- **This is the single highest-leverage upgrade** for AI companies, live news,
  stock market ticks, and multiplayer chat.

> **Recommendation:** Do Phases 1–5 on shared hosting now (they don't need it).
> Move to a VPS at Phase 6 (AI Companies), where real-time simulation begins.

---

## 2. Cross-cutting standards (apply in every phase)
- **SOLID / modular:** one service per domain, thin controllers, Form Requests
  for validation, API Resources for output, config-driven numbers. New systems
  register as services — never fat controllers.
- **Server-authoritative:** all money/state changes go through a service +
  `LedgerService`. The client never computes economy.
- **Migrations are additive & idempotent;** bump `schema_version` each release
  so the self-heal applies them. Never destructive without a guarded path.
- **Testing:** every phase ships PHPUnit feature tests for its service + one
  HTTP test per new endpoint (the trailer `city()` bug proved service-only
  tests aren't enough).
- **Performance:** eager-load relations, cache reference data, paginate lists,
  batch simulation writes, rate-limit self-heal loops (cache locks).
- **Security:** policies/ownership checks on every mutation, rate limiting on
  economy-moving endpoints, validate all input, never trust client amounts.

---

## 3. Phased roadmap

> Time estimates assume one focused developer. "wk" = week.

### Phase 1 — Core polish, onboarding & UI foundation  ·  ~1.5–2 wk
**Goal:** make the current game feel AAA and teachable before adding systems.
- **Features:** cinematic-lite onboarding (company name → logo colour →
  country → HQ → starter fleet → first contract), interactive tutorial overlay
  ("coach marks") explaining each screen, achievements v1, live news feed
  surfacing existing world events + fuel/price shifts.
- **DB:** `achievements`, `company_achievements`, `news_items`;
  `companies.onboarded_at`, `tutorial_step`.
- **Backend:** `OnboardingController`, `AchievementService` (event-driven
  unlocks off existing ledger/shipment hooks), `NewsService`.
- **Frontend:** `OnboardingWizard.vue`, `TutorialCoach.vue`, `NewsTicker.vue`,
  `AchievementsView.vue`; design-system pass (tokens, cards, charts).
- **UI:** unify glassmorphism, motion, typography, stat widgets, chart theme.
- **Perf:** cache achievement definitions; news paginated.
- **Security:** onboarding idempotent; achievements server-granted only.
- **Testing:** onboarding flow test; achievement-unlock unit tests.

### Phase 2 — Deep dynamic economy & richer world  ·  ~2 wk
**Goal:** the world's most reactive economy (build on the existing engine).
- **Features:** city attribute expansion (GDP, industries, crime, road quality,
  toll, border check, industrial growth); price shocks from festivals/wars/
  policy/fuel/accidents/import-export + **player & AI activity**; seasonal
  demand curves (Diwali/Monsoon/Christmas).
- **DB:** extend `cities`; `industries`, `city_industry`, `price_shocks`,
  `seasons`.
- **Backend:** enrich `EconomyService` (shock queue, seasonal modifiers), tie
  player deliveries into local supply/demand feedback (partially exists).
- **Frontend:** upgraded Markets view (candles, demand heat), city detail panel.
- **Perf:** snapshot prices in batches (exists); prune history (exists).
- **Testing:** economic invariants (floors/ceilings, shock decay).

### Phase 3 — Fleet & Garage depth  ·  ~2 wk
- **Features:** trucks gain oil/battery/insurance/registration/licence/mileage +
  more upgrade slots (turbo, GPS, suspension, brakes, lights, branding). Garage
  becomes a **service centre**: repair/paint/wash/tyre/fuel/inspection/parking,
  mechanics, upgrade levels.
- **DB:** `vehicles` columns (oil, battery, insured_until, registered_until,
  licence_until); `garages`, `garage_services`.
- **Backend:** extend `GarageService`; insurance/registration expiry affecting
  dispatch eligibility.
- **Frontend:** redesigned Fleet + Garage screens, per-vehicle service panel.
- **Testing:** expiry gating, service cost math.

### Phase 4 — Driver careers  ·  ~1.5 wk
- **Features:** age, experience, health, family, training, promotion, vacation,
  licence expiry, alcohol test, retirement; skills (night/rain/fuel-saving)
  feeding shipment outcomes (skill/fatigue already partly wired).
- **DB:** `drivers` columns + `driver_trainings`, `driver_events`.
- **Backend:** `DriverService` (training, morale/stress loop, retirement).
- **Frontend:** driver detail cards, training/promotion UI.
- **Testing:** skill progression, retirement, licence gating.

### Phase 5 — Warehouse operations  ·  ~1.5 wk
- **Features:** cold/hazard storage, workers, forklifts, automation, security/
  CCTV, electricity, loading speed, expansion tiers.
- **DB:** extend `warehouses`; `warehouse_upgrades`, `warehouse_staff`.
- **Backend:** `WarehouseService` upgrades affecting loading time & capacity.
- **Frontend:** warehouse management screen with upgrade tree.
- **Testing:** capacity/automation effects.

### Phase 6 — AI companies (⚠ needs Track B / VPS)  ·  ~2.5–3 wk
- **Features:** hundreds→thousands of AI firms that buy trucks, hire, earn,
  expand, take loans, research, buy warehouses, **bid on contracts**, merge,
  go bankrupt. They populate the leaderboard and move the economy.
- **DB:** `companies.is_ai`, `ai_profiles` (strategy, risk, aggression),
  `contract_bids`.
- **Backend:** `AiCompanyService` run by a **queued world tick** (VPS cron +
  worker); tiered simulation (active firms tick often, dormant rarely).
- **Frontend:** AI firms on map/leaderboard; contract bidding UI.
- **Perf:** batch + chunk simulation; Redis locks; cap per-tick work.
- **Testing:** AI can't create money out of nothing; bankruptcy cleanup.

### Phase 7 — Research, banking & stock market  ·  ~2 wk
- **Features:** expanded research tree (electric/hydrogen/autonomous/warehouse
  robots/route prediction — several nodes exist); banking (credit score, EMI,
  default → asset auction, investment); **stock market** (company valuation,
  IPO, shares, dividends) tradable between players + AI.
- **DB:** `loans` (EMI fields), `credit_scores`, `shares`, `share_transactions`,
  `ipos`, `dividends`.
- **Backend:** `BankService`, `StockMarketService`, valuation model off the
  existing balance sheet.
- **Frontend:** Bank + Stock Exchange screens with charts.
- **Testing:** interest/EMI/default math; share conservation; dividend payout.

### Phase 8 — Multiplayer & social depth  ·  ~2 wk
- **Features:** alliances (above guilds), guild/private chat, joint missions,
  company partnerships, auctions, friends; achievements/leaderboard tie-ins.
- **DB:** `alliances`, `chat_channels`, `messages`, `joint_missions`,
  `auctions`, `friendships`.
- **Backend:** `ChatService`, `AllianceService`; **WebSockets (Reverb)** on VPS,
  polling fallback on shared.
- **Frontend:** chat dock, alliance hub, auction house.
- **Security:** channel authorization, message rate limits, moderation hooks.
- **Testing:** channel ACLs, joint-mission payout split.

### Phase 9 — Living world: weather, traffic, events, news, AI advisor  ·  ~2 wk
- **Features:** richer weather + traffic engines (already influence shipments)
  with visible map layers; seasonal/random events (flood, oil crisis, pandemic,
  election, festival); **in-game AI advisor** ("best route / cargo / where to
  invest") answering from live economy data; dynamic news feed.
- **DB:** `weather_cells`, `traffic_incidents`, `advisor_queries` (optional).
- **Backend:** `WeatherService`, `TrafficService`, `AdvisorService`
  (rule/heuristic engine over current market + fleet data; LLM optional later).
- **Frontend:** map weather/traffic overlays, advisor chat panel, news feed.
- **Testing:** advisor recommendations are safe + deterministic on fixtures.

### Phase 10 — Endgame, cosmetics & community  ·  ~2–3 wk
- **Features:** deepen international shipping / railways / air cargo / ports /
  oil / mining / manufacturing (multi-modal foundation exists); cosmetics-only
  monetization (skins, themes, garage themes, season pass, profile frames,
  premium analytics — **never pay-to-win**); tournaments, photo/replay,
  community events; **push notifications** (PWA already installable).
- **DB:** `cosmetics`, `company_cosmetics`, `tournaments`, `push_subscriptions`.
- **Backend:** `CosmeticService`, `TournamentService`, Web Push.
- **Frontend:** cosmetics shop, tournament hub, CEO analytics dashboard v2
  (revenue/expense/route heatmap/utilisation).
- **Testing:** entitlement checks; tournament scoring.

---

## 4. Technical improvements track (parallel, infra-gated)
- **Shared-host now:** query optimisation, eager-loading audit, response caching,
  code-splitting (Vue lazy routes — partly done), rate limiting, security audit,
  broader test coverage.
- **VPS unlocks:** Redis cache/queues/locks, queued world tick + Supervisor
  worker, Laravel Reverb WebSockets, Docker for parity, monitoring
  (Telescope/Sentry), CI test gate before deploy.

---

## 5. Suggested sequencing & milestones
1. **Now → 2 wk:** Phase 1 (onboarding + UI + achievements + news). Highest
   perceived-quality jump, no infra change.
2. **+2 wk:** Phase 2 (economy/world depth).
3. **+2 wk each:** Phases 3–5 (fleet, drivers, warehouses).
4. **Infra decision → VPS**, then Phase 6 (AI companies) — the "alive" moment.
5. **Phases 7–10** build the MMO/endgame on top.

**MVP-of-the-vision milestone:** end of Phase 6 the world feels alive
(AI competitors + reactive economy + onboarding + deep fleet). Everything after
is breadth and endgame.

---

## 6. What I need from you to start Phase 1
1. **Infra track:** stay shared-hosting for now (my recommendation for P1–5), or
   provision a VPS earlier?
2. **Phase 1 priority order** — pick what to build first:
   onboarding/tutorial · achievements · live news feed · full UI redesign pass.
3. Any systems from your 32-point list you want pulled **earlier** than the phase
   above places them.

No code is written until you approve the plan and pick the Phase 1 starting point.
