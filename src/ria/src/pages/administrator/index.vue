<template>
    <div class="administrator-page d-flex" :class="{ 'administrator-page--editor': editorOpen && page === 'pages' }">
        <aside v-if="!(editorOpen && page === 'pages')" class="administrator-menu flex-shrink-0">
			<section v-for="menu in menus" :key="menu.title" class="administrator-menu-group">
				<div class="administrator-menu-title">{{ menu.title }}</div>
				<router-link v-for="item in menu.items" :key="item.page" class="menu-item"
					:class="{active: item.page === page}"
					:to="{ name: 'admin-page', params: { page: item.page } }">
					<i :class="item.icon"></i>
					<span class="ms-1" >{{ item.label }}</span>
				</router-link>
			</section>
        </aside>

        <div class="administrator-content flex-grow-1" :class="{ 'administrator-content--editor': editorOpen && page === 'pages' }">
            
			<dashboard v-if="page == 'dashboard'" :groups="dashboardGroups" :can-check-updates="availablePages.includes('info_system')" />
			<logs v-if="page == 'logs'"></logs>
			<info_system v-if="page == 'info_system'"></info_system>
			<extensions v-if="page == 'extensions'"></extensions>
			<ty_catalog v-if="page == 'ty_catalog'"></ty_catalog>
			<automation v-if="page == 'automation'"></automation>
			<users v-if="page == 'users'"></users>
			<user_assignments v-if="page == 'user_assignments'"></user_assignments>
			<user_groups v-if="page == 'user_groups'"></user_groups>
			<roles v-if="page == 'roles'"></roles>
			<spaces v-if="page == 'spaces'"></spaces>
			<languages v-if="page == 'languages'"></languages>
			<email_templates v-if="page == 'email_templates'" ref="emailTemplatesPage"></email_templates>
			<admin_ai v-if="page == 'ai'" ref="aiPage"></admin_ai>
			<sso_providers v-if="page == 'sso_providers'"></sso_providers>
			<pages v-if="page == 'pages'" ref="pagesPage" @workspace-change="editorOpen = $event"></pages>
			<menus v-if="page == 'menus'"></menus>
			<medias v-if="page == 'medias'"></medias>
			<fonts v-if="page == 'fonts'"></fonts>
        </div>
    </div>
</template>

<script>

import dashboard from '../../components/administrator/dashboard/index.vue'
import info_system from '../../components/administrator/infos_system/index.vue'
import logs from '../../components/administrator/logs/index.vue'
import users from '../../components/administrator/users/index.vue'
import user_assignments from '../../components/administrator/user_assignments/index.vue'
import user_groups from '../../components/administrator/user_groups/index.vue'
import roles from '../../components/administrator/roles/index.vue'
import spaces from '../../components/administrator/spaces/index.vue'
import languages from '../../components/administrator/languages/index.vue'
import email_templates from '../../components/administrator/email_templates/index.vue'
import sso_providers from '../../components/administrator/sso_providers/index.vue'
import admin_ai from '../../components/administrator/ai/index.vue'
import pages from '../../components/administrator/pages/index.vue'
import menus from '../../components/administrator/menus/index.vue'
import medias from '../../components/administrator/medias/index.vue'
import fonts from '../../components/administrator/fonts/index.vue'
import extensions from '../../components/administrator/extensions/index.vue'
import ty_catalog from '../../components/administrator/ty_catalog/index.vue'
import automation from '../../components/administrator/automation/index.vue'

