<template>
	<component :is="tag" class="file-dnd" :class="{ 'file-dnd--dragover': dragOver }" @dragenter="onDragEnter" @dragleave="onDragLeave" @dragover="onDragOver" @drop="onDrop">
		<input ref="input" class="visually-hidden" type="file" :multiple="multiple" :accept="accept" :disabled="disabled || uploading" @change="onChange">
		<slot :open-picker="openPicker">
			<button type="button" class="btn btn-outline-primary" :disabled="disabled || uploading" @click="openPicker"><i class="fa-solid fa-upload me-2" />Choisir des fichiers</button>
		</slot>
		<div v-if="dragOver" class="file-dnd__drop-hint" aria-hidden="true"><i class="fa-solid fa-cloud-arrow-up fa-2x" /><span>Déposez vos fichiers ici</span></div>
		<div v-if="validationError" class="alert alert-warning file-dnd__error" role="alert">{{ validationError }}</div>
		<div v-if="uploading" class="file-dnd__progress card shadow-sm" role="status" aria-live="polite">
			<div class="small mb-2">{{ progress === null ? 'Téléversement en cours…' : progress >= 100 ? 'Traitement en cours…' : `Téléversement : ${percent}%` }}</div>
			<div class="progress" :aria-label="progress === null ? 'Téléversement en cours' : `Téléversement : ${percent}%`">
				<div class="progress-bar progress-bar-striped" :class="{ 'progress-bar-animated': progress === null || progress >= 100 }" role="progressbar" :style="{ width: `${progress === null ? 100 : percent}%` }" :aria-valuenow="progress === null ? undefined : percent" aria-valuemin="0" aria-valuemax="100" />
			</div>
		</div>
	</component>
</template>

<script>
export default {
	props: {
		tag: { type: String, default: 'div' },
		multiple: { type: Boolean, default: false },
		acceptedExtensions: { type: Array, default: () => [] },
		maxSize: { type: Number, default: null },
		disabled: { type: Boolean, default: false },
		internalDragging: { type: Boolean, default: false },
		uploading: { type: Boolean, default: false },
		progress: { type: Number, default: null },
	},
	emits: ['files'],
	data() { return { dragOver: false, dragDepth: 0, validationError: '' } },
	computed: {
		accept() { return this.acceptedExtensions.map(extension => `.${extension}`).join(',') },
		percent() { return Math.max(0, Math.min(100, Math.round(this.progress ?? 0))) },
	},
	methods: {
		openPicker() { if (!this.disabled && !this.uploading) this.$refs.input?.click() },
		isFileDrag(event) { return !this.internalDragging && Array.from(event.dataTransfer?.types ?? []).includes('Files') },
		onDragEnter(event) {
			if (!this.isFileDrag(event) || this.disabled || this.uploading) return
			this.dragDepth++
			this.dragOver = true
		},
		onDragLeave(event) {
			if (!this.isFileDrag(event)) return
			this.dragDepth = Math.max(0, this.dragDepth - 1)
			if (this.dragDepth === 0) this.dragOver = false
		},
		onDragOver(event) {
			if (!this.isFileDrag(event) || this.disabled || this.uploading) return
			event.preventDefault()
			event.dataTransfer.dropEffect = 'copy'
		},
		onDrop(event) {
			if (!this.isFileDrag(event)) return
			event.preventDefault()
			this.dragDepth = 0
			this.dragOver = false
			if (!this.disabled && !this.uploading) this.setFiles(Array.from(event.dataTransfer.files))
		},
		onChange(event) {
			const files = Array.from(event.target.files)
			event.target.value = ''
			this.setFiles(files)
		},
		setFiles(files) {
			this.validationError = ''
			const selected = this.multiple ? files : files.slice(0, 1)
			if (!selected.length || this.disabled || this.uploading) return
			const allowed = new Set(this.acceptedExtensions.map(extension => extension.toLowerCase()))
			for (const file of selected) {
				const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
				if ((allowed.size && !allowed.has(extension)) || (this.maxSize && file.size > this.maxSize) || file.size < 1) {
					this.validationError = `Le fichier « ${file.name} » ne respecte pas les règles d’envoi.`
					return
				}
			}
			this.$emit('files', selected)
		},
	},
}
</script>

<style scoped>
.file-dnd { position: relative; }
.file-dnd--dragover { outline: 3px dashed #0d6efd; outline-offset: -6px; }
.file-dnd__drop-hint { position: absolute; z-index: 2; inset: 0; display: grid; place-content: center; justify-items: center; gap: .75rem; background: rgb(231 241 255 / 88%); color: #084298; font-weight: 600; pointer-events: none; }
.file-dnd__error { margin: 1rem; }
.file-dnd__progress { position: absolute; z-index: 3; bottom: 1rem; left: 1rem; right: 1rem; padding: 1rem; }
</style>
