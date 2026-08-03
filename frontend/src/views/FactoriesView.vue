<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { api, apiError } from '../api/client'
import { useToastStore } from '../stores/toast'
import { credits, num } from '../utils/format'

const toast = useToastStore()

interface InputReq { commodity: string; qty: number }
interface Factory {
  id: number; recipe: string; name: string; icon: string; level: number; status: string
  last_note: string | null; lifetime_output: number; output: string | null
  output_per_cycle: number; op_cost: number; inputs_per_cycle: InputReq[]
  upgrade_cost: number; warehouse: { id: number; name: string; city: string }
}
interface CatalogItem {
  recipe: string; name: string; icon: string; output: string; output_qty: number
  inputs: InputReq[]; op_cost: number; build_cost: number; upkeep: number
  unlock: number; needs: string | null; unlocked: boolean; affordable: boolean
}
interface Wh { id: number; name: string; city: { id: number; name: string } }

const factories = ref<Factory[]>([])
const catalog = ref<CatalogItem[]>([])
const warehouses = ref<Wh[]>([])
const loading = ref(true)
const busy = ref<number | string | null>(null)

const buildRecipe = ref<string | null>(null)
const buildWarehouse = ref<number | null>(null)

const selectedCatalog = computed(() => catalog.value.find((c) => c.recipe === buildRecipe.value) || null)
const activeCount = computed(() => factories.value.filter((f) => f.status === 'active').length)

async function load() {
  try {
    const [{ data: fac }, { data: wh }] = await Promise.all([
      api.get('/factories'),
      api.get('/warehouses'),
    ])
    factories.value = fac.data
    catalog.value = fac.catalog
    warehouses.value = wh.data ?? []
    if (!buildWarehouse.value) buildWarehouse.value = warehouses.value[0]?.id ?? null
    if (!buildRecipe.value) buildRecipe.value = catalog.value.find((c) => c.unlocked)?.recipe ?? null
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    loading.value = false
  }
}

