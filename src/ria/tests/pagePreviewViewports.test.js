import assert from 'node:assert/strict'
import { test } from 'node:test'
import { columnSizeAtViewport, pagePreviewViewports } from '../src/components/administrator/pages/pagePreviewViewports.js'

test('responsive preview uses the selected breakpoint and falls back to smaller ones', () => {
	const sizes = { xs: 12, sm: 8, md: 6, lg: 0, xl: 4, xxl: 3 }
	assert.deepEqual(pagePreviewViewports.map(option => option.id), ['xs', 'sm', 'md', 'lg', 'xl', 'xxl'])
	assert.deepEqual(pagePreviewViewports.map(option => columnSizeAtViewport(sizes, option.id)), [12, 8, 6, 6, 4, 3])
	assert.equal(columnSizeAtViewport({}, 'md'), 12)
})
