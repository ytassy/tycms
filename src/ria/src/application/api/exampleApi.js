export function getExampleApi(application) {
	return {
		ping(message) {
			return application.api.apiCall('option', 'controller', 'ping', 'post', { message })
		},
	}
}
