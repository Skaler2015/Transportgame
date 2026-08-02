<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { api, apiError } from '../api/client'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()

const mode = ref<'login' | 'register'>('register')
const busy = ref(false)
const step = ref(1) // register wizard: 1 account · 2 company · 3 base
const form = ref({
  name: '',
  email: '',
  password: '',
  company_name: '',
  country: 'IN',
  headquarters_city_id: null as number | null,
  logo_color: '#38bdf8',
})

const countries = ref<{ code: string; name: string }[]>([])
const cities = ref<{ id: number; name: string; region?: string }[]>([])

const BRAND_COLORS = [
  '#38bdf8', '#22d3ee', '#a78bfa', '#f472b6', '#fb7185',
  '#fbbf24', '#34d399', '#60a5fa', '#f97316', '#e2e8f0',
]

const companyInitial = computed(() => (form.value.company_name || 'T').charAt(0).toUpperCase())

onMounted(async () => {
  try {
    const { data } = await api.get('/world/countries')
    countries.value = data.data
  } catch {
    countries.value = [{ code: 'IN', name: 'India' }]
  }
  loadCities()
})

async function loadCities() {
  try {
    const { data } = await api.get('/world/cities', { params: { country: form.value.country } })
    cities.value = data.data
    // Default HQ to the first city if none picked / not in this country.
    if (!cities.value.find((c) => c.id === form.value.headquarters_city_id)) {
      form.value.headquarters_city_id = cities.value[0]?.id ?? null
    }
  } catch {
    cities.value = []
  }
}
watch(() => form.value.country, loadCities)

function nextStep() {
  if (step.value === 1) {
    if (!form.value.name || !form.value.email || form.value.password.length < 8) {
      return toast.error('Fill your name, email and an 8+ character password.')
    }
  }
  if (step.value === 2 && !form.value.company_name) {
    return toast.error('Give your company a name.')
  }
  step.value = Math.min(3, step.value + 1)
}
function prevStep() {
  step.value = Math.max(1, step.value - 1)
}

async function submitRegister() {
  busy.value = true
  try {
    await auth.register(form.value)
    toast.success(`Welcome to Transoria, ${auth.company?.name}!`)
    router.push({ name: 'dashboard' })
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = false
  }
}

async function submitLogin() {
  busy.value = true
  try {
    await auth.login({ email: form.value.email, password: form.value.password })
    toast.success('Welcome back, dispatcher.')
    router.push({ name: 'dashboard' })
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = false
  }
}

const features = [
  ['◎', 'A living world', 'Prices, weather and demand shift every minute across your country — no cron, always on.'],
  ['▤', 'Real arbitrage', 'Move goods from surplus to shortage. The spread between cities is your margin.'],
  ['✦', 'Deep progression', 'Level up, unlock bigger rigs, research autonomy — from one truck to a nationwide empire.'],
  ['🛠', 'Living fleet', 'Fuel, oil, tyres, batteries, insurance and registration — every truck is a machine to maintain.'],
  ['☺', 'Real crews', 'Hire drivers with skill, fatigue, morale and licences. Rest them or they miss deadlines.'],
  ['📈', 'Markets & stocks', 'Play the commodity market, invest on the exchange and earn dividends while you haul.'],
]

// Landing-page content.
const steps = [
  ['1', 'Found your company', 'Pick a name, brand colour, country and a home city. Start with ₹250,000, a garage, a truck, a trailer and a driver.'],
  ['2', 'Win contracts & dispatch', 'Grab jobs from the live market, auto-pick the best rig and hit GO. Watch trucks roll and cash land.'],
  ['3', 'Reinvest & scale', 'Buy bigger trucks, hire crews, add warehouses, research tech and climb the leaderboard.'],
]
const systems = [
  ['▤', 'Contract Market', 'A live board of jobs with ₹/min efficiency, backhaul detection and one-tap dispatch.'],
  ['⟳', 'Operations', 'Track every shipment in real time — progress, ETA, weather and event log.'],
  ['▦', 'Fleet & Dealership', 'Buy road, rail, sea and air vehicles. Upgrade engines, tyres and trailers.'],
  ['▢', 'Warehouses', 'Store cheap, sell dear. Upgrade capacity, staff and security for arbitrage at scale.'],
  ['📈', 'Stock Exchange', 'Invest in listed logistics firms, ride the market and collect dividends.'],
  ['⚑', 'Guilds & Trade', 'Team up, trade on the player exchange and take on the world together.'],
  ['🏆', 'Missions & Achievements', 'Daily rewards, long-term goals and badges that pay out cash and XP.'],
  ['◎', 'Live Map & News', 'A national map of your fleet plus a rolling news wire of world events.'],
]
const stats = [
  ['30+', 'cities'],
  ['20+', 'commodities'],
  ['4', 'transport modes'],
  ['∞', 'ambition'],
]

