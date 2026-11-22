export class PubSub {
	constructor() {
		this.events = new Map()
	}

	on(event, callback) {
		const callbacks = this.events.get(event) ?? new Set()
		callbacks.add(callback)
		this.events.set(event, callbacks)

		return () => this.off(event, callback)
	}

	off(event, callback) {
		const callbacks = this.events.get(event)
		if (callbacks === undefined) return

		callbacks.delete(callback)
		if (callbacks.size === 0) this.events.delete(event)
	}

	emit(event, data = null) {
		this.events.get(event)?.forEach((callback) => callback(data))
	}

	once(event, callback) {
		const unsubscribe = this.on(event, (data) => {
			unsubscribe()
			callback(data)
		})
	}

	clear(event) {
		this.events.delete(event)
	}
}
