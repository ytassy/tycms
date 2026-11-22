<template>
	<div class="language-page">
		<h1>Traductions</h1>
		<p class="text-body-secondary">Les clés des extensions sont définies dans le code. Une surcharge enregistrée dans un espace s’applique aussi à ses descendants, jusqu’à ce qu’ils la remplacent.</p>

		<div v-if="loadingSpaces" class="py-4 text-center">
			<spinner />
		</div>
		<template v-else>
			<div v-if="error" class="alert alert-warning" role="alert">
				{{ error }}
				<button v-if="spaceId" type="button" class="btn btn-link btn-sm" :disabled="busy" @click="loadEntries(true)">Recharger</button>
			</div>

			<div v-if="!spaces.length" class="alert alert-secondary">Aucun espace accessible pour l’édition des traductions.</div>
			<template v-else>
				<!-- Le Space choisi détermine les langues affichées et les droits d'écriture. -->
				<h2 class="h5">Espace</h2>
				<div class="d-flex flex-wrap gap-2 mb-4" role="group" aria-label="Espace à configurer">
					<button
						v-for="space in spaces"
						:key="space.id"
						type="button"
						:class="['btn', 'btn-sm', 'rounded-pill', space.id === spaceId ? 'btn-primary' : 'btn-outline-primary']"
						:aria-pressed="space.id === spaceId"
						:disabled="busy"
						@click="selectSpace(space.id)"
					>
						{{ space.title }}
					</button>
				</div>

				<!-- La déclaration d'une nouvelle clé reste repliée pour garder la liste compacte. -->
				<details class="card mb-4">
					<summary class="card-header">Déclarer une clé pour cet espace</summary>
					<div class="card-body">
						<p class="text-body-secondary small">La clé et son texte source français seront visibles dans cet espace et ses descendants. Le nom d’une clé est unique sur le site.</p>

						<form class="row g-2 align-items-end" @submit.prevent="createKey">
							<div class="col-lg-6">
								<label for="new-language-key" class="form-label">Clé</label>
								<input id="new-language-key" v-model.trim="newKey" class="form-control" placeholder="LG_SITE_MENU_CONTACT" maxlength="150" aria-describedby="new-language-key-help" :disabled="busy" />
								<div id="new-language-key-help" class="form-text">Commence par LG_, avec des lettres, chiffres et _ ; les minuscules seront converties en majuscules.</div>
							</div>

							<div class="col-12">
								<label for="new-language-source" class="form-label">Texte source (fr), HTML accepté</label>
								<code_editor v-model="newSource" syntax="html" wrap-lines :max-bytes="5000" :read-only="busy" input-id="new-language-source" aria-label="Texte source français" class="language-code-editor" />
							</div>

							<div class="col-12">
								<button type="submit" class="btn btn-outline-primary" :disabled="busy || !validNewKey">Créer</button>
							</div>
						</form>
					</div>
				</details>

				<!-- Une ligne présente la même clé dans toutes les langues disponibles. -->
				<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-3">
					<div>
						<h2 class="h5 mb-1">Clés disponibles</h2>
						<p class="text-body-secondary small mb-0">Une valeur vide enregistrée masque le texte. Les langues des espaces descendants sont aussi éditables ici.</p>
					</div>
					<div class="btn-group" role="group" aria-label="Filtrer les clés">
						<button type="button" :class="['btn', 'btn-sm', keyFilter === 'all' ? 'btn-primary' : 'btn-outline-primary']" :aria-pressed="keyFilter === 'all'" @click="keyFilter = 'all'">Toutes</button>
						<button type="button" :class="['btn', 'btn-sm', keyFilter === 'recommended' ? 'btn-primary' : 'btn-outline-primary']" :aria-pressed="keyFilter === 'recommended'" @click="keyFilter = 'recommended'">Recommandées</button>
						<button type="button" :class="['btn', 'btn-sm', keyFilter === 'space' ? 'btn-primary' : 'btn-outline-primary']" :aria-pressed="keyFilter === 'space'" title="Clés ajoutées par un utilisateur dans cet espace ou un espace parent" @click="keyFilter = 'space'">Clés d’espace</button>
					</div>
					<div style="min-width: 240px">
						<label for="language-key-search" class="form-label">Rechercher</label>
						<input id="language-key-search" v-model.trim="search" class="form-control" placeholder="Clé, description ou texte" />
					</div>
				</div>

				<div v-if="loadingEntries" class="py-4 text-center">
					<spinner />
				</div>
				<div v-else-if="!filteredEntries.length" class="alert alert-secondary">Aucune clé ne correspond aux filtres.</div>
				<div v-else class="table-responsive mb-4">
					<table class="table table-sm table-hover align-middle border">
						<caption class="visually-hidden">Traductions par clé et par langue dans {{ selectedSpace?.title }}</caption>
						<thead class="table-light">
							<tr>
								<th scope="col">Clé</th>
								<th v-for="code in locales" :key="code" scope="col" class="language-column">{{ code.toUpperCase() }}</th>
								<th scope="col" class="text-end">Modifier</th>
							</tr>
						</thead>
						<tbody>
							<template v-for="entry in filteredEntries" :key="entry.key">
								<!-- La ligne fermée montre seulement le texte visible pour chaque langue. -->
								<tr>
									<th scope="row" class="language-key" :title="entry.key">
										<div class="d-flex align-items-center gap-2">
											<span class="text-truncate language-key-name">{{ entry.key }}</span>
											<span v-if="entry.recommended" class="badge text-bg-info fw-normal flex-shrink-0">Recommandé</span>
											<span v-if="entry.owner === 'space'" class="badge text-bg-secondary fw-normal flex-shrink-0" title="Clé ajoutée par un utilisateur dans cet espace ou un espace parent">Clé d’espace</span>
										</div>
										<span v-if="entry.description" class="d-block small fw-normal text-body-secondary mt-1">{{ entry.description }}</span>
										<span v-if="entryHasChanges(entry)" class="badge text-bg-warning fw-normal">Non enregistré</span>
									</th>
									<td v-for="code in locales" :key="code" class="language-column">
										<div class="language-preview" :title="entry.languages[code]?.preview">{{ entry.languages[code]?.preview }}</div>
									</td>
									<td class="text-end">
										<button
											type="button"
											class="btn btn-outline-primary btn-sm"
											:aria-label="`${editingKey === entry.key ? 'Fermer' : 'Éditer'} ${entry.key}`"
											:aria-expanded="editingKey === entry.key"
											:disabled="busy"
											@click="toggleEditor(entry.key)"
										>
											<i class="fa-solid fa-pen-to-square" aria-hidden="true" />
										</button>
									</td>
								</tr>

								<!-- Le crayon ouvre tous les éditeurs HTML de cette clé. -->
								<tr v-if="editingKey === entry.key" class="table-light">
									<td :colspan="locales.length + 2" class="p-3">
										<form @submit.prevent="saveEntry(entry)">
											<div class="small text-body-secondary mb-2">
												Source (fr) : {{ entry.source }} · {{ entry.owner === 'space' ? 'Clé d’espace' : entry.owner }}
											</div>

											<p v-if="entry.description" class="small mb-3">{{ entry.description }}</p>

											<div class="row g-3">
												<div v-for="code in locales" :key="code" class="col-12">
													<div class="border rounded bg-body p-3 h-100">
														<div class="d-flex flex-wrap align-items-center gap-2 mb-2">
															<span class="form-label fw-semibold mb-0">{{ code.toUpperCase() }} — surcharge dans {{ selectedSpace?.title }}</span>
															<div class="btn-group btn-group-sm" role="group" :aria-label="`Mode d’édition de la surcharge en ${code.toUpperCase()}`">
																<button type="button" :class="['btn', entry.languages[code].editorMode === 'code' ? 'btn-primary' : 'btn-outline-primary']"
																	:aria-pressed="entry.languages[code].editorMode === 'code'" :disabled="busy || entry.languages[code].remove"
																	@click="entry.languages[code].editorMode = 'code'">HTML</button>
																<button type="button" :class="['btn', entry.languages[code].editorMode === 'rich' ? 'btn-primary' : 'btn-outline-primary']"
																	:aria-pressed="entry.languages[code].editorMode === 'rich'" :disabled="busy || entry.languages[code].remove"
																	@click="entry.languages[code].editorMode = 'rich'">Texte riche</button>
															</div>
														</div>
														<!-- Les deux éditeurs partagent le même brouillon HTML, sans sauvegarde au changement de mode. -->
														<code_editor
															v-if="entry.languages[code].editorMode === 'code'"
															:key="`${entry.key}:${code}`"
															:model-value="entry.languages[code].draft"
															syntax="html"
															wrap-lines
															:max-bytes="20000"
															:read-only="busy || entry.languages[code].remove"
															:input-id="`override-${entry.key}-${code}`"
															:aria-label="`HTML pour ${entry.key} en ${code}`"
															class="language-code-editor"
															@update:model-value="updateDraft(entry.languages[code], $event)"
														/>
														<rich_text_editor
															v-else
															:model-value="entry.languages[code].draft"
															:disabled="busy || entry.languages[code].remove"
															:media-space-id="spaceId"
															:show-ai-button="false"
															:aria-label="`Texte riche pour ${entry.key} en ${code}`"
															@update:model-value="updateDraft(entry.languages[code], $event)"
														/>

														<div v-if="entry.languages[code].remove" class="form-text text-warning-emphasis">
															Retrait de la surcharge prévu à l’enregistrement.
														</div>
														<div class="form-text">
															Valeur affichée : {{ entry.languages[code].effective || '(vide)' }}
															({{ originLabel(entry.languages[code].origin) }})
															<br />Catalogue : {{ entry.languages[code].catalogue ?? '—' }}
															<br />Héritée{{ entry.languages[code].inheritedSpaceId ? ` de ${spaceTitle(entry.languages[code].inheritedSpaceId)}` : '' }} : {{ entry.languages[code].inherited ?? '—' }}
														</div>
														<div v-if="entry.languages[code].localUpdatedAt" class="form-text">
															Modifiée le {{ entry.languages[code].localUpdatedAt }}.
														</div>
														<button
															v-if="entry.languages[code].local !== null || entry.languages[code].remove"
															type="button"
															class="btn btn-link btn-sm px-0"
															:disabled="busy"
															@click="toggleRemove(entry.languages[code])"
														>
															{{ entry.languages[code].remove ? 'Annuler le retrait' : 'Retirer la surcharge' }}
														</button>
													</div>
												</div>
											</div>

											<div class="mt-3">
												<button type="submit" class="btn btn-primary btn-sm" :disabled="busy || !entryHasChanges(entry)">
													Enregistrer toutes les langues modifiées
												</button>
											</div>
										</form>
									</td>
								</tr>
							</template>
						</tbody>
					</table>
				</div>
			</template>
		</template>
	</div>
