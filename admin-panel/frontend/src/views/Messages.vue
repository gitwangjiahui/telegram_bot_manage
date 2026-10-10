<template>
  <div class="messages-route">
    <ChatConsole />
  </div>
</template>

<script setup>
import ChatConsole from '../components/ChatConsole.vue';
</script>

<style scoped>
.messages-route {
  height: calc(100vh - 60px);
  background: #fff;
}
</style>
