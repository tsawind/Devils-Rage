<script setup lang="ts">
import SignatureToasts from '@/components/user/SignatureToasts.vue';
import { Toaster } from '@/components/ui/sonner';
import { useNotifications } from '@/composables/useNotifications';
import 'vue-sonner/style.css';

useNotifications();
</script>

<template>
    <Toaster position="top-center" />
    <!-- Patch 21: signature popups in their own column, just right of these -->
    <SignatureToasts />
</template>

<style scoped></style>
