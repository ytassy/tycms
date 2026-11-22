/** Positions de pages bornées, sans dupliquer les témoignages dans le DOM. */
export function testimonialPositions(offsets, maximum, visibleCount) {
	const positions = [0]
	const count = Math.max(1, Math.floor(visibleCount))
	for (let index = count; index < offsets.length; index += count) positions.push(Math.min(maximum, Math.max(0, offsets[index])))
	positions.push(Math.max(0, maximum))
	return [...new Set(positions.map(value => Math.round(value)))].sort((a, b) => a - b)
}

export function enhanceTestimonials(root) {
	if (!root.classList.contains('page-testimonials--carousel')) return () => {}
	const track = root.querySelector(':scope > .page-testimonials-track')
	const controls = root.querySelector(':scope > .page-testimonials-controls')
	if (!track || !controls) return () => {}
	const entries = [...track.children]
	const dots = controls.querySelector('[data-testimonials-dots]')
	const previous = controls.querySelector('[data-testimonials-prev]')
	const next = controls.querySelector('[data-testimonials-next]')
	const pause = controls.querySelector('[data-testimonials-pause]')
	const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)')
	const english = document.documentElement.lang.startsWith('en')
	const requestedInterval = Number(root.dataset.interval)
	const interval = Number.isInteger(requestedInterval) && requestedInterval >= 2000 && requestedInterval <= 60000 ? requestedInterval : 6000
	let positions = [0], current = 0, timer = null, hovered = false, focused = false, paused = false
	const removers = []
	const listen = (element, name, handler) => {
		element.addEventListener(name, handler)
		removers.push(() => element.removeEventListener(name, handler))
	}
	const go = index => track.scrollTo({ left: positions[Math.max(0, Math.min(positions.length - 1, index))], behavior: reducedMotion.matches ? 'instant' : 'smooth' })
	const schedule = () => {
		clearInterval(timer)
		timer = null
		if (root.dataset.autoplay !== '1' || reducedMotion.matches || paused || hovered || focused || document.hidden || positions.length < 2) return
		timer = setInterval(() => go((current + 1) % positions.length), interval)
	}
	const update = () => {
		current = positions.reduce((nearest, value, index) => Math.abs(value - track.scrollLeft) < Math.abs(positions[nearest] - track.scrollLeft) ? index : nearest, 0)
		if (previous) previous.disabled = current === 0
		if (next) next.disabled = current === positions.length - 1
		for (const [index, dot] of [...(dots?.children ?? [])].entries()) dot.setAttribute('aria-current', String(index === current))
	}
	const measure = () => {
		const origin = entries[0]?.offsetLeft ?? 0
		const offsets = entries.map(entry => entry.offsetLeft - origin)
		const step = offsets[1] || entries[0]?.offsetWidth || 1
		positions = testimonialPositions(offsets, Math.max(0, track.scrollWidth - track.clientWidth), Math.max(1, Math.round(track.clientWidth / step)))
		controls.hidden = positions.length < 2 || controls.children.length === 0
		if (dots) {
			dots.replaceChildren(...positions.map((_, index) => {
				const button = document.createElement('button')
				button.type = 'button'
				button.dataset.testimonialsPage = String(index)
				button.setAttribute('aria-label', `${english ? 'Testimonials page' : 'Page de témoignages'} ${index + 1}`)
				return button
			}))
		}
		update()
		schedule()
	}

	// Les boutons complètent le défilement natif, utilisable au toucher et au clavier.
	listen(controls, 'click', event => {
		const button = event.target.closest('button')
		if (!button || !controls.contains(button)) return
		if (button === previous) go(current - 1)
		else if (button === next) go(current + 1)
		else if (button === pause) {
			paused = !paused
			pause.setAttribute('aria-pressed', String(paused))
			pause.setAttribute('aria-label', paused ? (english ? 'Resume autoplay' : 'Reprendre le défilement automatique') : (english ? 'Pause autoplay' : 'Suspendre le défilement automatique'))
			pause.textContent = paused ? '▶' : 'Ⅱ'
			schedule()
		} else if (button.dataset.testimonialsPage !== undefined) go(Number(button.dataset.testimonialsPage))
	})
	listen(track, 'scroll', update)
	listen(track, 'keydown', event => {
		if (event.target !== track) return
		const index = { ArrowLeft: current - 1, ArrowRight: current + 1, Home: 0, End: positions.length - 1 }[event.key]
		if (index !== undefined) { event.preventDefault(); go(index) }
	})
	listen(root, 'mouseenter', () => { hovered = true; schedule() })
	listen(root, 'mouseleave', () => { hovered = false; schedule() })
	listen(root, 'focusin', () => { focused = true; schedule() })
	listen(root, 'focusout', event => { focused = root.contains(event.relatedTarget); schedule() })
	listen(document, 'visibilitychange', schedule)
	listen(reducedMotion, 'change', schedule)
	const observer = new ResizeObserver(measure)
	observer.observe(track)
	measure()
	return () => { clearInterval(timer); observer.disconnect(); removers.forEach(remove => remove()) }
}

export function mountTestimonials() {
	return [...document.querySelectorAll('[data-testimonials]')].map(enhanceTestimonials)
}
