
//import exampleApi from './api/example_api.js'

import axios from "axios"

const APImimeToExt = {
  'application/epub+zip':                      'epub',
  'application/pdf':                           'pdf',
  'application/zip':                           'zip',
  'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'docx',
  'application/msword':                        'doc',
  'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'xlsx',
  'image/jpeg':                                'jpg',
  'image/png':                                 'png',
  'image/webp':                                'webp',
  'audio/mpeg':                                'mp3',
  'video/mp4':                                 'mp4',
  'text/plain':                                'txt',
  'text/csv':                                  'csv',
}

function APIgetFilename(response, fallbackName = 'download') {
	// 1. Priorité : Content-Disposition
	const cd = response.headers['content-disposition']

	if (cd) {
		const match = cd.match(/filename[^;=\n]*=((['"]).*?\2|[^;\n]*)/)
		if (match?.[1]) return match[1].replace(/['"]/g, '')
	}

	// 2. Extension depuis Content-Type
	const contentType = response.headers['content-type']?.split(';')[0].trim()
	const ext = APImimeToExt[contentType]

	return ext ? `${fallbackName}.${ext}` : fallbackName
}



export default {
	getApi(app)
	{
		return {

			//example : exampleApi.get(app),

			get(url, params, fileResponseExptected = false){
				return this.request(url, 'get', params, fileResponseExptected);
			},

			post(url, params, fileResponseExptected = false, options = {}){
				return this.request(url, 'post', params, fileResponseExptected, options);
			},

			request: async (url, method = "get", formData = null, fileResponseExptected = false, options = {}) => {

				if (fileResponseExptected && method != 'get') throw new Error("Invalid API Args");

				if (formData && !(formData instanceof FormData))
				{
					for (let i in formData)
					{
						let value = formData[i];
						if (value === null) { }
						else if (typeof value === 'object' && !(value instanceof File) && !(value instanceof Blob)) formData[i] = JSON.stringify(value)
					}
				}
		
				return new Promise((resolve, reject) => {
					let appStore = app.stores.useAppStore();
					let nextApiUi = appStore.nextApiUi;
					if (nextApiUi != null) app.stores.useAppStore().showModaleWait();
		
					let nextApiUiSuccess = () => {
						if (nextApiUi != null) {
							app.stores.useAppStore().hideModaleWait();
							if (nextApiUi.useSuccessModale) app.stores.useAppStore().showModaleSuccess(nextApiUi.textConfirm);
							else if (nextApiUi.textConfirm != "") app.stores.useAppStore().showModaleConfirm(nextApiUi.textConfirm);

							appStore.resetNextApiUi();
						}

					}

					let nextApiUiError = () => {
						if (nextApiUi != null) {
							app.stores.useAppStore().hideModaleWait();

							appStore.resetNextApiUi();
						}
					}

					let axiosGetParams = {
						params: formData
					}
					if (fileResponseExptected) axiosGetParams.responseType = 'blob';


					let isMessageOrErrorResponse = (data) => {
						return data?.error == true || data?.is_message == true;
					}

					let processMessageOrErrorResponse = (data) => {
						// handle error
						nextApiUiError();

						let message_level = data?.message_level;
						
						if (data.message)
						{
							if (message_level == 'INFO') appStore.showModaleInfo(data.message)
							if (message_level == 'SUCCESS') appStore.showModaleSuccess(data.message)
							if (message_level == 'WARNING') appStore.showModaleWarning(data.message)
							if (message_level == 'DANGER') appStore.showModaleDanger(data.message)
							if (message_level == 'ERROR') appStore.showModaleError(data.message)
							else appStore.showModaleError(data.message)
						}
					}

		
					if (method == 'get') axios.get(url, axiosGetParams)
						.then(function (response) {
							
							if (isMessageOrErrorResponse(response.data))
							{
								processMessageOrErrorResponse(response.data)
								reject(new Error(response.data.message || 'La requête a échoué.'))
							}
							else
							{
								// handle success
								nextApiUiSuccess();

								if (fileResponseExptected) {
									const fileName = APIgetFilename(response, 'fichier')
									const contentType = response.headers['content-type']?.split(';')[0].trim()

									resolve({
										data: response.data,
										fileName,
										contentType
									})
								} else {
									resolve(response.data);
								}
							}
							
						})
						.catch(function (error) {
							nextApiUiError();
							if (isMessageOrErrorResponse(error.response?.data)) {
								processMessageOrErrorResponse(error.response.data);
							} else if (error.response?.data?.message) {
								appStore.showModaleError(error.response.data.message);
							}
							reject(error);
						})
						.finally(function () {
							// always executed
						});
		
					const csrfToken = app.conf.getCsrfToken()
					const postOptions = csrfToken && new URL(url, window.location.href).origin === window.location.origin
						? { ...options, headers: { ...options.headers, 'X-CSRF-Token': csrfToken } }
						: options

					if (method == 'post') axios.post(url, formData, postOptions)
						.then(function (response) {
							if (isMessageOrErrorResponse(response.data))
							{
								processMessageOrErrorResponse(response.data)
								reject(new Error(response.data.message || 'La requête a échoué.'))
								return
							}

							// handle success
							nextApiUiSuccess();
							resolve(response.data);
						})
						.catch(function (error) {
							// handle error
							console.log(error);
							nextApiUiError();

							if (error.response?.data?.message) appStore.showModaleError(error.response.data.message)
							reject(error);
						})
						.finally(function () {
							// always executed
						});
				})
		
				
			},

		}
	}
}