const nav = [
  ['Features', 'features'],
  ['How it works', 'how'],
  ['Systems', 'systems'],
]

function scrollTo(id: string) {
  document.getElementById(id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}
function goAuth(m: 'login' | 'register') {
  mode.value = m
  if (m === 'register') step.value = 1
  document.getElementById('auth')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
}
</script>

<template>
  <div class="min-h-screen">
    <!-- Sticky nav -->
    <header class="sticky top-0 z-40 border-b border-white/10 bg-ink-950/70 backdrop-blur-xl">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 h-16 flex items-center gap-4">
        <button class="flex items-center gap-3" @click="scrollTo('top')">
          <div class="h-9 w-9 rounded-xl bg-gradient-to-br from-brand to-brand-glow shadow-glow flex items-center justify-center font-black text-ink-950">T</div>
          <div class="text-left leading-none">
            <p class="font-extrabold tracking-tight">Transoria</p>
            <p class="text-[10px] uppercase tracking-[0.3em] text-brand-soft">Online</p>
          </div>
        </button>
        <nav class="hidden md:flex items-center gap-6 ml-6 text-sm text-slate-400">
          <button v-for="n in nav" :key="n[1]" class="hover:text-slate-100 transition" @click="scrollTo(n[1])">{{ n[0] }}</button>
        </nav>
        <div class="ml-auto flex items-center gap-2">
          <button class="btn-ghost !py-1.5 hidden sm:inline-flex" @click="goAuth('login')">Sign In</button>
          <button class="btn-primary !py-1.5" @click="goAuth('register')">Play Free →</button>
        </div>
      </div>
    </header>

    <div id="top" />

    <!-- Hero: pitch + live auth card -->
    <section class="relative overflow-hidden">
      <div class="absolute inset-0 bg-gradient-to-br from-brand/10 via-transparent to-brand-glow/10 pointer-events-none" />
      <div class="relative max-w-6xl mx-auto px-4 sm:px-6 py-12 lg:py-20 grid lg:grid-cols-2 gap-10 lg:items-center">
        <!-- Pitch -->
        <div class="max-w-xl">
          <span class="chip bg-brand/15 text-brand-soft">🚚 A persistent logistics MMO</span>
          <h1 class="mt-4 text-4xl sm:text-5xl font-extrabold leading-[1.1]">
            Build the logistics empire that <span class="text-brand">never sleeps.</span>
          </h1>
          <p class="mt-4 text-slate-400 text-lg">
            Start with one truck and a garage. Route cargo across a living economy, hire crews, play the
            markets and out-manoeuvre rivals — all in your browser, no download.
          </p>
          <div class="mt-6 flex flex-wrap gap-3">
            <button class="btn-primary !px-6 !py-3 text-base" @click="goAuth('register')">Found your company →</button>
            <button class="btn-ghost !px-6 !py-3 text-base" @click="scrollTo('how')">See how it works</button>
          </div>
          <div class="mt-8 grid grid-cols-4 gap-3 max-w-md">
            <div v-for="s in stats" :key="s[1]" class="text-center">
              <p class="text-2xl font-extrabold text-brand">{{ s[0] }}</p>
              <p class="text-[11px] uppercase tracking-wider text-slate-500">{{ s[1] }}</p>
            </div>
          </div>
        </div>

        <!-- Auth card -->
        <div id="auth" class="flex justify-center lg:justify-end scroll-mt-20">
      <div class="glass w-full max-w-md p-8 animate-slide-up">
        <div class="flex gap-2 p-1 rounded-xl bg-ink-900/70 mb-6">
          <button
            class="flex-1 py-2 rounded-lg text-sm font-semibold transition"
            :class="mode === 'register' ? 'bg-brand text-ink-950' : 'text-slate-400'"
            @click="mode = 'register'; step = 1"
          >Found a Company</button>
          <button
            class="flex-1 py-2 rounded-lg text-sm font-semibold transition"
            :class="mode === 'login' ? 'bg-brand text-ink-950' : 'text-slate-400'"
            @click="mode = 'login'"
          >Sign In</button>
        </div>

        <!-- REGISTER WIZARD -->
        <template v-if="mode === 'register'">
          <!-- Step indicator -->
          <div class="flex items-center gap-2 mb-6">
            <template v-for="s in 3" :key="s">
              <div
                class="h-1.5 flex-1 rounded-full transition-colors"
                :class="s <= step ? 'bg-brand' : 'bg-ink-700'"
              />
            </template>
          </div>

          <!-- Step 1: account -->
          <div v-if="step === 1" class="space-y-4">
            <p class="text-sm text-slate-400">Step 1 · Your account</p>
            <div>
              <label class="stat-label">Your Name</label>
              <input v-model="form.name" class="input mt-1" placeholder="Alex Dispatcher" />
            </div>
            <div>
              <label class="stat-label">Email</label>
              <input v-model="form.email" type="email" class="input mt-1" placeholder="you@transoria.io" />
            </div>
            <div>
              <label class="stat-label">Password</label>
              <input v-model="form.password" type="password" class="input mt-1" placeholder="••••••••" />
            </div>
            <button class="btn-primary w-full !py-2.5" @click="nextStep">Continue →</button>
          </div>

          <!-- Step 2: company + brand -->
          <div v-else-if="step === 2" class="space-y-4">
            <p class="text-sm text-slate-400">Step 2 · Your company</p>
            <div class="flex items-center gap-3">
              <div
                class="h-14 w-14 rounded-2xl shrink-0 grid place-items-center font-black text-ink-950 text-2xl shadow-lg"
                :style="{ background: form.logo_color }"
              >{{ companyInitial }}</div>
              <div class="flex-1">
                <label class="stat-label">Company Name</label>
                <input v-model="form.company_name" class="input mt-1" placeholder="Skyline Freightways" />
              </div>
            </div>
            <div>
              <label class="stat-label">Brand colour</label>
              <div class="flex flex-wrap gap-2 mt-2">
                <button
                  v-for="c in BRAND_COLORS"
                  :key="c"
                  type="button"
                  class="h-8 w-8 rounded-lg transition ring-2"
                  :style="{ background: c }"
                  :class="form.logo_color === c ? 'ring-white scale-110' : 'ring-transparent'"
                  @click="form.logo_color = c"
                />
              </div>
            </div>
            <div class="flex gap-2">
              <button class="btn-ghost" @click="prevStep">← Back</button>
              <button class="btn-primary flex-1 !py-2.5" @click="nextStep">Continue →</button>
            </div>
          </div>

          <!-- Step 3: base of operations -->
          <div v-else class="space-y-4">
            <p class="text-sm text-slate-400">Step 3 · Base of operations</p>
            <div>
              <label class="stat-label">Country</label>
              <select v-model="form.country" class="input mt-1">
                <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
              </select>
            </div>
            <div>
              <label class="stat-label">Headquarters city</label>
              <select v-model="form.headquarters_city_id" class="input mt-1">
                <option v-for="c in cities" :key="c.id" :value="c.id">
                  {{ c.name }}<template v-if="c.region"> · {{ c.region }}</template>
                </option>
              </select>
              <p class="text-[11px] text-slate-500 mt-1">Your first garage and truck start here.</p>
            </div>
            <div class="rounded-xl bg-ink-900/60 p-3 text-[11px] text-slate-400">
              You'll start with <span class="text-gold">₹250,000</span>, a garage, a mini-hauler,
              a starter trailer and a driver.
            </div>
            <div class="flex gap-2">
              <button class="btn-ghost" @click="prevStep">← Back</button>
              <button class="btn-primary flex-1 !py-2.5" :disabled="busy" @click="submitRegister">
                {{ busy ? 'Launching…' : 'Launch Company →' }}
              </button>
            </div>
          </div>
        </template>

        <!-- LOGIN -->
        <form v-else class="space-y-4" @submit.prevent="submitLogin">
          <div>
            <label class="stat-label">Email</label>
            <input v-model="form.email" type="email" class="input mt-1" placeholder="you@transoria.io" required />
          </div>
          <div>
            <label class="stat-label">Password</label>
            <input v-model="form.password" type="password" class="input mt-1" placeholder="••••••••" required />
          </div>
          <button class="btn-primary w-full !py-2.5" :disabled="busy">
            {{ busy ? 'Please wait…' : 'Sign In →' }}
          </button>
        </form>

        <p class="text-[11px] text-slate-500 text-center mt-5">
          No pay-to-win · Free to play · A persistent world
        </p>
      </div>
        </div>
      </div>
    </section>

    <!-- Features -->
    <section id="features" class="max-w-6xl mx-auto px-4 sm:px-6 py-14 scroll-mt-16">
      <div class="text-center max-w-2xl mx-auto">
        <p class="stat-label text-brand-soft">Why Transoria</p>
        <h2 class="text-3xl font-extrabold mt-2">A simulation with real depth</h2>
        <p class="text-slate-400 mt-3">Every system feeds the next. Master them and the empire compounds.</p>
      </div>
      <div class="grid sm:grid-cols-2 lg:grid-cols-3 gap-4 mt-10">
        <div v-for="f in features" :key="f[1]" class="glass p-5 glass-hover">
          <div class="h-11 w-11 rounded-xl bg-brand/10 flex items-center justify-center text-brand text-xl">{{ f[0] }}</div>
          <p class="font-semibold mt-3">{{ f[1] }}</p>
          <p class="text-sm text-slate-400 mt-1">{{ f[2] }}</p>
        </div>
      </div>
    </section>

    <!-- How it works -->
    <section id="how" class="border-y border-white/10 bg-ink-900/30 scroll-mt-16">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 py-14">
        <div class="text-center max-w-2xl mx-auto">
          <p class="stat-label text-brand-soft">Get rolling in minutes</p>
          <h2 class="text-3xl font-extrabold mt-2">How it works</h2>
        </div>
        <div class="grid md:grid-cols-3 gap-5 mt-10">
          <div v-for="s in steps" :key="s[0]" class="glass p-6 relative">
            <div class="h-10 w-10 rounded-full bg-brand text-ink-950 font-black grid place-items-center">{{ s[0] }}</div>
            <p class="font-semibold text-lg mt-4">{{ s[1] }}</p>
            <p class="text-sm text-slate-400 mt-2">{{ s[2] }}</p>
          </div>
        </div>
        <div class="text-center mt-10">
          <button class="btn-primary !px-6 !py-3" @click="goAuth('register')">Start free now →</button>
        </div>
      </div>
    </section>

    <!-- Game systems -->
    <section id="systems" class="max-w-6xl mx-auto px-4 sm:px-6 py-14 scroll-mt-16">
      <div class="text-center max-w-2xl mx-auto">
        <p class="stat-label text-brand-soft">One game, many worlds</p>
        <h2 class="text-3xl font-extrabold mt-2">Everything you can run</h2>
        <p class="text-slate-400 mt-3">From a single garage to warehouses, markets, guilds and the stock exchange.</p>
      </div>
      <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-4 mt-10">
        <div v-for="s in systems" :key="s[1]" class="glass p-5 glass-hover">
          <div class="text-2xl">{{ s[0] }}</div>
          <p class="font-semibold mt-2">{{ s[1] }}</p>
          <p class="text-[13px] text-slate-400 mt-1 leading-snug">{{ s[2] }}</p>
        </div>
      </div>
    </section>

    <!-- Final CTA -->
    <section class="max-w-6xl mx-auto px-4 sm:px-6 pb-16">
      <div class="glass p-10 text-center relative overflow-hidden">
        <div class="absolute inset-0 bg-gradient-to-br from-brand/15 via-transparent to-brand-glow/15 pointer-events-none" />
        <h2 class="relative text-3xl sm:text-4xl font-extrabold">Your first truck is waiting.</h2>
        <p class="relative text-slate-400 mt-3 max-w-xl mx-auto">
          Found your company in under a minute and start hauling in a world that keeps moving even when you log off.
        </p>
        <div class="relative mt-6 flex flex-wrap gap-3 justify-center">
          <button class="btn-primary !px-7 !py-3 text-base" @click="goAuth('register')">Found your company →</button>
          <button class="btn-ghost !px-7 !py-3 text-base" @click="goAuth('login')">I already play</button>
        </div>
      </div>
    </section>

    <!-- Footer -->
    <footer class="border-t border-white/10">
      <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-sm text-slate-500">
        <div class="flex items-center gap-2">
          <div class="h-7 w-7 rounded-lg bg-gradient-to-br from-brand to-brand-glow flex items-center justify-center font-black text-ink-950 text-xs">T</div>
          <span>Transoria Online — a persistent logistics MMO.</span>
        </div>
        <div class="flex items-center gap-5">
          <button class="hover:text-slate-200 transition" @click="scrollTo('features')">Features</button>
          <button class="hover:text-slate-200 transition" @click="scrollTo('systems')">Systems</button>
          <button class="hover:text-slate-200 transition" @click="goAuth('login')">Sign In</button>
        </div>
      </div>
    </footer>
  </div>
</template>
