/**
 * Keeps the viewer session alive and the playback token fresh.
 *
 * Every few seconds (and right away on seeks) the player reports its state.
 * The server answers with a new short-lived token, which goes into the
 * header of every HLS request, or with the reason playback must stop.
 */
export class Heartbeat {
	/**
	 * @param {object} playback the server's player config
	 * @param {() => object} getState current player state for the server
	 * @param {(reason: string) => void} onStop called once when the server refuses
	 */
	constructor(playback, getState, onStop) {
		this.url = playback.heartbeatUrl
		this.intervalMs = playback.heartbeatSeconds * 1000
		this.token = playback.token
		this.getState = getState
		this.onStop = onStop
		this.timer = null
		this.stopped = false
		this.inFlight = null
	}

	start() {
		this.schedule()
	}

	schedule() {
		window.clearTimeout(this.timer)
		if (!this.stopped) {
			this.timer = window.setTimeout(() => this.beat(), this.intervalMs)
		}
	}

	/** Sends a heartbeat now; concurrent calls share one request. */
	async beat() {
		if (this.stopped) {
			return
		}
		if (this.inFlight) {
			return this.inFlight
		}
		this.inFlight = this.send().finally(() => {
			this.inFlight = null
			this.schedule()
		})
		return this.inFlight
	}

	async send() {
		let response
		try {
			response = await fetch(this.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(this.getState()),
			})
		} catch {
			// Offline for a moment: try again on the next tick.
			return
		}
		const data = await response.json().catch(() => ({}))
		if (response.ok && data.status === 'ok') {
			this.token = data.token
		} else if (data.status) {
			this.stop()
			this.onStop(data.status)
		}
	}

	stop() {
		this.stopped = true
		window.clearTimeout(this.timer)
	}
}

export function formatTime(seconds) {
	if (!Number.isFinite(seconds) || seconds < 0) {
		seconds = 0
	}
	const total = Math.floor(seconds)
	const h = Math.floor(total / 3600)
	const m = Math.floor((total % 3600) / 60)
	const s = String(total % 60).padStart(2, '0')
	return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}
