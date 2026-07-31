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