export default {

	components: { logs, dashboard, fonts, info_system, users, user_assignments, user_groups, roles, spaces, languages, email_templates, sso_providers, admin_ai, pages, menus, medias, extensions, ty_catalog, automation },

	props: {
		id: { type: [String, Number], default: null },
		canEditExtensions: { type: Boolean, default: false },
	},

	data() {

		return {
			editorOpen: false,
		}
	},

	computed: {
		page() {
			const page = this.$route.name === 'admin-page' ? this.$route.params.page : ''
			return this.availablePages.includes(page) ? page : ''
		},
		menus() {
			return [
				{
					title: 'Administration du site',
					items: [
						{ page: 'dashboard', label: 'Tableau de bord', icon: 'fa-solid fa-table-cells-large' },
						{ page: 'info_system', label: 'Système', icon: 'fa-solid fa-gears', permission: 'ROOT' },
						{ page: 'logs', label: 'Journaux', icon: 'fa-solid fa-file-lines', permission: 'ROOT' },
						{ page: 'extensions', label: 'Extensions', icon: 'fa-solid fa-plug', permission: 'EXTENSION_EDIT' },
						{ page: 'ty_catalog', label: 'Catalogues TyCMS', icon: 'fa-solid fa-book-open', permission: 'plg_ty_catalog__VIEW' },
						{ page: 'automation', label: 'Automatisations', icon: 'fa-solid fa-diagram-project', permission: 'plg_automation__VIEW' },
						{ page: 'spaces', label: 'Espaces', icon: 'fa-solid fa-building', permission: 'SPACE_EDIT' },
						{ page: 'languages', label: 'Traductions', icon: 'fa-solid fa-language', permission: 'LANG_OVERRIDE_CRUD' },
						{ page: 'email_templates', label: 'Modèles d’e-mails', icon: 'fa-solid fa-envelope-open-text', permission: 'EMAIL_TEMPLATE_EDIT' },
						{ page: 'ai', label: 'Assistance IA', icon: 'fa-solid fa-wand-magic-sparkles', permission: 'SPACE_ADMIN' },
						{ page: 'sso_providers', label: 'Fournisseurs SSO', icon: 'fa-solid fa-right-to-bracket', permission: 'SSO_CRUD' },
						{ page: 'menus', label: 'Menus', icon: 'fa-solid fa-bars', permission: 'MENU_READ' },
						{ page: 'pages', label: 'Pages', icon: 'fa-solid fa-file-lines', permission: 'PAGE_READ' },
						{ page: 'fonts', label: 'Polices', icon: 'fa-solid fa-font', permission: 'FONT_ADD' },
						{ page: 'medias', label: 'Médias', icon: 'fa-solid fa-images', permission: 'MEDIA_READ' },
					].filter(item => item.page === 'dashboard' || (item.page === 'logs'
						? this.acl.isRoot()
						: item.page === 'automation'
							? this.hasAutomationPermission
							: item.page === 'ty_catalog'
								? this.hasTyCatalogPermission
								: this.acl.hasGlobalPermission(item.permission))
						|| (item.page === 'fonts' && this.hasFontPermission)
						|| (item.page === 'extensions' && this.canEditExtensions)
						|| (item.page === 'spaces' && this.acl.hasGlobalPermission('SPACE_ADMIN'))
						|| (item.page === 'languages' && this.hasLanguagePermission)
						|| (item.page === 'email_templates' && this.hasEmailTemplatePermission)
						|| (item.page === 'medias' && this.acl.hasGlobalPermission('MEDIA_CRUD'))
						|| (item.page === 'sso_providers' && this.hasSsoPermission)
						|| (item.page === 'ai' && this.hasAiAdminPermission)),
				},
				{
					title: 'Utilisateurs et ACLs',
					items: [
						{ page: 'roles', label: 'Rôles', icon: 'fa-solid fa-user-shield', permission: 'ROLE_READ' },
						{ page: 'users', label: 'Utilisateurs', icon: 'fa-solid fa-users', permission: 'USERS_READ' },
						{ page: 'user_assignments', label: 'Attributions utilisateurs', icon: 'fa-solid fa-table-cells', permission: 'USERS_READ' },
						{ page: 'user_groups', label: 'Groupes utilisateurs', icon: 'fa-solid fa-user-group', permission: 'USER_GROUPS_READ' },
					].filter(item => this.acl.hasGlobalPermission(item.permission)
						|| (item.page === 'roles' && this.hasRoleDirectoryPermission)
						|| (item.page === 'users' && this.hasUserDirectoryPermission)
						|| (item.page === 'user_assignments' && this.hasUserAssignmentsPermission)
						|| (item.page === 'user_groups' && this.hasGroupDirectoryPermission)),
				},
			].filter(menu => menu.items.length)
		},
		availablePages() { return this.menus.flatMap(menu => menu.items.map(item => item.page)) },
		dashboardGroups() {
			return this.menus.map(menu => ({ ...menu, items: menu.items.filter(item => item.page !== 'dashboard') }))
				.filter(menu => menu.items.length)
		},
		hasFontPermission() {
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}
			return this.acl.hasGlobalPermission('FONT_MANAGE', 1)
				|| Object.keys(parentIds).some(id => this.acl.hasGlobalPermission('FONT_ADD', Number(id)))
		},
		hasSsoPermission() {
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}
			return Object.keys(parentIds).some(id => this.acl.hasGlobalPermission('SSO_CRUD', Number(id)))
		},
		hasRoleDirectoryPermission() {
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}
			return Object.keys(parentIds).some(id => this.acl.hasGlobalPermission('ROLE_READ', Number(id))
				|| this.acl.hasGlobalPermission('ROLE_CRUD', Number(id)))
		},
		hasUserDirectoryPermission() {
			const permissions = ['USERS_READ', 'USERS_CRUD', 'USERS_ADMIN', 'USER_ROLES_READ', 'USER_ROLES_CRUD', 'USER_GROUPS_ASSIGN']
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}
			return Object.keys(parentIds).some(id => permissions.some(permission =>
				this.acl.hasGlobalPermission(permission, Number(id))))
		},
		hasUserAssignmentsPermission() {
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}
			return Object.keys(parentIds).some(id => this.acl.hasGlobalPermission('USERS_READ', Number(id)))
		},
		hasGroupDirectoryPermission() {
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}
			return Object.keys(parentIds).some(id => this.acl.hasGlobalPermission('USER_GROUPS_READ', Number(id))
				|| this.acl.hasGlobalPermission('USER_GROUPS_CRUD', Number(id)))
		},
		hasLanguagePermission() {
			// Le droit peut être accordé sur un Space enfant sans exister à la racine.
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}

			return Object.keys(parentIds).some(id => this.acl.hasGlobalPermission('LANG_OVERRIDE_CRUD', Number(id)))
		},
		hasEmailTemplatePermission() {
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}
			return Object.keys(parentIds).some(id => this.acl.hasGlobalPermission('EMAIL_TEMPLATE_EDIT', Number(id)))
		},
		hasAiAdminPermission() {
			const parentIds = this.conf.getAcl()?.space_parent_ids ?? {}
			return Object.keys(parentIds).some(id => this.acl.hasGlobalPermission('SPACE_ADMIN', Number(id)))
		},
		hasAutomationPermission() {
			// Une extension désactivée ne reçoit ni contrôleur ni route.
			return this.conf.getExtensions().includes('plg_automation')
				&& this.acl.hasGlobalPermission('plg_automation__VIEW')
		},
		hasTyCatalogPermission() {
			// Le serveur de catalogues peut être désactivé par configuration locale.
			return this.conf.getExtensions().includes('plg_ty_catalog')
				&& this.acl.hasGlobalPermission('plg_ty_catalog__VIEW')
		},
	},
	watch: {
		availablePages() { this.ensureAvailableRoute() },
	},

	created() {
		// Les retours du navigateur passent par les mêmes confirmations que le menu.
		this.removeAdminGuard = this.$router.beforeEach(async (to, from) => {
			const target = to.name === 'admin-page' ? to.params.page : ''

			// Conserver les anciens liens vers l'écran de mise à jour, désormais
			// fusionné dans l'onglet Mises à jour de l'écran Système.
			if (target === 'system_updates') {
				return { name: 'admin-page', params: { page: 'info_system' }, replace: true }
			}

			if (!this.availablePages.includes(target)) {
				const fallback = this.defaultPage()
				if (fallback) return { name: 'admin-page', params: { page: fallback }, replace: true }
				if (to.name !== 'admin-page' || target) return { name: 'admin-page', replace: true }
			}

			if (from.params.page === target) return true
			if (from.params.page === 'ai' && this.$refs.aiPage && !await this.$refs.aiPage.canLeave()) return false
			if (from.params.page === 'email_templates' && this.$refs.emailTemplatesPage && !await this.$refs.emailTemplatesPage.canLeave()) return false
			if (from.params.page === 'pages' && this.$refs.pagesPage && !await this.$refs.pagesPage.canLeave()) return false
			return true
		})

		this.removeAdminAfterEach = this.$router.afterEach((to, from, failure) => {
			if (failure) return
			// Ne fermer le document qu'après une navigation effectivement acceptée.
			if (from.params.page === 'pages' && to.params.page !== 'pages') this.stores.useAdminPagesStore().close()
		})
	},

	async mounted() {
		await this.$router.isReady()
		this.ensureAvailableRoute()
	},

	beforeUnmount() {
		this.removeAdminGuard?.()
		this.removeAdminAfterEach?.()
	},

	methods: {
		defaultPage() {
			return this.availablePages[0] ?? ''
		},
		ensureAvailableRoute() {
			if (this.page || (this.$route.name === 'admin-page' && !this.$route.params.page && !this.availablePages.length)) return
			this.$router.replace({ name: 'admin-page', params: { page: this.defaultPage() || undefined } })
		},
	},

}
</script>


