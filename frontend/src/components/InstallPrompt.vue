<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'

// Chrome/Edge/Android fire `beforeinstallprompt`; we capture it and drive our
// own button. iOS Safari has no such event, so we show a short "Add to Home
// Screen" hint instead. Either way the banner is dismissible and remembered.
const DISMISS_KEY = 'transoria.install.dismissed'

const deferred = ref<any>(null)
const show = ref(false)
const isIos = ref(false)
const installing = ref(false)

function isStandalone(): boolean {
  return (
    window.matchMedia?.('(display-mode: standalone)').matches ||
    // iOS Safari exposes this non-standard flag when launched from Home Screen
    (navigator as any).standalone === true
  )
}

function onBeforeInstall(e: Event) {
  e.preventDefault()
  deferred.value = e
  if (localStorage.getItem(DISMISS_KEY) !== '1') show.value = true
}

function onInstalled() {
  show.value = false
  deferred.value = null
}

async function install() {
  const e = deferred.value
  if (!e) return
  installing.value = true
  try {
    e.prompt()
    await e.userChoice
  } finally {
    installing.value = false
    show.value = false
    deferred.value = null
  }
}

function dismiss() {
  show.value = false
  localStorage.setItem(DISMISS_KEY, '1')
}

onMounted(() => {
  if (isStandalone() || localStorage.getItem(DISMISS_KEY) === '1') return

  const ua = window.navigator.userAgent
  const iosDevice = /iphone|ipad|ipod/i.test(ua)
  // iPadOS 13+ reports as desktop Safari; detect via touch + Mac.
  const iPadOsDesktop = /macintosh/i.test(ua) && (navigator as any).maxTouchPoints > 1
  const safari = /^((?!chrome|crios|android|fxios).)*safari/i.test(ua)

  if ((iosDevice || iPadOsDesktop) && safari) {
    isIos.value = true
    show.value = true
    return
  }

  window.addEventListener('beforeinstallprompt', onBeforeInstall)
  window.addEventListener('appinstalled', onInstalled)
})

onUnmounted(() => {
  window.removeEventListener('beforeinstallprompt', onBeforeInstall)
  window.removeEventListener('appinstalled', onInstalled)
})
</script>

<template>
  <Teleport to="body">
  <Transition name="install">
    <div v-if="show" class="install-wrap fixed inset-x-0 z-[1200] p-3">
      <div
        class="glass mx-auto flex max-w-md items-center gap-3 rounded-2xl border border-white/10 p-3 shadow-2xl"
      >
        <img src="/icon-192.png" alt="" class="h-11 w-11 rounded-xl shadow-lg" />
        <div class="min-w-0 flex-1">
          <p class="text-sm font-semibold leading-tight">Install Transoria</p>
          <p v-if="isIos" class="text-[11px] leading-snug text-slate-400">
            Tap <span class="text-slate-200">Share</span> <span aria-hidden>⎋</span>, then
            <span class="text-slate-200">“Add to Home Screen”</span> to play like an app.
          </p>
          <p v-else class="text-[11px] leading-snug text-slate-400">
            Add it to your home screen — full-screen, own icon, one tap to play.
          </p>
        </div>
        <button
          v-if="!isIos"
          class="btn-primary shrink-0 !px-4 !py-1.5 text-sm"
          :disabled="installing"
          @click="install"
        >
          {{ installing ? '…' : 'Install' }}
        </button>
        <button
          class="shrink-0 rounded-lg px-2 py-1 text-slate-400 hover:text-white"
          aria-label="Dismiss"
          @click="dismiss"
        >
          ✕
        </button>
      </div>
    </div>
  </Transition>
  </Teleport>
</template>

<style scoped>
/* Sit just above the mobile bottom tab bar; flush to the edge on desktop. */
.install-wrap {
  bottom: calc(0.75rem + env(safe-area-inset-bottom));
}
@media (max-width: 1023px) {
  .install-wrap {
    bottom: calc(4.75rem + env(safe-area-inset-bottom));
  }
}
.install-enter-active,
.install-leave-active {
  transition: transform 0.28s ease, opacity 0.28s ease;
}
.install-enter-from,
.install-leave-to {
  transform: translateY(120%);
  opacity: 0;
}
</style>
