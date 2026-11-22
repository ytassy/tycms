import { pageCatalogue } from './fixtures/pageCatalogue.js'
import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createItem } from '../src/components/administrator/pages/pageEditorModel.js'
import { mermaidDefaultsForPage, setMermaidOverride } from '../src/components/administrator/pages/pageMermaidOptions.js'
import { videoEmbedUrl, videoMediaUrl } from '../src/components/features/video/videoSources.js'
import { pagePreviewDocument } from '../src/components/administrator/pages/pagePreviewDocument.js'

test('media components are available with independent defaults', () => {
	assert.ok(pageCatalogue.some(component => component.id === 'core.video'))
	assert.ok(pageCatalogue.some(component => component.id === 'core.mermaid'))
	assert.equal(createItem('core.video').props.source, 'media')
	assert.ok(pageCatalogue.some(component => component.id === 'core.audio'))
	const audio = createItem('core.audio')
	audio.props.audio_style.height = 72
	assert.equal(createItem('core.audio').props.src, '')
	assert.deepEqual(createItem('core.audio').props.audio_style, {})
	const first = createItem('core.mermaid')
	const second = createItem('core.mermaid')
	first.props.options.theme = 'dark'
	assert.deepEqual(second.props.options, {})
	assert.match(first.props.diagram, /flowchart/)
})

test('Mermaid inherits from the page Space, and deleting an override restores inheritance', () => {
	const store = { current: { space_id: 7 }, spaces: [
		{ id: 1, mermaidOptions: { theme: 'forest' } }, { id: 7, mermaidOptions: { theme: 'dark', width: '80%' } },
	] }
	const props = { options: {} }
	assert.equal(mermaidDefaultsForPage(store).theme, 'dark')
	setMermaidOverride(props, 'theme', 'neutral')
	assert.equal({ ...mermaidDefaultsForPage(store), ...props.options }.theme, 'neutral')
	setMermaidOverride(props, 'theme', '')
	assert.deepEqual(props.options, {})
	store.spaces[1].mermaidOptions.theme = 'neo'
	assert.equal(mermaidDefaultsForPage(store).theme, 'neo')
})

test('only canonical YouTube and Vimeo player URLs are used by the canvas', () => {
	assert.equal(videoEmbedUrl('https://youtu.be/Abc_def-123?autoplay=1'), 'https://www.youtube.com/embed/Abc_def-123')
	assert.equal(videoEmbedUrl('https://www.youtube.com/watch?v=Abc_def-123'), 'https://www.youtube.com/embed/Abc_def-123')
	assert.equal(videoEmbedUrl('https://vimeo.com/123456789'), 'https://player.vimeo.com/video/123456789')
	for (const url of ['javascript:alert(1)', 'http://vimeo.com/123', 'https://www.youtube.com.evil.test/watch?v=Abc_def-123', 'https://www.youtube.com@evil.test/watch?v=Abc_def-123', 'https://example.test/video.mp4']) {
		assert.equal(videoEmbedUrl(url), '')
	}
})

test('media URLs reject external origins, encoded traversal and backslashes', () => {
	assert.equal(videoMediaUrl('/medias/test/ma%20video.mp4'), '/medias/test/ma%20video.mp4')
	for (const url of ['//evil.test/video.mp4', '/medias/test/../secret', '/medias/test/%2e%2e/secret', '/medias/test/%5csecret', '/medias/test/video.mp4?url=https://evil.test']) assert.equal(videoMediaUrl(url), '')
})

test('the faithful preview allows local video without allowing external media sources', () => {
	const html = pagePreviewDocument({ html: '' }, 'fr', 'https://site.test', { assetsBase: '/assets_core_v1_2_3/plg_core/' })
	assert.equal(html.match(/media-src ([^;]+);/)[1], 'https://site.test')
})
