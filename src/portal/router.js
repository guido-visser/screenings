/**
 * The portal's location lives in the URL hash:
 *   #/screenings/12              dashboard (default)
 *   #/screenings/12/settings     editor
 *   #/screenings/12/email        this screening's invitation email design
 *   #/screenings/12/viewers/34   one viewer's activity
 *   #/email                      the owner's default invitation email design
 */
export function parseHash(hash = window.location.hash) {
	if (/^#\/email\/?$/.test(hash)) {
		return { screeningId: null, view: 'email', inviteId: null }
	}
	const match = hash.match(/^#\/screenings\/(\d+)(?:\/(settings|dashboard|email)|\/viewers\/(\d+))?/)
	if (!match) {
		return { screeningId: null, view: null, inviteId: null }
	}
	return {
		screeningId: Number(match[1]),
		view: match[3] ? 'viewer' : (match[2] ?? 'dashboard'),
		inviteId: match[3] ? Number(match[3]) : null,
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
