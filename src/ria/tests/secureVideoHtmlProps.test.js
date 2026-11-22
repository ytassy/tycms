import assert from 'node:assert/strict'
import { test } from 'node:test'
import { readSecureVideoHtmlProps } from '../src/components/common/secureVideoHtmlProps.js'

const element = attributes => ({ getAttribute: name => attributes[name] ?? null })

test('les sources médias et alias sont transmises comme chaînes statiques', () => {
	for (const src of ['/medias/site/cours/playlist.m3u8', 'cours:video/playlist.m3u8']) {
		assert.deepEqual(readSecureVideoHtmlProps(element({ src, 'space-id': '7' })), {
			src, spaceId: '7',
		})
	}
})

test('les attributs supplémentaires, directives et événements ne deviennent jamais des props', () => {
	globalThis.secureVideoExpressionExecuted = false
	const expression = 'globalThis.secureVideoExpressionExecuted = true'
	try {
		assert.deepEqual(readSecureVideoHtmlProps(element({
			src: 'cours:video/playlist.m3u8', 'space-id': '7',
			':src': expression, 'v-bind': expression, '@error': expression,
			onError: expression, 'v-html': expression, is: 'ai_chat', ref: expression,
		})), { src: 'cours:video/playlist.m3u8', spaceId: '7' })
		assert.equal(globalThis.secureVideoExpressionExecuted, false)
	} finally {
		delete globalThis.secureVideoExpressionExecuted
	}
})

test('les props dynamiques seules et les attributs invalides empêchent le montage', () => {
	for (const attributes of [
		{}, { ':src': "'cours:video/playlist.m3u8'", 'space-id': '7' },
		{ src: 'cours:video/playlist.m3u8', ':space-id': '7' },
		...['', '0', '-1', '7.1', '1e2', '{{7}}', ' 7 ', '7\n', '7\r'].map(spaceId => ({
			src: 'cours:video/playlist.m3u8', 'space-id': spaceId,
		})),
		...['', ' ', 'cours:video/\nplaylist.m3u8', 'a'.repeat(2049)].map(src => ({
			src, 'space-id': '7',
		})),
	]) assert.equal(readSecureVideoHtmlProps(element(attributes)), null)
})

test('les interpolations dans une prop restent du texte sans évaluation', () => {
	assert.equal(readSecureVideoHtmlProps(element({
		src: 'cours:{{video}}/playlist.m3u8', 'space-id': '7',
	})).src, 'cours:{{video}}/playlist.m3u8')
})
