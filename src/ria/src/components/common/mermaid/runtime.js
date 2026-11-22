import { getCoreAssetsBase } from '../../../application/coreAssets.js'

let runtime

export function getMermaidRuntime() {
	// Charger le moteur de la même version que le bundle Vue de cette page.
	const url = new URL(`${getCoreAssetsBase()}mermaid/runtime.mjs`, window.location.origin).href
	return runtime ??= import(/* @vite-ignore */ url)
}

export function configuredMermaidOptions() {
	try {
		return JSON.parse(document.getElementById('app-bootstrap')?.textContent ?? '{}').shortcodes?.mermaid ?? {}
	} catch { return {} }
}
