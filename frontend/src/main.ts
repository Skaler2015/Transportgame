import { createApp } from 'vue'
import { createPinia } from 'pinia'
import 'leaflet/dist/leaflet.css'
import './style.css'
import App from './App.vue'
import { router } from './router'
import { useAuthStore } from './stores/auth'

const app = createApp(App)
app.use(createPinia())

// Restore any stored session before mounting so guards see the right state.
const auth = useAuthStore()
auth.bootstrap().finally(() => {
  app.use(router)
  app.mount('#app')
})

// Register the service worker so the game is installable as a standalone app
// and its shell opens instantly. Dev (Vite) is skipped — SW only ships in the
// built SPA served from the same origin.
if ('serviceWorker' in navigator && import.meta.env.PROD) {
  window.addEventListener('load', () => {
    navigator.serviceWorker.register('/sw.js').catch(() => {
      /* installability is a progressive enhancement — ignore failures */
    })
  })
}