</template>

<script>
const keyPattern = /^LG_[A-Z][A-Z0-9]*(?:_[A-Z0-9]+)*$/

function htmlPreview(value) {
	// Un template inerte permet d'afficher seulement le texte, sans rendre le HTML.
	const template = document.createElement('template')
	template.innerHTML = value ?? ''

	return template.content.textContent?.trim() || '(vide)'
}

export default {
	data() {
		return {
			spaces: [],
			spaceId: null,
			locales: [],
			entries: [],
			search: '',
			keyFilter: 'all',
			newKey: '',
			newSource: '',
			editingKey: null,
			loadingSpaces: true,
			loadingEntries: false,
			busy: false,
			error: '',
			requestId: 0,
		}
	},
	computed: {
		selectedSpace() {
			return this.spaces.find(space => space.id === this.spaceId) ?? null
		},
		validNewKey() {
			return keyPattern.test(this.newKey.toUpperCase())
				&& this.newKey.length <= 150
				&& this.newSource.trim().length > 0
		},
		filteredEntries() {
			// La recommandation et la description complètent la recherche dans les traductions.
			const term = this.search.toLocaleLowerCase()

			return this.entries.filter(entry => {
				if (this.keyFilter === 'recommended' && !entry.recommended) {
					return false
				}
				if (this.keyFilter === 'space' && entry.owner !== 'space') {
					return false
				}

				if (!term) {
					return true
				}

				const values = [
					entry.key,
					entry.source,
					entry.description ?? '',
					...this.locales.map(code => entry.languages[code]?.effective ?? ''),
				]

				return values.some(value => value.toLocaleLowerCase().includes(term))
			}).sort((left, right) =>
				Number(!!right.recommended) - Number(!!left.recommended)
				|| left.key.localeCompare(right.key)
			)
		},
		hasDrafts() {
			return this.entries.some(entry => this.entryHasChanges(entry))
		},
	},
	async mounted() {
		// Charger d'abord les Spaces autorisés, puis le tableau du premier Space.
		try {
			const response = await this.api.get('/administrator/api/languages/spaces')
			this.spaces = response.spaces ?? []

			if (this.spaces.length) {
				await this.selectSpace(this.spaces[0].id)
			}
		} catch (_) {
			this.error = 'Impossible de charger les espaces de traduction.'
		} finally {
			this.loadingSpaces = false
		}
	},
	methods: {
		entryHasChanges(entry) {
			return this.locales.some(code => entry.languages[code]?.edited)
		},
		updateDraft(language, value) {
			// Ignorer les événements des éditeurs lors d'une mise à jour externe.
			if (language.draft === value) {
				return
			}

			language.draft = value
			language.edited = true
			language.remove = false
		},
		toggleRemove(language) {
			// Conserver le brouillon pour pouvoir annuler un retrait préparé.
			if (language.remove) {
				language.remove = false
				language.draft = language.beforeRemoveDraft ?? (language.local ?? '')
				language.edited = language.beforeRemoveEdited ?? false
				return
			}

			language.beforeRemoveDraft = language.draft
			language.beforeRemoveEdited = language.edited
			language.remove = true
			language.edited = true
		},
		toggleEditor(key) {
			this.editingKey = this.editingKey === key ? null : key
		},
		spaceTitle(id) {
			return this.spaces.find(space => space.id === id)?.title ?? `l’espace #${id}`
		},
		originLabel(origin) {
			const labels = {
				space: 'Surcharge locale',
				parent: 'Surcharge héritée',
				catalogue: 'Catalogue',
				source: 'Texte source',
			}

			return labels[origin] ?? ''
		},
		async canLeave() {
			return !this.hasDrafts || await this.askYesNoAsync('Abandonner les traductions non enregistrées ?')
		},
		async selectSpace(id) {
			// Changer de Space réinitialise la sélection et protège les brouillons.
			if (this.spaceId === id || this.busy) {
				return
			}

			if (!await this.canLeave()) {
				return
			}

			this.spaceId = id
			this.editingKey = null
			this.locales = []
			this.entries = []

			await this.loadEntries()
		},
		async loadEntries(preserveDrafts = false, savedKey = null) {
			// Numéroter les requêtes pour ignorer une réponse devenue obsolète.
			const requestId = ++this.requestId

			// Conserver les modifications des autres clés après création ou sauvegarde.
			const drafts = new Map()
			const editorModes = new Map()
			if (preserveDrafts) {
				for (const entry of this.entries) {
					// Conserver le mode visuel, y compris pour la clé qui vient d'être sauvegardée.
					for (const code of this.locales) {
						editorModes.set(`${entry.key}:${code}`, entry.languages[code]?.editorMode ?? 'code')
					}

					if (entry.key === savedKey) {
						continue
					}

					for (const code of this.locales) {
						const language = entry.languages[code]
						if (language?.edited) {
							drafts.set(`${entry.key}:${code}`, {
								draft: language.draft,
								remove: language.remove,
								beforeRemoveDraft: language.beforeRemoveDraft,
								beforeRemoveEdited: language.beforeRemoveEdited,
							})
						}
					}
				}
			}

			this.loadingEntries = true
			this.error = ''

			try {
				// Une réponse contient toutes les langues, donc le tableau reste cohérent.
				const response = await this.api.get('/administrator/api/languages/matrix', { spaceId: this.spaceId })
				if (requestId !== this.requestId) {
					return
				}

				this.locales = response.locales ?? []
				this.entries = (response.entries ?? []).map(entry => ({
					...entry,
					languages: Object.fromEntries(this.locales.map(code => {
						const language = entry.languages[code]
						const pending = drafts.get(`${entry.key}:${code}`)

						return [code, {
							...language,
							editorMode: editorModes.get(`${entry.key}:${code}`) ?? 'code',
							preview: htmlPreview(language.effective),
							draft: pending ? pending.draft : (language.local ?? ''),
							remove: pending?.remove ?? false,
							edited: !!pending,
							beforeRemoveDraft: pending?.beforeRemoveDraft,
							beforeRemoveEdited: pending?.beforeRemoveEdited,
						}]
					})),
				}))
			} catch (_) {
				if (requestId === this.requestId) {
					this.error = 'Impossible de charger les traductions.'
				}
			} finally {
				if (requestId === this.requestId) {
					this.loadingEntries = false
				}
			}
		},
		async createKey() {
			if (this.busy || !this.validNewKey) {
				return
			}

			this.busy = true
			this.error = ''

			try {
				// Le serveur revalide la clé et purifie la source HTML avant stockage.
				await this.api.post('/administrator/api/languages/custom/create', {
					spaceId: this.spaceId,
					data: {
						key: this.newKey.toUpperCase(),
						sourceValue: this.newSource,
					},
				})

				this.newKey = ''
				this.newSource = ''
				await this.loadEntries(true)
			} catch (_) {
				this.error = 'La clé n’a pas pu être créée.'
			} finally {
				this.busy = false
			}
		},
		async saveEntry(entry) {
			if (this.busy || !this.entryHasChanges(entry)) {
				return
			}

			this.busy = true
			this.error = ''

			// Ne transmettre que les langues modifiées ; un retrait devient une action distincte.
			const changes = this.locales
				.filter(code => entry.languages[code].edited)
				.map(code => {
					const language = entry.languages[code]
					if (language.remove) {
						return { locale: code, action: 'delete' }
					}

					return { locale: code, action: 'save', value: language.draft }
				})

			try {
				// La route applique toutes les langues de cette clé dans une transaction.
				await this.api.post('/administrator/api/languages/overrides/save-many', {
					spaceId: this.spaceId,
					data: { key: entry.key, changes },
				})

				await this.loadEntries(true, entry.key)
			} catch (_) {
				this.error = 'Les traductions n’ont pas pu être enregistrées.'
			} finally {
				this.busy = false
			}
		},
	},
}
</script>

<style scoped>
.language-page {
	width: 100%;
	min-width: 0;
}

.language-key {
	max-width: 360px;
	min-width: 260px;
}

.language-key-name {
	min-width: 0;
}

.language-column {
	min-width: 190px;
	max-width: 300px;
}

.language-preview {
	overflow: hidden;
	text-overflow: ellipsis;
	white-space: nowrap;
	max-width: 280px;
}

summary.card-header {
	cursor: pointer;
}

.language-code-editor :deep(.cm-editor) {
	min-height: 120px;
	max-height: 70vh;
}

.language-code-editor :deep(.cm-scroller) {
	overflow: auto;
}
</style>
