const bytes = value => new TextEncoder().encode(JSON.stringify(value)).length

/** Keep the latest request and complete Tool exchanges when older context grows. */
export function requestMessages(messages, { maxBytes = 160_000, maxMessages = 70 } = {}) {
	if (messages.length <= maxMessages && bytes(messages) <= maxBytes) return messages
	const lastUserIndex = messages.findLastIndex(message => message.role === 'user')
	if (lastUserIndex < 0) throw new Error('Conversation IA sans demande utilisateur.')
	const system = messages.find(message => message.role === 'system')
	const recap = messages.slice(0, lastUserIndex).filter(message => message.role === 'user')
		.slice(-4).map(message => String(message.content).slice(0, 250))
	const prefix = [
		...(system ? [system] : []),
		{ role: 'system', content: `Le contexte ancien a été abrégé. Relis le brouillon actuel avec les Tools avant de le modifier.${recap.length ? ` Demandes précédentes : ${recap.join(' | ')}` : ''}` },
		messages[lastUserIndex],
	]
	if (bytes(prefix) > maxBytes) throw new Error('La demande IA est trop longue. Raccourcissez le message.')
	for (let start = lastUserIndex + 1; start < messages.length; start++) {
		if (messages[start].role !== 'assistant') continue
		const candidate = [...prefix, ...messages.slice(start)]
		if (candidate.length <= maxMessages && bytes(candidate) <= maxBytes) return candidate
	}
	return prefix
}
