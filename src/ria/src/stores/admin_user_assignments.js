import { defineStore } from 'pinia'
import { assignmentBlockReason } from '../components/administrator/user_assignments/assignmentInteraction.js'

const endpoint = '/administrator/api/user-assignments'
const validId = value => Number.isSafeInteger(value) && value > 0
const validIds = values => Array.isArray(values) && values.every(validId)

export const useAdminUserAssignmentsStore = {
    get(vueApp, application) {
        return defineStore('admin_user_assignments', {
            state: () => ({
                spaces: [], spaceId: null, roles: [], groups: [], users: [],
                page: 1, perPage: 20, search: '', total: 0,
                initializing: false, loading: false, saving: null, error: '', requestId: 0,
            }),
            getters: {
                pageCount: state => Math.max(1, Math.ceil(state.total / state.perPage)),
                selectedSpace: state => state.spaces.find(space => space.id === state.spaceId) ?? null,
            },
            actions: {
                clearPage() {
                    this.users = []
                    this.roles = []
                    this.groups = []
                    this.total = 0
                },
                cancelReads() {
                    // Une réponse d'un écran quitté ne doit pas réinstaller ses anciennes données.
                    this.requestId++
                    this.loading = false
                    this.initializing = false
                },
                async initialize(preferredSpaceId = null) {
                    const requestId = ++this.requestId
                    this.initializing = true
                    this.loading = false
                    this.error = ''
                    this.clearPage()
                    try {
                        const result = await application.api.get(`${endpoint}/directory`)
                        if (requestId !== this.requestId) return false
                        if (!Array.isArray(result?.spaces) || !result.spaces.every(space => validId(space.id)
                            && typeof space.title === 'string' && typeof space.can_assign_roles === 'boolean'
                            && typeof space.can_assign_groups === 'boolean')) {
                            throw new Error('Le serveur n’a pas fourni un répertoire d’espaces valide.')
                        }
                        this.spaces = result.spaces
                        const candidates = [this.spaceId, preferredSpaceId, this.spaces[0]?.id]
                        const nextSpaceId = candidates.find(id => this.spaces.some(space => space.id === id)) ?? null
                        if (nextSpaceId !== this.spaceId) this.page = 1
                        this.spaceId = nextSpaceId
                    } catch (error) {
                        if (requestId === this.requestId) {
                            this.spaces = []
                            this.spaceId = null
                            this.error = error?.message ?? 'Impossible de charger les espaces autorisés.'
                        }
                        return false
                    } finally {
                        if (requestId === this.requestId) this.initializing = false
                    }
                    return this.spaceId !== null ? this.loadPage() : true
                },
                async loadPage() {
                    if (this.spaceId === null) return false
                    const requestId = ++this.requestId
                    const query = { spaceId: this.spaceId, page: this.page, perPage: this.perPage, search: this.search }
                    this.loading = true
                    this.error = ''
                    this.clearPage()
                    try {
                        const result = await application.api.get(endpoint, query)
                        if (requestId !== this.requestId) return false
                        if (result?.space_id !== query.spaceId || result.page !== query.page || result.per_page !== query.perPage
                            || !Number.isSafeInteger(result.total) || result.total < 0
                            || !['roles', 'groups', 'users'].every(key => Array.isArray(result[key]))
                            || ![...result.roles, ...result.groups].every(item => validId(item.id) && typeof item.name === 'string')
                            || !result.users.every(user => validId(user.id) && validId(user.space_id)
                                && typeof user.first_name === 'string' && typeof user.last_name === 'string' && typeof user.email === 'string'
                                && typeof user.can_assign_roles === 'boolean' && typeof user.can_assign_groups === 'boolean'
                                && validIds(user.role_ids) && validIds(user.group_ids) && validIds(user.protected_group_ids))) {
                            throw new Error('Le serveur n’a pas fourni un tableau d’attributions valide.')
                        }

                        // Une suppression externe peut avoir rendu la dernière page vide.
                        this.total = result.total
                        if (this.page > this.pageCount) {
                            this.page = this.pageCount
                            return await this.loadPage()
                        }
                        this.roles = result.roles
                        this.groups = result.groups
                        this.users = result.users
                        return true
                    } catch (error) {
                        if (requestId === this.requestId) this.error = error?.message ?? 'Impossible de charger les attributions.'
                        return false
                    } finally {
                        if (requestId === this.requestId) this.loading = false
                    }
                },
                async setFilters({ spaceId = this.spaceId, search = this.search, perPage = this.perPage } = {}) {
                    if (this.saving || this.initializing || !this.spaces.some(space => space.id === spaceId)) return false
                    this.spaceId = spaceId
                    this.search = search.trim()
                    this.perPage = perPage
                    this.page = 1
                    return this.loadPage()
                },
                async goPage(page) {
                    if (this.saving || this.initializing || !Number.isInteger(page) || page < 1 || page > this.pageCount) return false
                    this.page = page
                    return this.loadPage()
                },
                async toggle(userId, kind, itemId) {
                    if (this.loading || this.initializing || this.saving || !['role', 'group'].includes(kind)) return false
                    const user = this.users.find(item => item.id === userId)
                    const catalog = kind === 'role' ? this.roles : this.groups
                    const column = { kind, id: itemId }
                    if (!user || !catalog.some(item => item.id === itemId) || assignmentBlockReason(user, column)) return false

                    const field = kind === 'role' ? 'role_ids' : 'group_ids'
                    const before = user[field].includes(itemId)
                    const data = { user_id: userId, space_id: this.spaceId, kind, item_id: itemId, before, assigned: !before }
                    const requestId = this.requestId
                    this.saving = { userId, kind, itemId }
                    this.error = ''
                    try {
                        const result = await application.api.post(`${endpoint}/save`, { data: { ...data } })
                        if (!result || ['user_id', 'space_id', 'kind', 'item_id', 'assigned'].some(key => result[key] !== data[key])) {
                            throw new Error('Le serveur n’a pas confirmé cette modification.')
                        }

                        // La case change seulement après confirmation de l'écriture.
                        user[field] = data.assigned ? [...user[field], itemId] : user[field].filter(id => id !== itemId)
                        // Un changement de ses propres droits impose de recharger les capacités.
                        if (userId === application.conf.getUser()?.id || requestId !== this.requestId) {
                            await this.initialize(this.spaceId)
                        }
                        return true
                    } catch (error) {
                        // Après un refus ou une coupure réseau, relire l'état réel avant tout autre clic.
                        const message = error?.message ?? 'Impossible de modifier cette attribution.'
                        await this.initialize(this.spaceId)
                        this.error = [message, this.error].filter(Boolean).join(' ')
                        return false
                    } finally {
                        this.saving = null
                    }
                },
            },
        })
    },
}
