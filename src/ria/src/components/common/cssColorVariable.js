// Même grammaire que CssColorVariable.php : aucun repli ni expression CSS libre.
export function templateColorName(value) {
	return typeof value === 'string' && !/[\r\n]/.test(value) ? /^var\((--[a-zA-Z_][a-zA-Z0-9_-]{0,63})\)$/.exec(value)?.[1] ?? null : null
}
