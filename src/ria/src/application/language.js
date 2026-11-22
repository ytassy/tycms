import { createI18n } from 'vue-i18n'

export function createLanguageI18n(globalConfiguration = {}) {
	const language = globalConfiguration.language ?? {}
	const locale = language.locale || language.defaultLocale || 'fr'
	// Vue i18n interprète « @ » comme début d'un lien vers un autre message.
	// Les textes et liens mailto des catalogues l'utilisent comme caractère brut.
	const messages = Object.fromEntries(
		Object.entries(language.messages ?? {}).map(([key, value]) => [
			key,
			typeof value === 'string' ? value.replaceAll('@', '\\@') : value,
		]),
	)

	// PHP a déjà choisi le Space, la langue et les surcharges effectives.
	// Chaque application Vue reçoit le même dictionnaire sans appel API.
	return createI18n({
		legacy: false,
		globalInjection: true,
		locale,
		fallbackLocale: false,
		messages: { [locale]: messages },
		missingWarn: false,
		fallbackWarn: false,
		escapeParameter: true,
		warnHtmlMessage: false,
	})
}
