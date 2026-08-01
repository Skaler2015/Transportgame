<script setup lang="ts">
import ToastHost from './components/ToastHost.vue'
import InstallPrompt from './components/InstallPrompt.vue'
</script>

<template>
  <RouterView />
  <ToastHost />
  <InstallPrompt />
</template>
