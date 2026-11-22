<template>
	<div class="localized-text-field">
		<div class="d-flex justify-content-between align-items-center mb-1">
			<label class="mb-0">{{ label }}<span v-if="required" class="text-danger ms-1" aria-hidden="true">*</span></label>
			<div class="d-flex flex-wrap gap-1">
				<button v-if="spaceId > 0 && !isLocalized" type="button" class="btn btn-outline-primary btn-sm" :disabled="disabled"
					@click="toggleKeyPicker('')">Clé LG_</button>
				<button v-if="locales.length > 1" type="button" class="btn btn-outline-secondary btn-sm" :disabled="disabled" @click="toggleMode">
					{{ isLocalized ? 'Texte commun' : 'Traduire par langue' }}
				</button>
			</div>
		</div>

		<template v-for="language in isLocalized ? locales : ['']" :key="language || 'common'">
			<div v-if="isLocalized" class="d-flex justify-content-between align-items-center mt-2 mb-1">
				<label class="form-label small mb-0">{{ language }}</label>
				<button v-if="spaceId > 0" type="button" class="btn btn-outline-primary btn-sm" :disabled="disabled"
					@click="toggleKeyPicker(language)">Clé LG_</button>
			</div>
			<rich_text_editor v-if="mode === 'rich' && !isKeyReference(language)" :key="`rich:${language}`" :model-value="textFor(language)"
				:disabled="disabled" :media-space-id="spaceId" expandable @update:model-value="updateText(language, $event)" />
			<code_editor v-else-if="mode === 'html'" :key="`html:${language}`" :model-value="textFor(language)"
				syntax="html" :read-only="disabled" :aria-label="`${label} ${language}`" class="localized-text-field__code-editor"
				@update:model-value="updateText(language, $event)" />
			<textarea v-else-if="mode === 'textarea'" :value="textFor(language)" class="form-control form-control-sm"
				:maxlength="maxLength" :disabled="disabled" rows="4" @input="updateText(language, $event.target.value)" />
			<input v-else :value="textFor(language)" :type="inputType" class="form-control form-control-sm" :maxlength="maxLength"
				:disabled="disabled" @input="updateText(language, $event.target.value)">
			<small v-if="mode === 'rich' && isKeyReference(language)" class="form-text d-block">Référence de traduction : le texte riche est édité dans les Langues.</small>

			<div v-if="pickerLanguage === language" class="localized-text-field__picker card mt-2">
				<div class="card-body p-2">
					<div class="d-flex align-items-center gap-2 mb-2">
						<input v-model.trim="keySearch" type="search" class="form-control form-control-sm" :aria-label="`Rechercher une clé pour ${label}`"
							placeholder="Rechercher par clé ou par texte">
						<button type="button" class="btn-close flex-shrink-0" aria-label="Fermer la liste des clés" @click="closeKeyPicker" />
					</div>

					<p v-if="languageKeysStore.isLoading(spaceId, pickerLocale)" class="small text-muted mb-1" role="status">Chargement des clés…</p>
					<p v-else-if="languageKeysStore.errorFor(spaceId, pickerLocale)" class="small text-danger mb-1" role="alert">
						{{ languageKeysStore.errorFor(spaceId, pickerLocale) }}
						<button type="button" class="btn btn-link btn-sm" @click="loadKeys">Réessayer</button>
					</p>
					<template v-else>
						<p v-if="!filteredKeys.length" class="small text-muted mb-1">Aucune clé trouvée.</p>
						<div v-else class="localized-text-field__results list-group" role="list">
							<button v-for="entry in filteredKeys.slice(0, 30)" :key="entry.key" type="button"
								class="list-group-item list-group-item-action text-start" :disabled="disabled" @click="selectKey(language, entry.key)">
								<code class="d-block">{{ entry.key }}</code>
								<span class="localized-text-field__preview d-block small text-muted">{{ entry.value || 'Valeur vide' }}</span>
							</button>
						</div>
						<p v-if="filteredKeys.length > 30" class="small text-muted mt-2 mb-0">{{ filteredKeys.length - 30 }} autres clés : affinez la recherche.</p>
					</template>
				</div>
			</div>
		</template>
	</div>
