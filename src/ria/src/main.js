import { createApp, createStaticVNode, defineAsyncComponent, h } from 'vue'
import { resolveRiaPage } from './application/extensionRegistry.js'
import { createRouter, createWebHashHistory } from 'vue-router'
import App from './App.vue'
import { applicationKey, createApplicationContext } from './application/context'
import { parseBootstrap } from './application/bootstrap'
import { createLanguageI18n } from './application/language'
import { registerStores } from './application/stores'
import { registerDirectives } from './application/directives'
import { registerGlobalComponents } from './application/registerGlobalComponents'
import { registerMixin } from './application/mixin'
import { mountPublicPageComponents, registerPublicPageComponent } from './application/publicPageRegistry'
import { mountTestimonials } from './components/features/testimonials/testimonialsCarousel.js'
import './scss/main.scss'
import { loadPageComponentPlugins } from './components/administrator/pages/pluginEntries.js'
import { mountMermaidComponents } from './components/common/mermaid/mount.js'
import { installPageImageLightbox } from './components/features/imageLightbox/imageLightbox'
import { mountSecureVideos, unmountSecureVideos } from './components/common/secureVideoMount.js'

await loadPageComponentPlugins()

const roots = document.querySelectorAll('[data-vue-app]')

document.onkeydown = function(e) {
	if (e.ctrlKey && e.key.toLowerCase() === 's') {
		e.preventDefault()
		document.querySelector('.ctrl-s')?.click()
		return
	}
	if (e.key === 'Escape') {
		// A confirmation can sit above an editor: only act on the front window.
		if (document.querySelector('.popover.show')) return
		const windows = [...document.querySelectorAll('.modal-backdrop, .page-preview-backdrop')]
		const front = windows.reduce((top, window) =>
			!top || Number(getComputedStyle(window).zIndex) >= Number(getComputedStyle(top).zIndex) ? window : top, null)
		const scope = front ?? document
		const openMenu = [...scope.querySelectorAll('.dropdown-menu.show')].at(-1)
		const menuButton = openMenu?.closest('.dropdown')?.querySelector(':scope > button.esc')
		if (openMenu && !menuButton) return
		const buttons = [...scope.querySelectorAll('.esc')]
		const button = (menuButton && !menuButton.disabled ? menuButton : null)
			?? buttons.reverse().find(element => !element.disabled && element.getClientRects().length)
		if (button) {
			button.click()
			return false
		}
	}
};

if (roots.length > 0) {
	const globalConfiguration = parseBootstrap(
		document.getElementById('app-bootstrap'),
		'global',
	)
	const pageModules = import.meta.glob('./pages/**/*.vue')

	roots.forEach((root) => {
		const bootstrapId = root.dataset.bootstrapId ?? null
		let pageName = root.dataset.component;
		
		if (pageName == null || pageName == '') pageName = 'empty';

		const extensionPage = resolveRiaPage(pageName)
		const loadPage = pageModules[`./pages/${pageName}.vue`]

		if (!loadPage && !extensionPage) {
			throw new Error(`Composant Vue introuvable : ${pageName}`)
		}

		let pageProps = {};

		if (bootstrapId != null) pageProps = parseBootstrap(
			document.getElementById(`app-bootstrap-${bootstrapId}`),
			`props de ${pageName}`,
		)
		
		// Capturer le HTML serveur avant que le montage ne vide la racine.
		// Il reste statique : aucune expression Vue issue du HTML n'est exécutée.
		const initialHtml = root.innerHTML
		const initialNodeCount = root.childNodes.length
		const pageComponent = loadPage ? defineAsyncComponent(loadPage) : extensionPage
		const app = createApp({
			beforeUnmount() {
				// Libérer les lecteurs montés dans le HTML avant son retrait du DOM.
				unmountSecureVideos(root)
			},
			render: () => h(App, { pageComponent, pageProps }, {
				default: () => initialNodeCount > 0
					? createStaticVNode(initialHtml, initialNodeCount)
					: [],
			}),
		})
		// Le routage par fragment ne concerne que la racine d'administration.
		if (pageName === 'administrator/index') {
			// Le routeur ne rend aucun composant : App choisit l'écran à partir du
			// fragment. Le composant vide satisfait Vue Router sans ajouter de rendu.
			const routePlaceholder = { render: () => null }
			app.use(createRouter({
				history: createWebHashHistory(),
				routes: [
					{ path: '/:page?', name: 'admin-page', component: routePlaceholder },
					{ path: '/:pathMatch(.*)*', name: 'admin-unknown', component: routePlaceholder },
				],
			}))
		}

		const application = createApplicationContext(globalConfiguration)
		app.use(createLanguageI18n(globalConfiguration))
		registerStores(app, application)

		app.provide(applicationKey, application)
		registerDirectives(app, application)
		registerMixin(app, application)
		registerGlobalComponents(app)
		app.mount(root)
	})
}

// Activer seulement les balises sec_video avec leurs deux props statiques.
// Le HTML PHP fonctionne aussi sur les pages sans racine applicative Vue.
mountSecureVideos()

registerPublicPageComponent('core/my-account', defineAsyncComponent(() => import('./components/page/MyAccount.vue')))
registerPublicPageComponent('core/authentication', defineAsyncComponent(() => import('./components/page/Authentication.vue')))
registerPublicPageComponent('core/space-switch', defineAsyncComponent(() => import('./components/page/SpaceSwitch.vue')))
registerPublicPageComponent('core/audio', defineAsyncComponent(() => import('./components/page/AudioPlayer.vue')))
registerPublicPageComponent('core/automation-button', defineAsyncComponent(() => import('./components/page/AutomationButton.vue')))
mountPublicPageComponents()
mountMermaidComponents()
installPageImageLightbox()
mountTestimonials()
