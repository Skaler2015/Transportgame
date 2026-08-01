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
  ['◎', 'A living world', 'Prices, weather and demand shift every minute across your country.'],
  ['▤', 'Real arbitrage', 'Move goods from surplus to shortage. The spread is your margin.'],
  ['✦', 'Deep progression', 'Level up, research autonomy, and grow from one truck to an empire.'],
]
</script>

<template>
  <div class="min-h-screen grid lg:grid-cols-2">
    <!-- Hero -->
    <div class="relative hidden lg:flex flex-col justify-between p-12 overflow-hidden">
      <div class="absolute inset-0 bg-gradient-to-br from-brand/10 via-transparent to-brand-glow/10" />
      <div class="relative flex items-center gap-3">
        <div class="h-11 w-11 rounded-2xl bg-gradient-to-br from-brand to-brand-glow shadow-glow flex items-center justify-center font-black text-ink-950 text-xl">T</div>
        <div>
          <p class="font-extrabold text-2xl tracking-tight leading-none">Transoria</p>
          <p class="text-xs uppercase tracking-[0.35em] text-brand-soft">Online</p>
        </div>
      </div>

      <div class="relative max-w-md">
        <h1 class="text-4xl font-extrabold leading-tight">
          Build the logistics empire that <span class="text-brand">never sleeps.</span>
        </h1>
        <p class="mt-4 text-slate-400">
          Start with one truck and a garage. Route cargo, hire crews, research technology and
          out-manoeuvre thousands of rivals in a persistent transport economy.
        </p>

        <div class="mt-8 space-y-4">
          <div v-for="f in features" :key="f[1]" class="flex gap-4">
            <div class="h-10 w-10 rounded-xl glass flex items-center justify-center text-brand text-lg shrink-0">{{ f[0] }}</div>
            <div>
              <p class="font-semibold">{{ f[1] }}</p>
              <p class="text-sm text-slate-400">{{ f[2] }}</p>
            </div>
          </div>
        </div>
      </div>

      <p class="relative text-xs text-slate-600">A persistent MMO business simulation. No pay-to-win.</p>
    </div>

    <!-- Form -->
    <div class="flex items-center justify-center p-6">
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
      </div>
    </div>
  </div>
</template>
