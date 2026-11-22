import assert from 'node:assert/strict'
import { test } from 'node:test'
import { audioMediaUrl, audioPlayerStyle, audioTime } from '../src/components/features/audio/audioPlayer.js'

test('le lecteur refuse les sources distantes et les chemins dangereux du brouillon', () => {
	assert.equal(audioMediaUrl('/medias/test/mon%20audio.wav'), '/medias/test/mon%20audio.wav')
	for (const src of ['https://evil.test/a.mp3', '//evil.test/a.mp3', '/medias/test/../secret', '/medias/test/%2e%2e/secret',
		'/medias/test/%5csecret', '/medias/test/%00a.mp3', '/medias/test/a.mp3?autoplay=1', '/medias/test/a.mp3" onerror="bad()', '/medias/test/%XX']) {
		assert.equal(audioMediaUrl(src), '')
	}
})

test('seules les options de présentation bornées deviennent des variables CSS', () => {
	assert.deepEqual(audioPlayerStyle({ height: 72, accent_color: '#123', color: '#12345678', border_radius: '12px' }), {
		'--page-audio-height': '72px', '--page-audio-accent-color': '#123', '--page-audio-color': '#12345678', '--page-audio-border-radius': '12px',
	})
	for (const style of [null, [], { height: 0, color: 'red;display:none' }, { height: 201, border_radius: '-1px' },
		{ height: '56', accent_color: 'url(https://evil.test)', unknown: '#fff' }]) {
		assert.deepEqual(audioPlayerStyle(style), {})
	}
})

test('les durées inconnues et les pistes longues restent lisibles', () => {
	assert.equal(audioTime(NaN), '0:00')
	assert.equal(audioTime(Infinity), '0:00')
	assert.equal(audioTime(-1), '0:00')
	assert.equal(audioTime(65.9), '1:05')
	assert.equal(audioTime(3661), '1:01:01')
})
