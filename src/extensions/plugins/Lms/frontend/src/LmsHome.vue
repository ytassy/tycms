<script setup>
import { useApplication } from '@tycms/api';

// Réutiliser le contexte et le client API du Core, notamment son jeton CSRF.
const application = useApplication();
</script>

<template>
    <section class="lms-home">
        <h1>LMS</h1>
        <p>La plateforme de formation est prête à être développée.</p>
        <p v-if="application.conf.getSpace()">Space : {{ application.conf.getSpace().name }}</p>
    </section>
</template>

<style scoped>
.lms-home { padding: 1rem; }
</style>
