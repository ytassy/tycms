<template>
	<modal :visible="request !== null" width="480px" :zIndex="9999996" :closable="true" @close="cancel">
		<template #header>{{ request?.title }}</template>
		<template #body>
			<form id="global-modal-input-form" @submit.prevent="submit">
				<label for="global-modal-input" class="form-label">{{ request?.label }}</label>
				<input id="global-modal-input" ref="input" v-model="value" class="form-control" type="text" required autocomplete="off">
			</form>
		</template>
		<template #footer>
			<button type="button" class="btn btn-outline-secondary esc me-2" @click="cancel">Annuler</button>
			<button type="submit" form="global-modal-input-form" class="btn btn-primary" :disabled="!value.trim()">Valider</button>
		</template>
	</modal>
</template>

<script>
export default {
	data() { return { value: '' } },
	computed: {
		appStore() { return this.stores.useAppStore() },
		request() { return this.appStore.modalInput },
	},
	watch: {
		request(request) {
			if (!request) return
			this.value = request.value
			this.$nextTick(() => { this.$refs.input?.focus(); this.$refs.input?.select() })
		},
	},
	methods: {
		submit() { if (this.value.trim()) this.appStore.answerInput(this.value.trim()) },
		cancel() { this.appStore.answerInput(null) },
	},
}
</script>