async function build() {
  if (!buildRecipe.value || !buildWarehouse.value) return
  busy.value = 'build'
  try {
    await api.post('/factories', { recipe: buildRecipe.value, warehouse_id: buildWarehouse.value })
    toast.success('Factory built — production begins next cycle.')
    await load()
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

async function upgrade(f: Factory) {
  busy.value = f.id
  try {
    await api.post(`/factories/${f.id}/upgrade`)
    toast.success(`${f.name} upgraded to L${f.level + 1}.`)
    await load()
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

async function toggle(f: Factory) {
  busy.value = f.id
  try {
    await api.post(`/factories/${f.id}/toggle`)
    await load()
  } catch (e) {
    toast.error(apiError(e))
  } finally {
    busy.value = null
  }
}

onMounted(load)
</script>

<template>
  <div class="space-y-5">
    <div class="flex items-end justify-between flex-wrap gap-3">
      <div>
        <h1 class="text-2xl font-bold">Manufacturing</h1>
        <p class="text-slate-400 text-sm">
          Build factories at your warehouses to produce goods — then sell them locally or haul them to a dearer market.
        </p>
      </div>
      <div class="text-right text-sm">
        <p class="stat-label">Running</p>
        <p class="font-bold text-gain">{{ activeCount }} / {{ factories.length }}</p>
      </div>
    </div>

    <div v-if="loading" class="glass p-10 text-center text-slate-400">Loading factories…</div>

    <template v-else>
      <!-- Owned factories -->
      <div v-if="factories.length" class="grid md:grid-cols-2 gap-3">
        <div v-for="f in factories" :key="f.id" class="glass p-4">
          <div class="flex items-start justify-between gap-2">
            <div class="flex items-center gap-2 min-w-0">
              <span class="text-2xl">{{ f.icon }}</span>
              <div class="min-w-0">
                <p class="font-semibold truncate">{{ f.name }} <span class="chip bg-white/5 text-slate-400">L{{ f.level }}</span></p>
                <p class="text-[11px] text-slate-400 truncate">{{ f.warehouse.name }} · {{ f.warehouse.city }}</p>
              </div>
            </div>
            <span class="chip shrink-0" :class="f.status === 'active' ? 'bg-gain/15 text-gain' : 'bg-white/10 text-slate-400'">
              {{ f.status === 'active' ? '● Running' : '❚❚ Paused' }}
            </span>
          </div>

          <div class="grid grid-cols-3 gap-2 mt-3 text-center">
            <div class="glass !p-2"><p class="stat-label">Output/cycle</p><p class="font-mono text-sm">{{ f.output_per_cycle }}</p></div>
            <div class="glass !p-2"><p class="stat-label">Run cost</p><p class="font-mono text-sm text-loss">{{ credits(f.op_cost, { compact: true }) }}</p></div>
            <div class="glass !p-2"><p class="stat-label">Made total</p><p class="font-mono text-sm">{{ num(f.lifetime_output) }}</p></div>
          </div>

          <p v-if="f.inputs_per_cycle.length" class="text-[11px] text-slate-400 mt-2">
            Needs per cycle:
            <span v-for="(i, idx) in f.inputs_per_cycle" :key="i.commodity">
              {{ i.qty }}× {{ i.commodity }}<span v-if="idx < f.inputs_per_cycle.length - 1">, </span>
            </span>
            <span class="text-slate-500">(stock them in this warehouse)</span>
          </p>

          <p v-if="f.status === 'active' && f.last_note" class="text-[11px] text-gold mt-2">⚠ {{ f.last_note }}</p>

          <div class="flex gap-2 mt-3">
            <button class="btn-ghost !py-1.5 text-xs flex-1" :disabled="busy === f.id" @click="toggle(f)">
              {{ f.status === 'active' ? 'Pause' : 'Resume' }}
            </button>
            <button v-if="f.upgrade_cost > 0" class="btn-primary !py-1.5 text-xs flex-1" :disabled="busy === f.id" @click="upgrade(f)">
              Upgrade · {{ credits(f.upgrade_cost, { compact: true }) }}
            </button>
            <span v-else class="btn-ghost !py-1.5 text-xs flex-1 text-center opacity-50">Max level</span>
          </div>
        </div>
      </div>
      <div v-else class="glass p-8 text-center text-slate-400">
        No factories yet. Build one below to start producing your own cargo.
      </div>

      <!-- Build panel -->
      <div class="glass p-5">
        <h2 class="font-semibold mb-3">Build a factory</h2>
        <div v-if="!warehouses.length" class="text-sm text-slate-400">
          You need a warehouse first — build one on the Warehouses page, then site a factory there.
        </div>
        <template v-else>
          <div class="grid sm:grid-cols-2 gap-3">
            <div>
              <label class="stat-label">Factory type</label>
              <select v-model="buildRecipe" class="input mt-1 w-full">
                <optgroup label="Available">
                  <option v-for="c in catalog.filter((x) => x.unlocked)" :key="c.recipe" :value="c.recipe">{{ c.icon }} {{ c.name }} → {{ c.output }}</option>
                </optgroup>
                <optgroup label="Locked (level up to unlock)">
                  <option v-for="c in catalog.filter((x) => !x.unlocked)" :key="c.recipe" :value="c.recipe" disabled>🔒 {{ c.name }} (Lv {{ c.unlock }})</option>
                </optgroup>
              </select>
            </div>
            <div>
              <label class="stat-label">At warehouse</label>
              <select v-model="buildWarehouse" class="input mt-1 w-full">
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }} · {{ w.city.name }}</option>
              </select>
            </div>
          </div>

          <div v-if="selectedCatalog" class="glass !bg-white/5 p-3 mt-3 text-sm space-y-1">
            <div class="flex justify-between"><span class="text-slate-400">Produces</span><span>{{ selectedCatalog.output_qty }}× {{ selectedCatalog.output }} / cycle</span></div>
            <div class="flex justify-between"><span class="text-slate-400">Inputs</span>
              <span>
                <template v-if="selectedCatalog.inputs.length">
                  <span v-for="(i, idx) in selectedCatalog.inputs" :key="i.commodity">{{ i.qty }}× {{ i.commodity }}<span v-if="idx < selectedCatalog.inputs.length - 1">, </span></span>
                </template>
                <template v-else>None (raw producer)</template>
              </span>
            </div>
            <div class="flex justify-between"><span class="text-slate-400">Run cost / cycle</span><span class="text-loss">{{ credits(selectedCatalog.op_cost) }}</span></div>
            <div v-if="selectedCatalog.needs" class="flex justify-between"><span class="text-slate-400">Warehouse must have</span><span class="text-gold">{{ selectedCatalog.needs === 'cold' ? 'cold storage' : 'a hazmat bay' }}</span></div>
            <div class="flex justify-between border-t border-white/10 pt-1 mt-1"><span class="text-slate-400">Build cost</span><span class="font-semibold">{{ credits(selectedCatalog.build_cost) }}</span></div>
          </div>

          <button class="btn-primary w-full mt-3"
            :disabled="busy === 'build' || !selectedCatalog?.unlocked || !selectedCatalog?.affordable"
            @click="build">
            {{ !selectedCatalog?.affordable ? 'Not enough cash' : 'Build factory' }}
          </button>
        </template>
      </div>

      <p class="text-[11px] text-slate-500">
        Tip: raw producers (farm, sawmill, mine…) need no inputs. Factories consume other goods — haul steel & electronics
        into an Auto Plant's warehouse and it builds cars worth far more than the parts.
      </p>
    </template>
  </div>
</template>
