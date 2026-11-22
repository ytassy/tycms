import { inject } from 'vue'
import Api from './api'
import Acl from './acl'
import { PubSub } from './pubsub'
import { initAndGetTools } from './tools'

export const applicationKey = Symbol('application')

export function createApplicationContext(globalConfiguration = {}) {
	const application = {}

	application.conf = {
		getSpace: () => globalConfiguration.space ?? null,
		getTemplateColors: () => globalConfiguration.templateColors ?? [],
		getTimezones: () => globalConfiguration.timezones ?? {},
		getLanguage: () => globalConfiguration.language ?? null,
		getUser: () => globalConfiguration.user ?? null,
		getAcl: () => globalConfiguration.acl ?? null,
		getExtensions: () => globalConfiguration.extensions ?? [],
		getCsrfToken: () => globalConfiguration.csrfToken ?? null,
	}
	application.stores = {}
	application.pubsub = new PubSub()
	application.tools = initAndGetTools(application)
	application.api = Api.getApi(application)
	application.acl = Acl.getAcl(application)

	return application
}

export function useApplication() {
	const application = inject(applicationKey)

	if (application === undefined) {
		throw new Error('Le contexte de l’application n’est pas disponible.')
	}

	return application
}
