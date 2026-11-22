import { mapActions } from 'pinia'
import moment from 'moment'

export function registerMixin(vueApp, application) {
	const useAppStore = application.stores.useAppStore

	vueApp.mixin({
		computed: {
			pubsub() { return application.pubsub },
			app() { return application },
			acl() { return application.acl },
			tools() { return application.tools },
			api() { return application.api },
			conf() { return application.conf },
			stores() { return application.stores },
			global_vue_comp_async_options() { return new Map() },
		},

		methods: {
			$ucFirst(text) {
				if (text === null || text === undefined || text === '') return ''
				const value = String(text)
				return value.charAt(0).toUpperCase() + value.slice(1)
			},

			$date(date, format = 'dddd DD/MM/YYYY à HH:mm') {
				return date === null ? '' : moment(date).format(format)
			},

			...mapActions(useAppStore, [
				'showModaleWait',
				'hideModaleWait',
				'showModaleSuccess',
				'hideModaleSuccess',
				'showModaleInfo',
				'showModaleConfirm',
				'showModaleWarning',
				'showModaleError',
				'showModaleDanger',
				'showModaleException',
				'hideModaleMessage',
				'showModaleQuestionYesNo',
				'showModaleQuestion',
				'hideModaleQuestion',
				'askInput',
				'setNextApiUi',
			]),

			askYesNo(text, callback) {
				useAppStore().showModaleQuestionYesNo(text, callback)
			},

			askYesNoAsync(text) {
				// La modale Question renvoie une promesse utilisable dans les actions async.
				return useAppStore().askYesNo(text)
			},

			askYes(text, callback) {
				useAppStore().showModaleQuestionYesNo(text, (answer) => {
					if (answer) callback(answer)
				})
			},

			utcToLocal(utcDateString, options = {}) {
				if (!utcDateString) return ''
				const normalized = utcDateString.endsWith('Z') || utcDateString.includes('+')
					? utcDateString
					: `${utcDateString.replace(' ', 'T')}Z`
				const date = new Date(normalized)
				if (Number.isNaN(date.getTime())) return utcDateString

				return new Intl.DateTimeFormat(navigator.language || 'fr-FR', {
					timeZone: Intl.DateTimeFormat().resolvedOptions().timeZone,
					year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit',
					...options,
				}).format(date)
			},
		},
	})
}

export default {
	registerMixin,
}