</template>

<script>
import { selectLocalizedText } from './localizedText'

export default {
	props: {
		modelValue: { type: [String, Object], default: '' },
		label: { type: String, required: true },
		required: { type: Boolean, default: false },
		locales: { type: Array, default: () => [] },
		locale: { type: String, default: 'fr' },
		mode: { type: String, default: 'text' },
		inputType: { type: String, default: 'text' },
		maxLength: { type: Number, default: 500 },
		spaceId: { type: Number, default: 0 },
		disabled: { type: Boolean, default: false },
	},
	emits: ['update:modelValue'],
	data: () => ({ pickerLanguage: null, keySearch: '' }),
	computed: {
		isLocalized() { return this.modelValue !== null && typeof this.modelValue === 'object' && !Array.isArray(this.modelValue) },
		languageKeysStore() { return this.stores.useAdminLanguageKeysStore() },
		pickerLocale() { return this.pickerLanguage || this.locale },
		filteredKeys() {
			const query = this.keySearch.toLocaleLowerCase()
			const entries = this.languageKeysStore.entriesFor(this.spaceId, this.pickerLocale)
			if (!query) return entries

			return entries.filter(entry => entry.key.toLocaleLowerCase().includes(query)
				|| entry.value.toLocaleLowerCase().includes(query))
		},
	},
	watch: {
		spaceId() { this.closeKeyPicker() },
		locale() { this.closeKeyPicker() },
		disabled(value) { if (value) this.closeKeyPicker() },
	},
	methods: {
		textFor(language) { return selectLocalizedText(this.modelValue, language) },
		isKeyReference(language) {
			return /^LG_[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/.test(this.textFor(language))
		},
		closeKeyPicker() {
			this.pickerLanguage = null
			this.keySearch = ''
		},
		async toggleKeyPicker(language) {
			if (this.disabled || this.spaceId < 1) return

			if (this.pickerLanguage === language) {
				this.closeKeyPicker()
				return
			}

			// La langue de la ligne choisie sert à afficher sa vraie valeur effective.
			this.pickerLanguage = language
			this.keySearch = ''
			await this.loadKeys()
		},
		async loadKeys() {
			await this.languageKeysStore.load(this.spaceId, this.pickerLocale)
		},
		selectKey(language, key) {
			// Conserver la référence brute ; la résolution appartient au rendu public.
			this.updateText(language, key)
			this.closeKeyPicker()
		},
		updateText(language, text) {
			// On remplace l'objet entier pour conserver une édition réactive et brute.
			if (!this.isLocalized) this.$emit('update:modelValue', text)
			else this.$emit('update:modelValue', { ...this.modelValue, [language]: text })
		},
		async toggleMode() {
			this.closeKeyPicker()

			if (!this.isLocalized) {
				const translations = Object.fromEntries(this.locales.map(locale => [locale, '']))
				translations[this.locale] = this.modelValue ?? ''
				this.$emit('update:modelValue', translations)
				return
			}

			// Le retour au texte commun supprime les autres variantes : confirmer ce choix.
			if (!await this.askYesNoAsync('Remplacer toutes les traductions par le texte de la langue affichée ?')) return
			this.$emit('update:modelValue', selectLocalizedText(this.modelValue, this.locale))
		},
	},
}
</script>

<style scoped>
.localized-text-field {
	display: block;
	margin-bottom: .9rem;
	font-size: .84rem;
	font-weight: 600;
}

.localized-text-field .form-control {
	margin-top: .25rem;
	font-weight: 400;
}

.localized-text-field__code-editor {
	margin-top: .25rem;
	font-weight: 400;
}

.localized-text-field__code-editor :deep(.cm-editor) {
	height: min(360px, 45vh);
}

.localized-text-field__results {
	max-height: 280px;
	overflow-y: auto;
}

.localized-text-field__preview {
	white-space: pre-wrap;
	word-break: break-word;
	display: -webkit-box !important;
	-webkit-box-orient: vertical;
	-webkit-line-clamp: 2;
	overflow: hidden;
}
</style>
