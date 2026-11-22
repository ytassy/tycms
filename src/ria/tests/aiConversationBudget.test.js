import assert from 'node:assert/strict'
import { test } from 'node:test'
import { requestMessages } from '../src/components/common/ai/conversationBudget.js'

test('conversation compaction keeps the latest user request and complete Tool result', () => {
	const messages = [
		{ role: 'system', content: 'Instructions' },
		{ role: 'user', content: 'Ancienne demande' },
		{ role: 'assistant', content: 'A'.repeat(300) },
		{ role: 'user', content: 'Ajouter un titre' },
		{ role: 'assistant', content: '', tool_calls: [{ id: 'call_1' }] },
		{ role: 'tool', tool_call_id: 'call_1', content: '{"id":"n_1"}' },
	]
	const result = requestMessages(messages, { maxBytes: 400, maxMessages: 5 })
	assert.equal(result[0].role, 'system')
	assert.equal(result.at(-1).role, 'tool')
	assert.equal(result.at(-1).tool_call_id, result.at(-2).tool_calls[0].id)
	assert.equal(result.some(message => message.role === 'user' && message.content === 'Ajouter un titre'), true)
	assert.equal(result.some(message => message.content === 'A'.repeat(300)), false)
})
