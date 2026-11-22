function setCleanup(element, cleanup) {
	element.__cleanup?.()
	element.__cleanup = cleanup
}

function cleanup(element) {
	element.__cleanup?.()
	delete element.__cleanup
}

export function registerDirectives(vueApp, application) {
	vueApp.directive('single-click', {
		mounted(element) {
			const handler = () => { element.style.pointerEvents = 'none' }
			element.addEventListener('click', handler)
			setCleanup(element, () => element.removeEventListener('click', handler))
		},
		beforeUnmount: cleanup,
	})

	vueApp.directive('prevent-double', {
		mounted(element) {
			const handler = () => {
				element.style.pointerEvents = 'none'
				window.setTimeout(() => { element.style.pointerEvents = '' }, 1000)
			}
			element.addEventListener('click', handler)
			setCleanup(element, () => element.removeEventListener('click', handler))
		},
		beforeUnmount: cleanup,
	})

	vueApp.directive('single-click-icon', {
		mounted(element) {
			const handler = () => {
				element.style.pointerEvents = 'none'
				const icon = element.querySelector('i')
				if (icon !== null) icon.hidden = true
				const spinner = document.createElement('i')
				spinner.className = 'fa fa-spinner fa-pulse icon-default'
				icon?.after(spinner) ?? element.append(spinner)
			}
			element.addEventListener('click', handler)
			setCleanup(element, () => element.removeEventListener('click', handler))
		},
		beforeUnmount: cleanup,
	})

	const visibility = (element, binding) => {
		element.style.visibility = binding.value ? 'visible' : 'hidden'
	}
	vueApp.directive('visibility', { mounted: visibility, updated: visibility })
	vueApp.directive('visible', { mounted: visibility, updated: visibility })

	const pointerEvents = (element, binding) => {
		element.style.pointerEvents = binding.value ? 'none' : ''
	}
	vueApp.directive('pointer-event-none', { mounted: pointerEvents, updated: pointerEvents })

	const opacity = (element, binding) => {
		element.style.opacity = binding.value ? '0.5' : ''
	}
	vueApp.directive('opacity-50', { mounted: opacity, updated: opacity })

	vueApp.directive('focus', {
		mounted(element) { element.focus() },
	})

	const printTextMultiline = (element, binding) => {
		if (binding.value === null || binding.value === undefined) return
		element.innerHTML = String(application.tools.escapeHtml(binding.value)).replace(/\n/g, '<br>')
	}
	vueApp.directive('text-ml', { mounted: printTextMultiline, updated: printTextMultiline })

	vueApp.directive('propagate-to-input', {
		mounted(element, binding) {
			if (binding.value === false) return
			const handler = (event) => {
				event.stopPropagation()
				if (event.target !== element) return
				element.querySelector('a, button, input')?.click()
			}
			element.addEventListener('click', handler)
			setCleanup(element, () => element.removeEventListener('click', handler))
		},
		beforeUnmount: cleanup,
	})


	// ****** ACL
	const aclPerm = (element, binding) => {
		if (binding.value == '') return
		const { perm, spaceId = null } = typeof binding.value === 'object'
			? binding.value
			: { perm: binding.value }
		let hasPerm = application.acl.hasGlobalPermission(perm, spaceId);

		if (hasPerm == false){
			element.style.setProperty('display', 'none', 'important')
			element.style.setProperty('visibility', 'hidden', 'important')
		} else {
			element.style.setProperty('display', '')
			element.style.setProperty('visibility', '')
		}
	};
	vueApp.directive('acl-perm', {
		mounted: aclPerm, updated: aclPerm,
	})


	const aclRole = (element, binding) => {
		if (binding.value == '') return
		const { role, spaceId = null } = typeof binding.value === 'object'
			? binding.value
			: { role: binding.value }
		let hasRole = application.acl.hasGlobalRole(role, spaceId);

		if (hasRole == false){
			element.style.setProperty('display', 'none', 'important')
			element.style.setProperty('visibility', 'hidden', 'important')
		} else {
			element.style.setProperty('display', '')
			element.style.setProperty('visibility', '')
		}
	}

	vueApp.directive('acl-role', {
		mounted: aclRole, updated: aclRole,
	})
}

export default {
	registerDirectives,
}
