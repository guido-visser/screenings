/**
 * The portal's location lives in the URL hash:
 *   #/screenings/12              dashboard (default)
 *   #/screenings/12/settings     editor
 *   #/screenings/12/email        this screening's invitation email design
 *   #/screenings/12/notes        viewers' notes on the film (review page)
 *   #/screenings/12/notes/56     the same, opened at one note
 *   #/screenings/12/viewers/34   one viewer's activity
 *   #/email                      the owner's default invitation email design
 */
export function parseHash(hash = window.location.hash) {
	if (/^#\/email\/?$/.test(hash)) {
		return { screeningId: null, view: 'email', inviteId: null, noteId: null }
	}
	const match = hash.match(/^#\/screenings\/(\d+)(?:\/(settings|dashboard|email|notes)(?:\/(\d+))?|\/viewers\/(\d+))?/)
	if (!match) {
		return { screeningId: null, view: null, inviteId: null, noteId: null }
	}
	return {
		screeningId: Number(match[1]),
		view: match[4] ? 'viewer' : (match[2] ?? 'dashboard'),
		inviteId: match[4] ? Number(match[4]) : null,
		noteId: match[2] === 'notes' && match[3] ? Number(match[3]) : null,
	}
}

export function screeningHash(screeningId, view = 'dashboard', inviteId = null) {
	if (screeningId === null) {
		return view === 'email' ? '#/email' : '#/'
	}
	if (view === 'viewer') {
		return `#/screenings/${screeningId}/viewers/${inviteId}`
	}
	return view === 'dashboard' ? `#/screenings/${screeningId}` : `#/screenings/${screeningId}/${view}`
}

export function navigate(screeningId, view = 'dashboard', inviteId = null) {
	window.location.hash = screeningId === null && view !== 'email' ? '' : screeningHash(screeningId, view, inviteId)
}
