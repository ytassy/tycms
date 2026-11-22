import { createApp } from 'vue'
import { applicationKey, createApplicationContext } from './context'
import { registerStores } from './stores'
import { registerMixin } from './mixin'
import { registerDirectives } from './directives'
import { registerGlobalComponents } from './registerGlobalComponents'
import { parseBootstrap } from './bootstrap'
import { createLanguageI18n } from './language'

const components = new Map()
let mountingEnabled = false

/** Extension bundles register trusted public components by logical ID. */
export function registerPublicPageComponent(id, component) {
	if (components.has(id)) throw new Error(`Composant de page déjà enregistré : ${id}`)
	components.set(id, component)
	if (mountingEnabled) mountPublicPageComponents()
}

export function mountPublicPageComponents() {
	// Les extensions terminent leurs enregistrements avant le premier montage.
	mountingEnabled = true
	for (const element of document.querySelectorAll('[data-page-vue]')) {
		if (element.dataset.pageMounted === '1') continue
		const component = components.get(element.dataset.pageVue)
		if (!component) continue
		try {
			const props = JSON.parse(element.dataset.pageProps ?? '{}')
			const app = createApp(component, props)
			const globalConfiguration = parseBootstrap(
				document.getElementById('app-bootstrap'),
				'global',
			)

			// Les composants de page sont des racines Vue distinctes.
			// Installer leur dictionnaire, leur contexte API et tous les registres communs.
			app.use(createLanguageI18n(globalConfiguration))
			const application = createApplicationContext(globalConfiguration)
			registerStores(app, application)
			app.provide(applicationKey, application)
			registerDirectives(app, application)
			registerMixin(app, application)
			registerGlobalComponents(app)
			app.mount(element)
			element.dataset.pageMounted = '1'
		} catch (error) {
			console.error('Composant de page invalide', error)
		}
	}
}
