/**
 * Runs `tick` every `ms` while the page is visible, and once right away when
 * it becomes visible again. A tick still running delays the next one, so a
 * slow server never gets overlapping requests.
 *
 * @param {() => Promise<void>} tick the refresh
 * @param {number} ms interval
 * @return {() => void} stops polling
 */
export function startPolling(tick, ms) {
	let timer = null
	let running = false
	let stopped = false

	async function run() {
		window.clearTimeout(timer)
		if (stopped || document.hidden || running) {
			return
		}
		running = true
		try {
			await tick()
		} catch {
			// Try again on the next tick.
		} finally {
			running = false
			if (!stopped) {
				timer = window.setTimeout(run, ms)
			}
		}
	}

	function onVisibility() {
		if (!document.hidden) {
			run()
		}
	}

	document.addEventListener('visibilitychange', onVisibility)
	timer = window.setTimeout(run, ms)
	return () => {
		stopped = true
		window.clearTimeout(timer)
		document.removeEventListener('visibilitychange', onVisibility)
	}
}
