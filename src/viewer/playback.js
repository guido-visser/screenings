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
	 * @param {?(state: object) => void} onUnsent gets the state of a heartbeat that didn't arrive
	 */
	constructor(playback, getState, onStop, onUnsent = null) {
		this.onUnsent = onUnsent
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

	/**
	 * Like beat(), but the state is read after any request already in flight,
	 * so the server is sure to get the state as it is now.
	 */
	async beatFresh() {
		await this.inFlight
		return this.beat()
	}

	async send() {
		const state = this.getState()
		let response
		try {
			response = await fetch(this.url, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify(state),
			})
		} catch {
			// Offline for a moment: keep what wasn't delivered, try again next tick.
			this.onUnsent?.(state)
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

	/** A last heartbeat that survives the page closing (no answer needed). */
	final() {
		if (!this.stopped && navigator.sendBeacon) {
			navigator.sendBeacon(this.url, new Blob([JSON.stringify(this.getState())], { type: 'application/json' }))
		}
	}

	stop() {
		this.stopped = true
		window.clearTimeout(this.timer)
	}
}

/**
 * Queues player events and sends them in batches. On page leave the rest go
 * out with navigator.sendBeacon, which survives the page closing.
 */
export class Tracker {
	static FLUSH_MS = 10000
	static FLUSH_AT = 20
	static MAX_QUEUE = 200

	constructor(eventsUrl) {
		this.url = eventsUrl
		this.queue = []
		this.timer = window.setInterval(() => this.flush(), Tracker.FLUSH_MS)
	}

	/**
	 * @param {string} type one of the server's whitelisted event types
	 * @param {?number} position playhead in seconds
	 * @param {object} data small payload (whitelisted per type on the server)
	 */
	track(type, position = null, data = undefined) {
		this.queue.push({ type, position, ts: Date.now(), data })
		if (this.queue.length > Tracker.MAX_QUEUE) {
			this.queue.splice(0, this.queue.length - Tracker.MAX_QUEUE)
		}
		if (this.queue.length >= Tracker.FLUSH_AT) {
			this.flush()
		}
	}

	flush(beacon = false) {
		if (this.queue.length === 0) {
			return
		}
		// The server takes at most 50 per request.
		while (this.queue.length) {
			const batch = this.queue.splice(0, 50)
			const body = JSON.stringify({ events: batch })
			if (beacon && navigator.sendBeacon) {
				navigator.sendBeacon(this.url, new Blob([body], { type: 'application/json' }))
				continue
			}
			fetch(this.url, {
				method: 'POST',
				credentials: 'same-origin',
				keepalive: true,
				headers: { 'Content-Type': 'application/json' },
				body,
			}).then((response) => {
				if (!response.ok && response.status >= 500) {
					this.queue.unshift(...batch)
				}
			}).catch(() => {
				this.queue.unshift(...batch)
			})
		}
	}

	stop() {
		window.clearInterval(this.timer)
		this.flush(true)
	}
}

/**
 * Records which intervals of the film were actually played (not skipped).
 * The heartbeat sends what was played since the previous heartbeat.
 */
export class RangeRecorder {
	constructor() {
		this.closed = []
		this.start = null
		this.last = null
	}

	/** Call on every timeupdate while playing. */
	update(time) {
		if (this.start === null) {
			this.start = time
		} else if (time < this.last - 0.5 || time > this.last + 2) {
			// A jump (seek or stall recovery): the previous stretch ends there.
			this.close()
			this.start = time
		}
		this.last = time
	}

	/** Call on pause, seeking, waiting and ended. */
	close() {
		if (this.start !== null && this.last !== null && this.last > this.start) {
			this.closed.push([round(this.start), round(this.last)])
		}
		this.start = null
		this.last = null
	}

	/** Puts back ranges a failed heartbeat couldn't deliver. */
	restore(ranges) {
		this.closed.unshift(...(ranges ?? []))
	}

	/** Everything played since the last call; a running stretch continues. */
	take() {
		const ranges = this.closed
		this.closed = []
		if (this.start !== null && this.last > this.start) {
			ranges.push([round(this.start), round(this.last)])
			this.start = this.last
		}
		return ranges
	}
}

const round = (seconds) => Math.round(seconds * 10) / 10

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
