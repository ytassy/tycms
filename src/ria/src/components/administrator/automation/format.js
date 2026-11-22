export function statusLabel(status) {
	return {
		success: 'Succès',
		ok: 'OK',
		failed: 'Échec',
		stopped: 'Arrêté',
		running: 'En cours',
		queued: 'En attente',
		pending: 'En attente',
		processing: 'En cours',
		invalid: 'Invalide',
	}[status] ?? (status || '—')
}

export function statusClass(status) {
	return {
		success: 'text-bg-success',
		ok: 'text-bg-success',
		failed: 'text-bg-danger',
		stopped: 'text-bg-secondary',
		running: 'text-bg-warning',
		queued: 'text-bg-secondary',
		pending: 'text-bg-secondary',
		processing: 'text-bg-warning',
		invalid: 'text-bg-danger',
	}[status] ?? 'text-bg-secondary'
}

export function effectLabel(effect) {
	return {
		pure: 'Calcul',
		read: 'Lecture',
		write: 'Écriture',
		external: 'Externe',
	}[effect] ?? (effect || '—')
}

export function effectClass(effect) {
	return {
		pure: 'text-bg-secondary',
		read: 'text-bg-info',
		write: 'text-bg-warning',
		external: 'text-bg-primary',
	}[effect] ?? 'text-bg-secondary'
}

export function formatDate(value) {
	return value ? String(value).replace('T', ' ').replace(/\.\d+Z?$/, '') : '—'
}

export function prettyJson(value) {
	if (value === null || value === undefined || value === '') return ''
	if (typeof value !== 'string') return JSON.stringify(value, null, 2)

	try {
		return JSON.stringify(JSON.parse(value), null, 2)
	} catch {
		return value
	}
}

export function slugify(value) {
	const normalized = String(value ?? '')
		.normalize('NFD')
		.replace(/[\u0300-\u036f]/g, '')
		.toLowerCase()
		.replace(/[^a-z0-9_-]+/g, '-')
		.replace(/^-+|-+$/g, '')
		.slice(0, 64)

	if (normalized === '') return ''
	return /^[a-z]/.test(normalized) ? normalized : `scenario-${normalized}`.slice(0, 64)
}
