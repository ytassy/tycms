import assert from 'node:assert/strict'
import test from 'node:test'
import { mediaEntryHtml } from '../src/components/common/RichTextEditor/richTextMedia.js'

test('insère les images, les vidéos et les autres fichiers avec une URL racine', () => {
	assert.equal(mediaEntryHtml({ type: 'file', url: '/medias/site/photo%20test.jpg', name: 'Photo test.jpg', mime: 'image/jpeg' }), '<img src="/medias/site/photo%20test.jpg" alt="Photo test.jpg">')
	assert.equal(mediaEntryHtml({ type: 'file', url: '/medias/site/video.mp4', name: 'video.mp4', mime: 'video/mp4' }), '<video controls="controls" src="/medias/site/video.mp4"></video>')
	assert.equal(mediaEntryHtml({ type: 'file', url: '/medias/site/rapport.pdf', name: 'rapport.pdf', mime: 'application/pdf' }), '<a href="/medias/site/rapport.pdf">rapport.pdf</a>')
})

test('échappe le texte et refuse les URL étrangères à la médiathèque', () => {
	assert.equal(mediaEntryHtml({ type: 'file', url: '/medias/site/a%26b.pdf', name: 'a & <b>.pdf', mime: 'application/pdf' }), '<a href="/medias/site/a%26b.pdf">a &amp; &lt;b&gt;.pdf</a>')
	assert.equal(mediaEntryHtml({ type: 'file', url: 'https://example.com/photo.jpg', name: 'photo', mime: 'image/jpeg' }), null)
	assert.equal(mediaEntryHtml({ type: 'file', url: '//example.com/photo.jpg', name: 'photo', mime: 'image/jpeg' }), null)
	assert.equal(mediaEntryHtml({ type: 'directory', url: '/medias/site/folder', name: 'folder' }), null)
})
