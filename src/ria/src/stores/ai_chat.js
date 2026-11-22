import { defineStore } from 'pinia'

export const useAiChatStore = {
	get(vueApp, application) {
		return defineStore('ai_chat', {
			actions: {
				capabilities(spaceId) {
					return application.api.get('/api/ai/capabilities', { spaceId })
				},
				complete(spaceId, useCase, messages, tools) {
					return application.api.post('/api/ai/llm', { spaceId, useCase, input: { messages, tools } })
				},
				generateImage(spaceId, prompt) {
					return application.api.post('/api/ai/image', { spaceId, input: { prompt } })
				},
				status(batchId) {
					return application.api.post('/api/ai/status', { batchId })
				},
			},
		})
	},
}
