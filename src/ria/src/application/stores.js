import { createPinia } from 'pinia'
import { createExtensionStores } from './extensionRegistry.js'
import { useAuthenticationStore } from '../stores/authentication'
import { useMyAccountStore } from '../stores/my_account'
import { useSpaceSwitchStore } from '../stores/space_switch'
import { useAdminJobsStore } from '../stores/admin_jobs'
import { useAdminLogsStore } from '../stores/admin_logs'
import { useAdminSystemUpdatesStore } from '../stores/admin_system_updates'
import { useAdminFontsStore } from '../stores/admin_fonts'
import { useAdminUserAssignmentsStore } from '../stores/admin_user_assignments'
import { useAppStore } from '../stores/app'
import { useAdminExtensionsStore } from '../stores/admin_extensions'
import { useAdminExtensionSourcesStore } from '../stores/admin_extension_sources'
import { useAdminMenusStore } from '../stores/admin_menus'
import { useAdminPagesStore } from '../stores/admin_pages'
import { useAdminLanguageKeysStore } from '../stores/admin_language_keys'
import { useAdminMediasStore } from '../stores/admin_medias'
import { useAdminSpacesStore } from '../stores/admin_spaces'
import { useAdminSsoProvidersStore } from '../stores/admin_sso_providers'
import { useAdminServiceAccountsStore } from '../stores/admin_service_accounts'
import { useAdminTyCatalogStore } from '../stores/admin_ty_catalog'
import { useAdminAiStore } from '../stores/admin_ai'
import { useAdminEmailTemplatesStore } from '../stores/admin_email_templates'
import { useAiChatStore } from '../stores/ai_chat'
import { useExampleStore } from '../stores/example.js'

export function registerStores(vueApp, application, extraStores = {}) {
	const pinia = createPinia()
	application.stores = {
        useAuthenticationStore: useAuthenticationStore.get(vueApp, application),
        useMyAccountStore: useMyAccountStore.get(vueApp, application),
		useSpaceSwitchStore: useSpaceSwitchStore.get(vueApp, application),
		useAdminSystemUpdatesStore: useAdminSystemUpdatesStore.get(vueApp, application),
		useAdminJobsStore: useAdminJobsStore.get(vueApp, application),
		useAdminLogsStore: useAdminLogsStore.get(vueApp, application),
		useAdminFontsStore: useAdminFontsStore.get(vueApp, application),
		useAdminUserAssignmentsStore: useAdminUserAssignmentsStore.get(vueApp, application),
		useAppStore: useAppStore.get(vueApp, application),
		useAdminExtensionsStore: useAdminExtensionsStore.get(vueApp, application),
		useAdminExtensionSourcesStore: useAdminExtensionSourcesStore.get(vueApp, application),
		useAdminMenusStore: useAdminMenusStore.get(vueApp, application),
		useAdminPagesStore: useAdminPagesStore.get(vueApp, application),
		useAdminLanguageKeysStore: useAdminLanguageKeysStore.get(vueApp, application),
		useAdminMediasStore: useAdminMediasStore.get(vueApp, application),
		useAdminSpacesStore: useAdminSpacesStore.get(vueApp, application),
		useAdminSsoProvidersStore: useAdminSsoProvidersStore.get(vueApp, application),
		useAdminServiceAccountsStore: useAdminServiceAccountsStore.get(vueApp, application),
		useAdminTyCatalogStore: useAdminTyCatalogStore.get(vueApp, application),
		useAdminAiStore: useAdminAiStore.get(vueApp, application),
		useAdminEmailTemplatesStore: useAdminEmailTemplatesStore.get(vueApp, application),
		useAiChatStore: useAiChatStore.get(vueApp, application),
		useExampleStore: useExampleStore.get(vueApp, application),
		...extraStores,
	}
	// Les factories d’extension reçoivent le même contexte, sans remplacer les stores Core.
	Object.assign(application.stores, createExtensionStores(vueApp, application, Object.keys(application.stores)))
	vueApp.use(pinia)
	return pinia
}

export default {
	registerStores,
}
