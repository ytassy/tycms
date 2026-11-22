import assert from 'node:assert/strict'
import test from 'node:test'
import { createSSRApp, defineComponent, h } from 'vue'
import { renderToString } from '@vue/server-renderer'
import { createLanguageI18n } from '../src/application/language.js'

const Message = defineComponent({
	props: { messageKey: { type: String, required: true } },
	render() { return h('p', this.$t(this.messageKey)) },
})

test('deux racines Vue gardent chacune la langue et les surcharges du bootstrap', async () => {
	const french = createSSRApp(Message, { messageKey: 'LG_CORE_MENU_HOME' })
	french.use(createLanguageI18n({
		language: { locale: 'fr', messages: { LG_CORE_MENU_HOME: 'Accueil' } },
	}))

	const english = createSSRApp(Message, { messageKey: 'LG_CORE_MENU_HOME' })
	english.use(createLanguageI18n({
		language: { locale: 'en', messages: { LG_CORE_MENU_HOME: 'Site home' } },
	}))

	assert.equal(await renderToString(french), '<p>Accueil</p>')
	assert.equal(await renderToString(english), '<p>Site home</p>')
})

test('le HTML reste du texte par défaut et les paramètres sont échappés', async () => {
	const i18n = createLanguageI18n({
		language: {
			locale: 'en',
			messages: { LG_HTML: '<strong>Hello {name}</strong>' },
		},
	})

	const translation = i18n.global.t('LG_HTML', { name: '<img src=x onerror=alert(1)>' })
	assert.match(translation, /^<strong>Hello /)
	assert.doesNotMatch(translation, /<img/)

	const app = createSSRApp(Message, { messageKey: 'LG_HTML' })
	app.use(i18n)
	assert.match(await renderToString(app), /&lt;strong&gt;/)
})

test('les adresses courriel et la pluralisation Vue i18n restent utilisables', () => {
	const i18n = createLanguageI18n({
		language: {
			locale: 'en',
			messages: {
				LG_CONTACT: '<a href="mailto:test@example.com">test@example.com</a>',
				LG_COUNT: 'none | one | {count} items',
			},
		},
	})

	assert.equal(i18n.global.t('LG_CONTACT'),
		'<a href="mailto:test@example.com">test@example.com</a>')
	assert.equal(i18n.global.t('LG_COUNT', 2), '2 items')
})
