import assert from 'node:assert/strict'
import { test } from 'node:test'
import { createImageGenerationTool, displayImage } from '../src/components/common/ai/imageGenerationTool.js'
import { parseToolCall, prepareTools } from '../src/components/common/ai/aiTools.js'

const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Wl+G0YAAAAASUVORK5CYII='

test('the shared image Tool exposes its prompt and requires approval mode', async () => {
	const calls = [], displayed = []
	const tool = createImageGenerationTool({
		generate: async prompt => { calls.push(prompt); return { b64_json: PNG, media_type: 'image/png' } },
		publish: image => displayed.push(image),
	})
	const { byName, definitions } = prepareTools([tool])
	assert.equal(definitions[0].function.name, 'generate_image')
	assert.equal(JSON.stringify(definitions).includes('approval'), false)
	const { args } = parseToolCall({ id: 'call_1', function: {
		name: 'generate_image', arguments: '{"prompt":" Un paysage "}',
	} }, byName)
	assert.equal(tool.mode, 'approval')
	assert.equal(tool.approval(args).summary, 'Un paysage')
	assert.deepEqual(calls, [])
	const result = await tool.execute(args)
	assert.deepEqual(calls, ['Un paysage'])
	assert.deepEqual(result, { generated: true, displayed: true, count: 1 })
	assert.equal(JSON.stringify(result).includes(PNG), false)
	assert.equal(displayed[0].src, `data:image/png;base64,${PNG}`)
	assert.throws(() => tool.approval(args), /Une seule génération/)
	await assert.rejects(tool.execute(args), /Une seule génération/)
})

test('invalid prompts and image payloads are rejected', () => {
	const tool = createImageGenerationTool({ generate: async () => null, publish: () => {} })
	assert.throws(() => tool.validate({ prompt: ' ' }), /prompt image/)
	assert.throws(() => displayImage({ b64_json: PNG, media_type: 'image/svg+xml' }, 'Test'), /non prise en charge/)
	assert.throws(() => displayImage({ b64_json: 'bad*', media_type: 'image/png' }, 'Test'), /invalide/)
})
