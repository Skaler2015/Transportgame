import { createRouter, createWebHistory } from 'vue-router'
import { getToken } from '../api/client'

const routes = [
  { path: '/login', name: 'login', component: () => import('../views/AuthView.vue'), meta: { guest: true } },
  {
    path: '/',
    component: () => import('../layouts/AppShell.vue'),
    meta: { auth: true },
    children: [
      { path: '', redirect: '/dashboard' },
      { path: 'dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue') },
      { path: 'contracts', name: 'contracts', component: () => import('../views/ContractsView.vue') },
      { path: 'operations', name: 'operations', component: () => import('../views/OperationsView.vue') },
      { path: 'fleet', name: 'fleet', component: () => import('../views/FleetView.vue') },
      { path: 'drivers', name: 'drivers', component: () => import('../views/DriversView.vue') },
      { path: 'warehouses', name: 'warehouses', component: () => import('../views/WarehousesView.vue') },
      { path: 'exchange', name: 'exchange', component: () => import('../views/ExchangeView.vue') },
      { path: 'missions', name: 'missions', component: () => import('../views/MissionsView.vue') },
      { path: 'finance', name: 'finance', component: () => import('../views/FinanceView.vue') },
      { path: 'stocks', name: 'stocks', component: () => import('../views/StockView.vue') },
      { path: 'accounts', name: 'accounts', component: () => import('../views/AccountsView.vue') },
      { path: 'guilds', name: 'guilds', component: () => import('../views/GuildsView.vue') },
      { path: 'map', name: 'map', component: () => import('../views/MapView.vue') },
      { path: 'market', name: 'market', component: () => import('../views/MarketView.vue') },
      { path: 'research', name: 'research', component: () => import('../views/ResearchView.vue') },
      { path: 'achievements', name: 'achievements', component: () => import('../views/AchievementsView.vue') },
      { path: 'news', name: 'news', component: () => import('../views/NewsView.vue') },
      { path: 'advisor', name: 'advisor', component: () => import('../views/AdvisorView.vue') },
      { path: 'leaderboard', name: 'leaderboard', component: () => import('../views/LeaderboardView.vue') },
      { path: 'settings', name: 'settings', component: () => import('../views/SettingsView.vue') },
    ],
  },
]

export const router = createRouter({
  history: createWebHistory(),
  routes,
})

router.beforeEach((to) => {
  const authed = !!getToken()
  if (to.meta.auth && !authed) return { name: 'login' }
  if (to.meta.guest && authed) return { name: 'dashboard' }
  return true
})
