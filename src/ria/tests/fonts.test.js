import assert from 'node:assert/strict'
import test from 'node:test'
import { createPinia, setActivePinia } from 'pinia'
import axios from 'axios'
import { useAdminFontsStore } from '../src/stores/admin_fonts.js'
import apiFactory from '../src/application/api.js'
import { FONT_PREVIEW_SAMPLE, localFontStylesheet, matchingFonts, loadFontPreview } from '../src/components/common/forms/fields/fontPreview.js'

import { googleFontPreview, createCatalogPreview } from '../src/components/administrator/fonts/catalogPreview.js'

const font = { id: 'google_montserrat', verified: true, version: 'version-test', enabled: true }
function createStore(postResponse, installed = {}, library = null) {
	setActivePinia(createPinia())
	const app = { api: {
		post: async () => postResponse,
		get: async () => library ?? ({ families: [], installed, canManage: false, spaceIds: [7] }),
	} }
	return useAdminFontsStore.get(null, app)()
}

test('un HTTP 200 contenant un warning PHP ne valide jamais une installation', async () => {
	const store = createStore('Warning: fopen(): Permission denied\n{"error":false}')
	assert.equal(await store.mutate('install', { id: font.id, spaceId: 7 }), false)
	assert.match(store.error, /pas confirmé/)
})

test('le succès exige une vérification serveur et un registre rechargé concordant', async () => {
	for (const response of [{}, { font: { ...font, verified: false } }, { font: { ...font, id: 'autre' } }]) {
		assert.equal(await createStore(response).mutate('install', { id: font.id, spaceId: 7 }), false)
	}
	assert.equal(await createStore({ font }).mutate('install', { id: font.id, spaceId: 7 }), false)
	assert.equal(await createStore({ font }, { [font.id]: font }).mutate('install', { id: font.id, spaceId: 7 }), true)
})

test('un catalogue malformé ou une activation non enregistrée produit une erreur', async () => {
	const malformed = createStore({ font }, {}, '<html>Erreur serveur</html>')
	assert.equal(await malformed.mutate('install', { id: font.id, spaceId: 7 }), false)
	assert.match(malformed.error, /Réponse invalide/)
	assert.equal(await createStore({ updated: true }, { [font.id]: font }).mutate('enabled', { id: font.id, enabled: false }), false)
})

test('la suppression exige que le registre ne référence plus la police', async () => {
	assert.equal(await createStore({ deleted: true }, { [font.id]: font }).mutate('delete', { id: font.id }), false)
	assert.equal(await createStore({ deleted: true }).mutate('delete', { id: font.id }), true)
})

test('une erreur GET du service API rejette la promesse au lieu de bloquer le chargement', async () => {
	const original = axios.get
	const api = apiFactory.getApi({
		conf: { getCsrfToken: () => '' },
		stores: { useAppStore: () => ({ nextApiUi: null, showModaleError() {} }) },
	})
	try {
		axios.get = async () => ({ data: { error: true, message: 'Droits insuffisants' } })
		await assert.rejects(api.get('/administrator/api/fonts'), /Droits insuffisants/)
		axios.get = async () => { throw new Error('Serveur inaccessible') }
		await assert.rejects(api.get('/administrator/api/fonts'), /Serveur inaccessible/)
	} finally { axios.get = original }
})


test('le champ filtre les familles actives et conserve uniquement le choix désactivé courant', () => {
	const choices = [
		{ value: 'a', label: 'Montserrat' },
		{ value: 'b', label: 'Nunito' },
		{ value: 'c', label: 'Lora', disabled: true },
	]
	assert.deepEqual(matchingFonts(choices, ' NUNI ', 'a').map(font => font.value), ['a', 'b'])
	assert.deepEqual(matchingFonts(choices, '', 'a').map(font => font.value), ['a', 'b'])
	assert.deepEqual(matchingFonts(choices, 'mont', 'c').map(font => font.value), ['a', 'c'])
})

test('les aperçus refusent les feuilles distantes et les chemins hors de la bibliothèque', () => {
	const path = '/assets/fonts/google_montserrat/' + 'a'.repeat(24) + '/font.css'
	assert.equal(localFontStylesheet(path), path)
	for (const url of ['https://fonts.googleapis.com/css2?family=Roboto', '//evil.test/font.css', '/assets/fonts/../evil.css', path + '?redirect=1']) {
		assert.throws(() => localFontStylesheet(url), /bibliothèque locale/)
	}
})

