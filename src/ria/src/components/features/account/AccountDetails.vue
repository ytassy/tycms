<template>
    <div v-if="layout === 'table' && rows.length" class="table-responsive">
        <table class="table table-sm align-middle mb-3">
            <caption class="visually-hidden">Informations de mon compte</caption>
            <tbody><tr v-for="row in rows" :key="row.name"><th scope="row">{{ row.label }}</th><td class="text-break">{{ row.value }}</td></tr></tbody>
        </table>
    </div>
    <dl v-else-if="layout === 'list' && rows.length">
        <div v-for="row in rows" :key="row.name" class="d-flex flex-wrap align-items-baseline gap-2 mb-2">
            <dt>{{ row.label }} :</dt><dd class="text-break mb-0">{{ row.value }}</dd>
        </div>
    </dl>
    <dl v-else-if="rows.length">
        <template v-for="row in rows" :key="row.name"><dt>{{ row.label }}</dt><dd class="text-break">{{ row.value }}</dd></template>
    </dl>
</template>

<script>
export default {
    props: {
        rows: { type: Array, required: true },
        layout: { type: String, default: 'simple' },
    },
}
</script>
