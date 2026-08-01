<script setup lang="ts">
import { onMounted, onUnmounted, computed, ref, watch } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useGameStore } from '../stores/game'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'
import { apiError } from '../api/client'

const auth = useAuthStore()
const game = useGameStore()
const toast = useToastStore()
const router = useRouter()
const route = useRoute()

// Mobile navigation drawer (the sidebar is hidden below the lg breakpoint).
const mobileOpen = ref(false)
watch(() => route.fullPath, () => (mobileOpen.value = false))

const nav = [
  { to: '/dashboard', label: 'Dashboard', icon: '◧' },
  { to: '/contracts', label: 'Contract Market', icon: '▤' },
  { to: '/operations', label: 'Operations', icon: '⟳' },
  { to: '/fleet', label: 'Fleet & Dealership', icon: '▦' },
  { to: '/drivers', label: 'Crew', icon: '☺' },
  { to: '/warehouses', label: 'Warehouses', icon: '▢' },
  { to: '/exchange', label: 'Exchange', icon: '⇄' },
  { to: '/missions', label: 'Missions', icon: '✓' },
  { to: '/finance', label: 'Finance', icon: '$' },
  { to: '/accounts', label: 'Accounts', icon: '▤' },
  { to: '/guilds', label: 'Guilds', icon: '⚑' },
  { to: '/map', label: 'Live Map', icon: '◎' },
  { to: '/market', label: 'Markets', icon: '≣' },
  { to: '/research', label: 'R&D Tree', icon: '✦' },
  { to: '/leaderboard', label: 'Leaderboard', icon: '♛' },
  { to: '/settings', label: 'Settings', icon: '⚙' },
]

// Bottom tab bar for mobile — the core gameplay loop, one tap away. The
// 5th slot opens the full drawer with every section.
const bottomNav = [
  { to: '/dashboard', label: 'Home', icon: '◧' },
  { to: '/contracts', label: 'Jobs', icon: '▤' },
  { to: '/operations', label: 'Dispatch', icon: '⟳' },
  { to: '/fleet', label: 'Fleet', icon: '▦' },
]

const company = computed(() => game.dashboard?.company ?? auth.company)
const xpPct = computed(() => {
  const c = company.value
  if (!c || !c.xp_to_next) return 0
  return Math.min(100, Math.round((c.xp / c.xp_to_next) * 100))
})

let poll: number | undefined

onMounted(async () => {
  await game.loadReference().catch(() => {})
  await game.refreshDashboard().catch((e) => toast.error(apiError(e)))
  poll = window.setInterval(() => game.refreshDashboard().catch(() => {}), 12000)
})
onUnmounted(() => clearInterval(poll))

async function advance() {
  try {
    await game.advanceTick()
    toast.info('World advanced. Shipments and prices updated.')
  } catch (e) {
    toast.error(apiError(e))
  }
}

async function logout() {
  await auth.logout()
  router.push({ name: 'login' })
}
</script>