test('les aperçus partagent le CSS local et signalent un fichier de police indisponible', async () => {
	const previous = globalThis.document
	let stylesheetLoads = 0
	let binaryLoads = 0
	globalThis.document = {
		createElement: () => ({ remove() {} }),
		head: { append(link) { stylesheetLoads++; queueMicrotask(() => link.onload()) } },
		fonts: { async load() { binaryLoads++; return [{}] } },
	}
	try {
		const option = { data: { fontName: 'Test Font', fontCssUrl: '/assets/fonts/google_test/' + 'b'.repeat(24) + '/font.css' } }
		await loadFontPreview(option, 'Texte')
		await loadFontPreview(option, 'Texte')
		assert.equal(stylesheetLoads, 1)
		assert.equal(binaryLoads, 2)
		globalThis.document.fonts.load = async () => []
		await assert.rejects(loadFontPreview(option, 'Texte'), /pas disponible/)
	} finally { globalThis.document = previous }
})


test('le catalogue construit uniquement une URL Google avec variante valide et texte limité', () => {
	const preview = googleFontPreview({ family: 'A & B Sans', previewVariant: '300i' })
	const url = new URL(preview.url)
	assert.equal(url.origin, 'https://fonts.googleapis.com')
	assert.equal(url.pathname, '/css2')
	assert.equal(url.searchParams.get('family'), 'A & B Sans:ital,wght@1,300')
	assert.equal(url.searchParams.get('text'), FONT_PREVIEW_SAMPLE)
	assert.equal(url.searchParams.has('key'), false)
	assert.equal(preview.style, 'italic')
	for (const font of [{ family: 'Bad;name' }, { family: 'Valid', previewVariant: '400;family=Other' }]) {
		assert.throws(() => googleFontPreview(font), /prévisualisée/)
	}
})

test('quitter une ligne retire sa feuille Google et annule un chargement inachevé', async () => {
	const links = []
	let fontLoads = 0
	const doc = {
		createElement: () => ({ removed: false, remove() { this.removed = true } }),
		head: { append(link) { links.push(link) } },
		fonts: { async load() { fontLoads++; return [{}] } },
	}
	const preview = createCatalogPreview({ family: 'Montserrat', previewVariant: '400' }, doc)
	assert.equal(links.length, 1)
	await links[0].onload()
	await preview.ready
	assert.equal(fontLoads, 1)
	preview.dispose()
	assert.equal(links[0].removed, true)

	const cancelled = createCatalogPreview({ family: 'Lora', previewVariant: '400' }, doc)
	cancelled.dispose()
	await assert.rejects(cancelled.ready, /fermé/)
	assert.equal(links[1].removed, true)
	assert.equal(fontLoads, 1)
})


test('l’actualisation du catalogue exige une réponse confirmée et une relecture concordante', async () => {
	const updatedAt = '2026-10-31T12:00:00Z'
	const response = { refreshed: true, catalog: { count: 1, updatedAt } }
	const library = { families: [{ id: font.id }], installed: {}, canManage: true, spaceIds: [1], catalogUpdatedAt: updatedAt }
	const store = createStore(response, {}, library)
	assert.deepEqual(await store.refreshCatalog(), response.catalog)
	assert.equal(store.catalogUpdatedAt, updatedAt)
	assert.equal(store.busy, false)
	assert.equal(store.refreshing, false)

	const mismatch = createStore(response)
	assert.equal(await mismatch.refreshCatalog(), null)
	assert.match(mismatch.error, /ne correspond pas/)
})

test('une actualisation non confirmée conserve le catalogue affiché', async () => {
	for (const response of ['Warning: erreur PHP', { refreshed: false }, { refreshed: true, catalog: { count: 0 } }]) {
		const store = createStore(response)
		store.families = [{ id: 'previous_font' }]
		assert.equal(await store.refreshCatalog(), null)
		assert.deepEqual(store.families, [{ id: 'previous_font' }])
		assert.equal(store.busy, false)
		assert.equal(store.refreshing, false)
	}
})
