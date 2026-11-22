<script>
export default {
    props: ['item', 'node', 'context', 'viewport', 'components'],
    computed: {
        // Le mixin Core est installé par l’éditeur, même avec une bibliothèque séparée.
        spaceId() { return this.conf.getSpace()?.id ?? null; },
    },
};
</script>

<template>
    <div v-visibility="true" class="lms-course-list" :data-space-id="spaceId">
        <strong>Liste des cours</strong>
        <p>La liste des cours est vide.</p>
    </div>
</template>

<style scoped>
.lms-course-list {
    padding: 1rem;
    border: 1px dashed currentColor;
    border-radius: .25rem;
}

.lms-course-list p { margin: .5rem 0 0; }
</style>