<template>
  <div class="min-h-screen flex">
    <!-- Sidebar -->
    <aside class="hidden lg:flex flex-col w-64 shrink-0 border-r border-white/10 bg-ink-900/70 backdrop-blur-xl">
      <div class="px-5 py-5 flex items-center gap-3">
        <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-brand to-brand-glow shadow-glow flex items-center justify-center font-black text-ink-950">T</div>
        <div>
          <p class="font-extrabold tracking-tight leading-none">Transoria</p>
          <p class="text-[10px] uppercase tracking-[0.2em] text-brand-soft">Online</p>
        </div>
      </div>

      <nav class="flex-1 px-3 space-y-1 mt-2">
        <RouterLink
          v-for="item in nav"
          :key="item.to"
          :to="item.to"
          class="flex items-center gap-3 px-3 py-2.5 rounded-xl text-sm font-medium text-slate-400 hover:text-slate-100 hover:bg-white/5 transition"
          active-class="!text-brand bg-brand/10 !font-semibold"
        >
          <span class="text-base w-5 text-center opacity-80">{{ item.icon }}</span>
          {{ item.label }}
        </RouterLink>
      </nav>

      <div class="p-4">
        <button class="btn-ghost w-full text-xs" @click="advance">⏱ Advance World</button>
        <p class="text-[10px] text-slate-500 mt-2 text-center leading-tight">
          Dev control — the live world advances automatically every minute.
        </p>
      </div>
    </aside>

    <!-- Main column -->
    <div class="flex-1 flex flex-col min-w-0">
      <!-- Topbar -->
      <header
        class="sticky top-0 z-30 border-b border-white/10 bg-ink-950/70 backdrop-blur-xl"
        style="padding-top: env(safe-area-inset-top)"
      >
        <div
          class="px-4 sm:px-6 h-16 flex items-center gap-4"
          style="padding-left: max(1rem, env(safe-area-inset-left)); padding-right: max(1rem, env(safe-area-inset-right))"
        >
          <!-- Mobile menu button (hidden on lg where the sidebar shows) -->
          <button
            class="lg:hidden btn-ghost !px-3 !py-2 text-lg leading-none"
            aria-label="Menu"
            @click="mobileOpen = true"
          >☰</button>

          <div class="flex items-center gap-3 min-w-0">
            <div
              class="h-8 w-8 rounded-lg shrink-0 flex items-center justify-center font-bold text-ink-950 text-sm"
              :style="{ background: company?.logo_color || '#38bdf8' }"
            >
              {{ (company?.name || 'T').charAt(0) }}
            </div>
            <div class="min-w-0">
              <p class="font-semibold leading-none truncate">{{ company?.name }}</p>
              <p class="text-[11px] text-slate-400 truncate">{{ company?.headquarters?.name }} HQ · {{ company?.country_name || company?.country }}</p>
            </div>
          </div>

          <div class="ml-auto flex items-center gap-2 sm:gap-4">
            <div class="hidden sm:block text-right">
              <p class="stat-label">Cash</p>
              <p class="font-mono font-semibold text-gold leading-none">{{ credits(company?.cash ?? 0) }}</p>
            </div>
            <div class="hidden md:block text-right">
              <p class="stat-label">Company Value</p>
              <p class="font-mono font-semibold leading-none">{{ credits(company?.value ?? 0, { compact: true }) }}</p>
            </div>
            <div class="text-right">
              <p class="stat-label">Reputation</p>
              <p class="font-mono font-semibold text-brand-soft leading-none">{{ num(company?.reputation ?? 0) }}</p>
            </div>

            <!-- Level + XP -->
            <div class="hidden sm:flex items-center gap-2 pl-2">
              <div class="h-9 w-9 rounded-full border-2 border-brand/60 flex items-center justify-center font-bold text-sm">
                {{ company?.level ?? 1 }}
              </div>
              <div class="w-24">
                <div class="h-1.5 rounded-full bg-ink-700 overflow-hidden">
                  <div class="h-full bg-gradient-to-r from-brand to-brand-glow" :style="{ width: xpPct + '%' }" />
                </div>
                <p class="text-[10px] text-slate-500 mt-1">Lv {{ company?.level }} · {{ xpPct }}%</p>
              </div>
            </div>

            <button class="btn-ghost !px-3" title="Log out" @click="logout">⏻</button>
          </div>
        </div>
      </header>

      <main
        class="flex-1 p-4 sm:p-6 max-w-[1500px] w-full mx-auto"
        style="padding-bottom: calc(1.5rem + env(safe-area-inset-bottom)); padding-left: max(1rem, env(safe-area-inset-left)); padding-right: max(1rem, env(safe-area-inset-right))"
      >
        <RouterView />
        <!-- Reserve space so content scrolls clear of the bottom tab bar. -->
        <div class="lg:hidden" style="height: calc(4.75rem + env(safe-area-inset-bottom))" />
      </main>
    </div>

    <!-- Mobile bottom tab bar (hidden on lg where the sidebar shows).
         Teleported to <body> so `position: fixed` is relative to the viewport
         and never trapped by a transformed/filtered ancestor (which would make
         it scroll away with the page). -->
    <Teleport to="body">
      <nav
        class="lg:hidden fixed bottom-0 inset-x-0 z-40 border-t border-white/10 bg-ink-950/95 backdrop-blur-xl"
        style="padding-bottom: env(safe-area-inset-bottom)"
      >
        <div class="grid grid-cols-5">
          <RouterLink
            v-for="item in bottomNav"
            :key="item.to"
            :to="item.to"
            class="flex flex-col items-center justify-center gap-0.5 py-2.5 text-[10px] font-medium text-slate-400 transition"
            active-class="!text-brand"
          >
            <span class="text-xl leading-none">{{ item.icon }}</span>
            {{ item.label }}
          </RouterLink>
          <button
            class="flex flex-col items-center justify-center gap-0.5 py-2.5 text-[10px] font-medium text-slate-400 transition active:text-brand"
            @click="mobileOpen = true"
          >
            <span class="text-xl leading-none">☰</span>
            More
          </button>
        </div>
      </nav>
    </Teleport>

    <!-- Mobile navigation drawer (teleported for the same reason) -->
    <Teleport to="body">
    <Transition name="fade">
      <div v-if="mobileOpen" class="fixed inset-0 z-50 lg:hidden" @click="mobileOpen = false">
        <div class="absolute inset-0 bg-ink-950/70 backdrop-blur-sm" />
        <aside
          class="absolute left-0 top-0 bottom-0 w-72 max-w-[80%] bg-ink-900 border-r border-white/10 flex flex-col animate-slide-up"
          style="padding-top: env(safe-area-inset-top); padding-bottom: env(safe-area-inset-bottom)"
          @click.stop
        >
          <div class="px-5 py-5 flex items-center gap-3 border-b border-white/10">
            <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-brand to-brand-glow shadow-glow flex items-center justify-center font-black text-ink-950">T</div>
            <div class="flex-1">
              <p class="font-extrabold tracking-tight leading-none">Transoria</p>
              <p class="text-[10px] uppercase tracking-[0.2em] text-brand-soft">Online</p>
            </div>
            <button class="btn-ghost !px-3" aria-label="Close" @click="mobileOpen = false">✕</button>
          </div>

          <nav class="flex-1 px-3 py-3 space-y-1 overflow-y-auto">
            <RouterLink
              v-for="item in nav"
              :key="item.to"
              :to="item.to"
              class="flex items-center gap-3 px-3 py-3 rounded-xl text-sm font-medium text-slate-300 hover:text-slate-100 hover:bg-white/5 transition"
              active-class="!text-brand bg-brand/10 !font-semibold"
            >
              <span class="text-base w-5 text-center opacity-80">{{ item.icon }}</span>
              {{ item.label }}
            </RouterLink>
          </nav>

          <div class="p-4 border-t border-white/10">
            <button class="btn-ghost w-full text-xs" @click="advance">⏱ Advance World</button>
          </div>
        </aside>
      </div>
    </Transition>
    </Teleport>
  </div>
</template>

<style scoped>
.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}
.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
