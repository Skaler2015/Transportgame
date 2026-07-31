# Transoria Online — Game Design Document

> Working title: **Transoria Online**. Genre: MMO business simulation /
> logistics management / economic strategy. Platform: browser + PWA (Android/iOS
> installable). This document describes the design; the repository implements a
> playable vertical slice of it.

---

## 1. Fantasy & pillars

**You are the founder of a logistics company in the Transoria belt — a fictional
continent of trading cities.** From a single garage and one truck, you build a
freight network that outgrows regions, powertrains and eventually the road itself.

Three design pillars keep every decision honest:

1. **A world that never stops.** Prices, weather, demand and rival activity move
   on a server clock whether or not you're logged in. Logging in is about making
   *decisions*, not grinding clicks.
2. **Legible depth.** Every number a player sees traces to a cause — a shortage,
   a storm, a tired driver. Systems are deep but their *reasons* are surfaced.
3. **Fair progression.** Monetisation is cosmetic only. Advantage comes from
   routing smarter and reading the market, never from a wallet.

## 2. The economic core (the real game)

Transoria is, underneath the trucks, a **spatial arbitrage engine**.

- Each **city** produces some commodities (surplus → cheap) and consumes others
  (demand → dear). Stock moves every tick: `stock += production − consumption`.
- **Local price** is derived from a shortage signal:
  `demand_index = (consumption + 1) / (production + 0.1·stock + 1)`, squashed to a
  band, then `price = base · (1 + elasticity·(demand_index − 1))`, clamped to a
  floor/ceiling and modulated by any active world events.
- The **gap** between a producer's price and a consumer's price — minus fuel,
  wages, tax and wear — is the player's profit. Contracts package that gap into
  discrete, deadline-bound jobs.

Because player deliveries *physically move stock* (origin drains, destination
fills), a busy lane self-corrects: over-served routes lose their edge, pushing
players to discover new ones. This is what makes the economy feel alive rather
than scripted.

## 3. Systems

### Cities & the world graph
Nodes with position (lat/lng), population, economic growth, traffic, tax, fuel
price, weather and infrastructure flags (port/airport/rail). Distance uses the
haversine formula times a road-winding factor.

### Commodities
Weight, volume, value, risk, volatility, and handling requirements (refrigerated,
tanker, hazardous, perishable). Handling gates which vehicles — and which driver
licences — a job needs.

### Vehicles
Purchasable **models** (blueprints in the dealership) vs. owned **vehicles**
(instances with condition, tire wear, fuel, odometer and a status machine:
`idle → assigned → en_route → maintenance`). Class, capacity, speed, fuel
economy, reliability, powertrain and capability flags differentiate them.

### Drivers
Skill, morale, fatigue, loyalty and an optional hazmat licence. Skill and rest
raise effective speed and safety; fatigue raises accident risk and forces rest
between trips. Drivers gain skill and morale from successful runs.

### Shipments (the resolution engine)
On dispatch: validate compatibility & capacity, compute effective speed
(model × driver × weather × traffic × research), fuel budget and ETA; charge fuel
up front. On arrival (via the tick): roll incidents (breakdown → repair cost +
delay; accident → cargo lost + fail), compare against the deadline, then settle —
apply tax, pay the ledger, adjust reputation and XP, wear the vehicle, fatigue
the driver, and move the physical stock.

### Economy tick
`php artisan world:tick` (scheduled every minute) advances events & weather,
moves markets and records price history, settles arrived shipments, rests
drivers, expires stale contract offers and replenishes the market.

### Reputation & progression
Reputation (0–1000) rises with on-time delivery and falls with failure.
XP follows an exponential curve (`1000 · level^1.6`); each level grants research
points. Research nodes give permanent, stacking operational bonuses.

### Finance
A double-sided **ledger** journals every credit/debit with a running balance and
category (revenue, fuel, wages, purchase, upkeep, penalty, loan, interest, R&D),
powering the dashboard P&L and server-side balance reconciliation.

## 4. Economy balancing knobs

All live in `backend/config/transoria.php`:

| Group | Examples |
|---|---|
| `economy` | price elasticity, floor/ceiling %, demand smoothing, fuel drift |
| `contracts` | target open jobs per hub, base/rush margin, penalty %, deadline slack, TTL |
| `shipment` | late payout %, accident/breakdown base chances, wear per 1000 km, XP per run |
| `tick` | tick minutes, game-hours per tick, **seconds per game hour** (time compression), history depth |
| `research` | full node tree: cost, prerequisites, bonus map |
| `events` | catalog with spawn weights, durations and modifier sets |

## 5. UX & visual language

Premium, modern, **dark** dashboard aesthetic with **glassmorphism** surfaces, a
sky/teal signal colour on deep-slate, gold for money, emerald/rose for gain/loss.
Live progress bars and an animated Leaflet map convey motion. The information
architecture is a left rail of domains (Dashboard, Contract Market, Operations,
Fleet & Dealership, Crew, Live Map, Markets, R&D, Leaderboard) with a persistent
top bar of company vitals (cash, value, reputation, level/XP).

## 6. Multiplayer & persistence

The world and its economy are **shared and server-owned**; the contract market is
a common pool players compete over, and the leaderboard ranks companies by
reputation and value. The current slice uses short-interval **polling** for live
updates; the roadmap upgrades hot paths (shipment progress, market, news) to
**WebSocket** push, and adds guilds/corporations, contract auctions and
player-to-player trade on top of the existing schema.

## 7. Monetisation (no pay-to-win)

Cosmetics only: truck liveries, garage themes, company logos, a season pass and
VIP quality-of-life (extra saved filters, longer history) — never capacity,
speed, payout or economy advantages.

## 8. Anti-cheat posture

The client is a renderer. All economy math, validation and money movement are
server-side and journaled; dispatch validates ownership, availability, capacity
and licences; the dev tick endpoint is disabled outside local environments.
Roadmap: rate limiting, action audit logs, and balance reconciliation jobs.

## 9. Roadmap sequence

1. **WebSockets** for live shipment/market/news push (replace polling).
2. **Warehouses & inventory** — buffer stock for arbitrage (schema already present).
3. **Guilds/corporations**, shared contracts, alliance events.
4. **Player market** — cargo auctions and direct trade contracts.
5. **Multimodal** rail/air/sea legs and ports/airports.
6. **Seasons, missions & world crises**; **cosmetics store**; **admin panel**; **2FA**.
