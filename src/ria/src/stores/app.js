import { defineStore } from 'pinia'

export const useAppStore = {
	get(vueApp, application) {
		return defineStore('app', {
			state: () => ({
				modalWaitVisible: false,

				modalSuccessVisible: false,
				modalSuccessText: null,

				modalMessageText: null,
				modalMessageCode: 0,
				modalMessageException: null,
				modalMessageOnClose: null,

				modalQuestionText: null,
				modalQuestionCallback: null,
				modalQuestionOptions: null,
				modalInput: null,

				nextApiUi: null,
			}),

			actions: {
				showModaleWait() {
					this.modalWaitVisible = true
				},

				hideModaleWait() {
					this.modalWaitVisible = false
				},

				showModaleSuccess(text = null) {
					this.modalSuccessVisible = true
					this.modalSuccessText = text

					window.setTimeout(() => {
						this.hideModaleSuccess()
					}, 1000)
				},

				hideModaleSuccess() {
					this.modalSuccessVisible = false
					this.modalSuccessText = null
				},

				showModaleInfo(text, onClose = null) {
					this.showModaleMessage(text, 1, onClose)
				},

				showModaleConfirm(text, onClose = null) {
					this.showModaleMessage(text, 2, onClose)
				},

				showModaleWarning(text, onClose = null) {
					this.showModaleMessage(text, 3, onClose)
				},

				showModaleDanger(text, onClose = null) {
					this.showModaleMessage(text, 4, onClose)
				},

				showModaleError(text, onClose = null) {
					this.showModaleMessage(text, 5, onClose)
				},

				showModaleException(text, exception) {
					this.modalMessageText = text
					this.modalMessageCode = 5
					this.modalMessageException = exception
					this.modalMessageOnClose = null
				},

				showModaleMessage(text, code, onClose = null) {
					this.modalMessageText = text
					this.modalMessageCode = code
					this.modalMessageException = null
					this.modalMessageOnClose = typeof onClose === 'function' ? onClose : null
				},

				hideModaleMessage() {
					const onClose = this.modalMessageOnClose
					this.modalMessageText = null
					this.modalMessageCode = 0
					this.modalMessageException = null
					this.modalMessageOnClose = null
					onClose?.()
				},

				showModaleQuestionYesNo(text, callback = null) {
					this.showModaleQuestion(text, null, callback)
				},

				showModaleQuestion(
					text,
					options,
					callback = null,
				) {
					this.modalQuestionText = text
					this.modalQuestionCallback = callback
					this.modalQuestionOptions = options
				},

				hideModaleQuestion() {
					this.modalQuestionText = null
					this.modalQuestionCallback = null
					this.modalQuestionOptions = null
				},

				answerQuestion(answer) {
					const callback = this.modalQuestionCallback
					this.hideModaleQuestion()
					callback?.(answer)
				},

				askYesNo(question) {
					return new Promise((resolve) => {
						this.showModaleQuestionYesNo(question, resolve)
					})
				},

				askInput(title, label, value = '') {
					return new Promise((resolve) => {
						this.modalInput?.resolve(null)
						this.modalInput = { title, label, value, resolve }
					})
				},

				answerInput(value) {
					const request = this.modalInput
					this.modalInput = null
					request?.resolve(value)
				},

				setNextApiUi(textConfirm = null, useSuccessModale = true) {
					this.nextApiUi = {
						useSuccessModale,
						textConfirm,
					}
				},

				resetNextApiUi() {
					this.nextApiUi = null
				},
			},
		})
	},
}
