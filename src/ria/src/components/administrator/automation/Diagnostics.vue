<template>
    <div v-if="items.length" class="automation-diagnostics">
        <div v-for="(item, index) in items" :key="`${item.code}-${item.line}-${item.column}-${index}`"
            class="d-flex align-items-start gap-2 py-1">
            <span :class="['badge flex-shrink-0', item.severity === 'warning' ? 'text-bg-warning' : 'text-bg-danger']">
                {{ item.line }}:{{ item.column }}
            </span>
            <span class="small">{{ item.message }}</span>
            <code class="small text-body-secondary ms-auto">{{ item.code }}</code>
        </div>
    </div>
</template>

<script>
export default {
    props: {
        items: { type: Array, default: () => [] },
    },
}
</script>

<style scoped>
.automation-diagnostics {
    border-left: 3px solid var(--bs-danger);
    padding-left: 0.75rem;
}
</style>
