<template>
	<div class="card shadow-sm">
		<div class="card-header bg-primary text-white">
			<h1 class="h5 mb-0">{{ formDefinition.label }}</h1>
		</div>
		<div class="card-body">
			<p v-if="formDefinition.description" class="text-body-secondary">{{ formDefinition.description }}</p>
			<form_renderer v-model="values" :definition="formDefinition" :errors="errors" />
		</div>
		<div class="card-footer bg-body-tertiary">
			<div class="small text-body-secondary mb-1">Valeurs courantes (démonstration, non sauvegardées)</div>
			<pre class="mb-0"><code>{{ values }}</code></pre>
		</div>
	</div>
</template>

<script>
export default {
	props: {
		formDefinition: {
			type: Object,
			required: true,
		},
	},

	data() {
		return {
			values: { ...this.formDefinition.initialValues },
			errors: {},
		}
	},
}
</script>
