<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { useToastStore } from '../stores/toast'
import { api, apiError } from '../api/client'

const auth = useAuthStore()
const toast = useToastStore()
const router = useRouter()

const mode = ref<'login' | 'register'>('register')
const busy = ref(false)
const form = ref({ name: '', email: '', password: '', company_name: '', country: 'IN' })
const countries = ref<{ code: string; name: string }[]>([])

onMounted(async () => {
  try {
    const { data } = await api.get('/world/countries')
    countries.value = data.data
  } catch {
    countries.value = [{ code: 'IN', name: 'India' }]
  }
})

async function submit() {
  busy.value = true
  try {
    if (mode.value === 'register') {
      await auth.register(form.value)
      toast.success(`Welcome to Transoria, ${auth.company?.name}!`)
    } else {
      await auth.login({ email: form.value.email, password: form.value.password })
      toast.success('Welcome back, dispatcher.')
    }
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
            @click="mode = 'register'"
          >Found a Company</button>
          <button
            class="flex-1 py-2 rounded-lg text-sm font-semibold transition"
            :class="mode === 'login' ? 'bg-brand text-ink-950' : 'text-slate-400'"
            @click="mode = 'login'"
          >Sign In</button>
        </div>

        <form class="space-y-4" @submit.prevent="submit">
          <template v-if="mode === 'register'">
            <div>
              <label class="stat-label">Your Name</label>
              <input v-model="form.name" class="input mt-1" placeholder="Alex Dispatcher" required />
            </div>
            <div>
              <label class="stat-label">Company Name</label>
              <input v-model="form.company_name" class="input mt-1" placeholder="Skyline Freightways" required />
            </div>
            <div>
              <label class="stat-label">Country</label>
              <select v-model="form.country" class="input mt-1">
                <option v-for="c in countries" :key="c.code" :value="c.code">{{ c.name }}</option>
              </select>
              <p class="text-[11px] text-slate-500 mt-1">You'll operate in this country's cities.</p>
            </div>
          </template>

          <div>
            <label class="stat-label">Email</label>
            <input v-model="form.email" type="email" class="input mt-1" placeholder="you@transoria.io" required />
          </div>
          <div>
            <label class="stat-label">Password</label>
            <input v-model="form.password" type="password" class="input mt-1" placeholder="••••••••" minlength="8" required />
          </div>

          <button class="btn-primary w-full !py-2.5" :disabled="busy">
            {{ busy ? 'Please wait…' : mode === 'register' ? 'Launch Company →' : 'Sign In →' }}
          </button>
        </form>

        <p v-if="mode === 'register'" class="text-[11px] text-slate-500 mt-4 text-center">
          You'll start with ₡250,000, a garage, one mini-hauler and a driver.
        </p>
      </div>
    </div>
  </div>
</template>
