import { defineStore } from 'pinia'
import { parsePageNode, pastePageNode, serializePageNode } from '../components/administrator/pages/pageClipboard.js'
import { MAX_ZONE_BODIES, canDuplicateNode, duplicateNode, collectNodes, childZones, setPageComponentDefinitions, zoneDefinition, replaceNodeReference, createZone, createColumn, createTab, validTree, findNode, nearestInsertTarget, placeNode } from '../components/administrator/pages/pageEditorModel.js'
import { pageJsonBytes, pageMaxSize } from '../components/administrator/pages/pageSize.js'

const emptyDocument = () => ({ version: 3, container: { fluid: false }, items: [] })
const copy = value => JSON.parse(JSON.stringify(value))

export const useAdminPagesStore = {
	get(vueApp, application) {
		return defineStore('admin_pages', {
			state: () => ({
				spaces: [], pages: [], components: [], editContext: {}, forms: {}, current: null, baseline: '',
				previewLocale: 'fr', previewTabs: {}, previewAccordion: {}, activeZones: {},
				selectedId: null, activeTab: 'components', dragPayload: null, editorSession: 0,
				loading: false, saving: false, previewing: false, pasting: false,
			}),
			getters: {
				catalogue: state => state.components,
				maxPageSize: state => pageMaxSize(state.editContext),
				pageSize: state => state.current?.type === 2 ? pageJsonBytes(state.current.data) : 0,
				selected: state => findNode(state.current?.data, state.selectedId),
				isDirty: state => Boolean(state.current && (state.current.id == null || JSON.stringify(state.current) !== state.baseline)),
				currentSpaceLanguages: state => state.spaces.find(space => space.id === state.current?.space_id)?.languages ?? [],
				currentSpaceConfiguredLanguages: state => state.spaces.find(space => space.id === state.current?.space_id)?.configuredLanguages ?? [],
			},
			actions: {
				async loadDirectory() {
					this.loading = true
					try {
						const response = await application.api.get('/administrator/api/pages')
						this.spaces = response.spaces ?? []
						this.pages = response.pages ?? []
						this.components = response.components ?? []
						setPageComponentDefinitions(this.components)
					} finally { this.loading = false }
				},
				async changeStatus(pageId, status) {
					await application.api.post('/administrator/api/pages/status', { pageId, status: String(status) })
					await this.loadDirectory()
				},
				async deletePage(pageId) {
					await application.api.post('/administrator/api/pages/delete', { pageId })
					await this.loadDirectory()
				},
				async open(pageId, { preserveWorkspace = false } = {}) {
					const response = await application.api.get('/administrator/api/pages/read', { pageId })
					const selectedId = preserveWorkspace ? this.selectedId : null
					const activeTab = preserveWorkspace ? this.activeTab : 'components'
					this.editContext = response.context ?? {}
					this.forms = response.forms ?? {}
					this.applyFormDefaults()
					this.current = response.page
					const space = this.spaces.find(item => item.id === this.current.space_id)
					if (!preserveWorkspace || !space?.languages?.includes(this.previewLocale)) {
						this.previewLocale = space?.defaultLocale ?? 'fr'
					}
					this.baseline = JSON.stringify(this.current)
					this.selectedId = selectedId && findNode(this.current?.data, selectedId) ? selectedId : null
					this.activeTab = this.current.type === 2 ? activeTab : 'page'
					this.dragPayload = null
					if (!preserveWorkspace) {
						this.editorSession++
						this.previewTabs = {}
						this.previewAccordion = {}
					}
				},
				async loadHistory(pageId) {
					return application.api.get('/administrator/api/pages/history', { pageId })
				},
				async loadHistoryVersion(pageId, versionId) {
					return application.api.get('/administrator/api/pages/history/version', { pageId, versionId })
				},
				applyHistoryVersion(version) {
					if (!this.current?.canEdit || !version) return false

					// Remplacer uniquement les champs portés par la version : la
					// révision courante reste celle de la page pour que la sauvegarde
					// détecte toujours une modification concurrente.
					this.current = {
						...this.current,
						name: version.name ?? '',
						description: version.description ?? '',
						type: Number(version.type),
						raw_html: version.rawHtml ?? '',
						raw_scss: version.rawScss ?? '',
						data: version.data == null ? null : copy(version.data),
						access_edit_role_id: version.accessEditRoleId ?? null,
					}
					this.selectedId = null
					this.dragPayload = null
					this.previewTabs = {}
					this.previewAccordion = {}
					this.editorSession++
					return true
				},
				applyFormDefaults() {
                    for (const component of this.components) {
                        const form = this.forms[component.id]
                        component.defaults = { ...(form?.initialValues ?? {}), ...component.defaults }
                        if (form) component.editorFields = form.sections.flatMap(section => section.fields).filter(field => field.name).map(field => ({ ...field, localized: field.type === 'core.localized_text', type: field.type === 'core.number' ? 'number' : field.type }))
                    }
                    setPageComponentDefinitions(this.components)
                },
                async loadEditorContext(spaceId) {
                    const response = await application.api.get('/administrator/api/pages/editor-context', { spaceId })
                    this.editContext = response.context ?? {}
                    this.forms = response.forms ?? {}
                    this.applyFormDefaults()
                },
                async previewComponent(nodeId) {
                    return application.api.post('/administrator/api/pages/canvas', { data: {
                        page_id: this.current.id, space_id: this.current.space_id,
                        node_id: nodeId, locale: this.previewLocale, data: this.current.data,
                    } })
                },
                async startNew(spaceId) {
                    await this.loadEditorContext(spaceId)
					this.editorSession++
					this.previewTabs = {}
					this.previewAccordion = {}
					this.current = {
						id: null, space_id: spaceId, name: '', description: '', type: 2,
						raw_html: '', raw_scss: '',
						data: emptyDocument(), roles: this.spaces.find(space => space.id === spaceId)?.roles ?? [],
						revision: null, canEdit: true,
					}
					this.previewLocale = this.spaces.find(space => space.id === spaceId)?.defaultLocale ?? 'fr'
					this.baseline = ''
					this.selectedId = null
					this.activeTab = 'page'
				},
				setPageType(type) {
					if (!this.current?.canEdit) return
					this.current.type = Number(type)
					if (this.current.type === 2 && !this.current.data) this.current.data = emptyDocument()
					this.selectedId = null
					if (this.current.type === 1) this.activeTab = 'page'
				},
				selectNode(id, { showProperties = true } = {}) {
					if (!findNode(this.current?.data, id)) return
					this.selectedId = id
                    for (const { node } of collectNodes(this.current.data)) {
                        const zone = childZones(node).find(zone => zone.id === id || findNode({ items: zone.items }, id))
                        if (zone) this.activeZones[node.id] = zone.id
                    }
					if (showProperties) this.activeTab = 'properties'
				},
				beginDrag(payload) {
					if (this.current?.canEdit && this.current.type === 2) this.dragPayload = payload
				},
				endDrag() { this.dragPayload = null },
				canDrop(target) {
					return Boolean(this.current?.canEdit && this.current.type === 2
						&& placeNode(this.current.data, this.dragPayload, target, this.components))
				},
				drop(target) {
					if (!this.current?.canEdit || !this.dragPayload) return false
					const before = new Set(collectNodes(this.current.data).map(entry => entry.node.id))
					const next = placeNode(this.current.data, this.dragPayload, target, this.components)
					if (!next) return false
					const added = collectNodes(next).find(entry => !before.has(entry.node.id) && entry.node.type === this.dragPayload.type)
					this.current.data = next
					const showProperties = this.activeTab !== 'tree'
					if (added) this.selectNode(added.node.id, { showProperties })
					else if (this.dragPayload.kind === 'node') this.selectNode(this.dragPayload.id, { showProperties })
					this.dragPayload = null
					return true
				},
				addType(type) {
					if (!this.current?.canEdit || this.current.type !== 2) return false
					const displayedId = this.previewTabs[this.selectedId] ?? this.previewAccordion[this.selectedId]?.[0]
					const selectedId = findNode(this.current.data, displayedId) ? displayedId : this.selectedId
					const target = nearestInsertTarget(this.current.data, selectedId, type)
					this.beginDrag({ kind: 'catalog', type })
					const result = this.drop(target)
					this.endDrag()
					return result
				},
				moveNode(id, target) {
					if (!this.current?.canEdit || this.saving) return false
					this.beginDrag({ kind: 'node', id })
					const result = this.drop(target)
					this.endDrag()
					return result
				},
				moveSibling(id, delta) {
					const source = findNode(this.current?.data, id)
					if (!source || source.index + delta < 0 || source.index + delta >= source.siblings.length) return false
					return this.moveNode(id, {
						kind: source.kind === 'item' ? 'items' : 'zones', zoneName: source.zoneName, parentId: source.parentId,
						index: source.index + (delta > 0 ? 2 : -1),
					})
				},
                addTab(id) { return this.addZone(id, 'tabs') },
                addPanel(id) { return this.addZone(id, 'panels') },
                addZone(id, name) {
                    if (!this.current?.canEdit) return false
                    const next = copy(this.current.data)
                    const node = findNode(next, id)?.node
                    const definition = this.components.find(entry => entry.id === node?.type)?.zones?.[name]
                    const zones = node?.zones?.[name]
                    if (!definition || !zones || zones.length >= (definition.max ?? MAX_ZONE_BODIES)) return false
                    const zone = createZone(definition.defaults)
                    zones.push(zone)
                    if (!validTree(next)) return false
                    this.current.data = next
                    this.selectNode(zone.id)
                    return true
                },
				addColumn(rowId) {
					if (!this.current?.canEdit) return
					const next = copy(this.current.data)
					const row = findNode(next, rowId)?.node
					if (row?.type !== 'core.row' || row.zones.columns.length >= MAX_ZONE_BODIES) return
					const column = createColumn({ xs: 12, md: 6 })
					row.zones.columns.push(column)

					// Conserver aussi la limite globale lors de l'ajout d'une colonne.
					if (!validTree(next)) return
					this.current.data = next
					this.selectNode(column.id)
				},
				setNodeVisibility(id, visible) {
					if (!this.current?.canEdit || this.current.type !== 2 || this.saving || typeof visible !== 'boolean') return false
					const found = findNode(this.current.data, id)
					if (!found) return false

					const common = found.node.props.common ??= {}
					if (visible) {
						delete common.visible
						if (Object.keys(common).length === 0) delete found.node.props.common
					} else common.visible = false
					return true
				},
				canUseClipboard(id) {
					return Boolean(this.current?.canEdit && this.current.type === 2 && !this.saving && !this.pasting
						&& findNode(this.current.data, id))
				},
				async copyNode(id, clipboard = globalThis.navigator?.clipboard) {
					if (!this.canUseClipboard(id)) return false
					if (!clipboard?.writeText) throw new Error('Le presse-papiers système est indisponible. Ouvrez l’éditeur en HTTPS et autorisez son accès dans le navigateur.')
					const text = serializePageNode(this.current.data, id)
					try { await clipboard.writeText(text) }
					catch { throw new Error('Copie refusée par le navigateur. Autorisez l’accès au presse-papiers.') }
					return true
				},
				async pasteNode(id, mode, confirm, clipboard = globalThis.navigator?.clipboard) {
					if (!this.canUseClipboard(id)) return false
					if (!clipboard?.readText) throw new Error('Le presse-papiers système est indisponible. Ouvrez l’éditeur en HTTPS et autorisez son accès dans le navigateur.')
					const page = this.current
					const session = this.editorSession
					const snapshot = JSON.stringify(page)
					const unchanged = () => this.current === page && this.editorSession === session
						&& !this.saving && page.canEdit && page.type === 2 && JSON.stringify(page) === snapshot
					this.pasting = true
					try {
						// Lire dès le clic pour conserver l’activation utilisateur exigée par le navigateur.
						let text
						try { text = await clipboard.readText() }
						catch { throw new Error('Collage refusé par le navigateur. Autorisez la lecture du presse-papiers.') }
						if (!unchanged()) return false
						const payload = parsePageNode(text)
						const result = pastePageNode(page.data, id, payload, mode)
						if (!result) throw new Error('Collage impossible : cible incompatible ou limites du document dépassées.')
						// Tout remplacement exige un accord, même pour une feuille ou un corps vide.
						if (mode === 'replace') {
							if (typeof confirm !== 'function' || !await confirm('Remplacer cet élément et tout son contenu par la copie ?')) return false
						}
						if (!unchanged()) return false

						// Seul le document nettoyé et validé par le serveur peut entrer dans le canevas.
						const response = await application.api.post('/administrator/api/pages/validate-draft', {
							data: { page_id: page.id, space_id: page.space_id, data: result.document },
						})
						if (!unchanged()) return false
						if (!validTree(response.data) || !findNode(response.data, result.nodeId)) throw new Error('Réponse de validation du collage invalide.')
						this.current.data = response.data
						this.previewTabs = {}
						this.previewAccordion = {}
						this.selectNode(result.nodeId, { showProperties: false })
						return true
					} finally { this.pasting = false }
				},
				canDuplicateNode(id) {
					return Boolean(this.current?.canEdit && this.current.type === 2 && !this.saving
						&& canDuplicateNode(this.current.data, id))
				},
				duplicateNode(id) {
					if (!this.canDuplicateNode(id)) return false
					const result = duplicateNode(this.current.data, id)
					if (!result) return false

					// Une seule commande pour le menu et les boutons du panneau latéral.
					this.current.data = result.document
					this.selectNode(result.nodeId, { showProperties: false })
					return true
				},
				canRemoveNode(id) {
					if (!this.current?.canEdit || this.current.type !== 2 || this.saving) return false
					const found = findNode(this.current.data, id)
					return Boolean(found && (found.kind === 'item' || found.siblings.length > (zoneDefinition(this.current.data, found)?.min ?? 0)))
				},
				async requestRemoveNode(id, confirm, { confirmEmpty = true } = {}) {
					if (!this.canRemoveNode(id)) return false
					const page = this.current
					const session = this.editorSession
					const { node, kind } = findNode(page.data, id)
					const hasChildren = childZones(node).length > 0 || node.items?.length > 0

					// L’interface fournit la modale ; la commande porte la politique et les garde-fous.
					if (hasChildren || confirmEmpty) {
						const message = kind === 'tab' ? 'Supprimer cet onglet et tout son contenu ?'
							: kind === 'panel' ? 'Supprimer ce panneau et tout son contenu ?'
								: 'Supprimer cet élément et tout son contenu ?'
						if (typeof confirm !== 'function' || !await confirm(message)) return false
					}

					// Une confirmation différée ne doit agir ni sur une autre page, ni pendant sa sauvegarde.
					if (page !== this.current || session !== this.editorSession) return false
					return this.removeNode(id)
				},
				removeNode(id) {
					if (!this.canRemoveNode(id)) return false
					const next = copy(this.current.data)
					const found = findNode(next, id)
					found.siblings.splice(found.index, 1)
                    if (found.kind !== 'item') replaceNodeReference(findNode(next, found.parentId).node, id)
                    this.previewTabs = {}
                    this.previewAccordion = {}
					this.current.data = next
					this.selectedId = null
					return true
				},
				async updateDiagram(id, svg) {
					if (!this.current?.canEdit || this.current.type !== 2 || this.saving) return false
					const page = this.current
					const session = this.editorSession
					const snapshot = JSON.stringify(page)
					const document = copy(page.data)
					const found = findNode(document, id)
					if (found?.node.type !== 'core.diagram') return false
					found.node.props.svg = svg

					// Réutiliser le WF de page : droits sur le Space réel, SVG et poids du JSON complet.
					const response = await application.api.post('/administrator/api/pages/validate-draft', {
						data: { page_id: page.id, space_id: page.space_id, data: document },
					})
					if (this.current !== page || this.editorSession !== session || !page.canEdit || this.saving
						|| JSON.stringify(page) !== snapshot) return false
					if (!validTree(response.data) || findNode(response.data, id)?.node.type !== 'core.diagram') {
						throw new Error('Réponse de validation du diagramme invalide.')
					}
					this.current.data = response.data
					return true
				},
				async sanitizeAiHtml(html) {
					if (!this.current?.canEdit || this.current.type !== 2) throw new Error('Page IA non modifiable.')
					const response = await application.api.post('/administrator/api/pages/ai/sanitize-html', {
						data: { page_id: this.current.id, space_id: this.current.space_id, html },
					})
					return response.html
				},
				async validateAiDraft(document) {
					if (!this.current?.canEdit || this.current.type !== 2) throw new Error('Page IA non modifiable.')
					return await application.api.post('/administrator/api/pages/ai/validate-draft', {
						data: { page_id: this.current.id, space_id: this.current.space_id, data: document },
					})
				},
				applyAiDraft(document, selectedId) {
					if (!this.current?.canEdit || this.current.type !== 2 || this.saving) return false
					this.current.data = document
					if (selectedId && findNode(document, selectedId)) this.selectedId = selectedId
					else if (this.selectedId && !findNode(document, this.selectedId)) this.selectedId = null
					return true
				},
				async preview() {
					if (!this.current || this.previewing) return null
					this.previewing = true
					try {
						return await application.api.post('/administrator/api/pages/preview', {
							data: {
								page_id: this.current.id, space_id: this.current.space_id, type: this.current.type,
								locale: this.previewLocale,
								data: this.current.data, raw_html: this.current.raw_html, raw_scss: this.current.raw_scss,
							},
						})
					} finally { this.previewing = false }
				},
				async save() {
					if (!this.current || this.saving || !this.current.canEdit) return false
					this.saving = true
					try {
						const data = {
							space_id: this.current.space_id, name: this.current.name,
							description: this.current.description, type: Number(this.current.type),
							raw_html: this.current.raw_html, raw_scss: this.current.raw_scss,
							data: this.current.data,
							revision: this.current.revision,
						}
						const response = this.current.id == null
							? await application.api.post('/administrator/api/pages/create', { data })
							: await application.api.post('/administrator/api/pages/update', { pageId: this.current.id, data })
						await this.loadDirectory()
						await this.open(response.page.id, { preserveWorkspace: true })
						return true
				} finally { this.saving = false }
				},
				close() {
					this.current = null
					this.baseline = ''
					this.selectedId = null
					this.dragPayload = null
				},
			},
		})
	},
}
