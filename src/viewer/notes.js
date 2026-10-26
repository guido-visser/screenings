import { t } from '@nextcloud/l10n'
import { reactive } from 'vue'

/**
 * The viewer's own notes, shared by the player (adding, markers) and the
 * list under it (editing, deleting, jumping).
 *
 * @param {?string} url the session's notes endpoint; null when the owner
 *   didn't ask this invite for notes
 */
export function createNotes(url) {
	const state = reactive({
		enabled: Boolean(url),
		items: [],
		loaded: false,
	})

	async function request(method, path = '', body = undefined) {
		let response
		try {
			response = await fetch(url + path, {
				method,
				credentials: 'same-origin',
				headers: body === undefined ? {} : { 'Content-Type': 'application/json' },
				body: body === undefined ? undefined : JSON.stringify(body),
			})
		} catch {
			throw new Error(t('screenings', 'No connection. Your note was not saved; try again.'))
		}
		const data = await response.json().catch(() => ({}))
		if (!response.ok) {
			throw new Error(data.message ?? t('screenings', 'Something went wrong. Try again.'))
		}
		return data
	}

	function sort() {
		state.items.sort((a, b) => a.position - b.position || a.id - b.id)
	}

	state.load = async () => {
		if (!state.enabled) {
			return
		}
		state.items = await request('GET')
		state.loaded = true
	}

	state.add = async (position, body) => {
		const note = await request('POST', '', { position, body })
		state.items.push(note)
		sort()
		return note
	}

	state.update = async (id, body) => {
		const note = await request('PUT', '/' + id, { body })
		state.items = state.items.map((n) => n.id === id ? note : n)
		return note
	}

	state.remove = async (id) => {
		await request('DELETE', '/' + id)
		state.items = state.items.filter((n) => n.id !== id)
	}

	return state
}
