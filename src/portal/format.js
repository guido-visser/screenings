import { formatRelativeTime, getLanguage, n, t } from '@nextcloud/l10n'

export function formatDuration(seconds) {
	if (seconds === null || seconds === undefined || !Number.isFinite(seconds)) {
		return '–'
	}
	const total = Math.round(seconds)
	const h = Math.floor(total / 3600)
	const m = Math.floor((total % 3600) / 60)
	const s = String(total % 60).padStart(2, '0')
	return h > 0 ? `${h}:${String(m).padStart(2, '0')}:${s}` : `${m}:${s}`
}

export function formatRelative(unixSeconds) {
	return unixSeconds ? formatRelativeTime(unixSeconds * 1000) : t('screenings', 'never')
}

export function formatDateTime(ms) {
	return new Date(ms).toLocaleString(getLanguage(), { dateStyle: 'medium', timeStyle: 'medium' })
}

let regionNames = null
export function countryName(code) {
	if (!code) {
		return null
	}
	try {
		regionNames ??= new Intl.DisplayNames([getLanguage()], { type: 'region' })
		return regionNames.of(code)
	} catch {
		return code
	}
}

export function location(device) {
	const country = countryName(device?.country)
	return [device?.city, country].filter(Boolean).join(', ') || null
}

export function deviceText(device) {
	if (!device) {
		return null
	}
	return [device.browser, device.os].filter((part) => part && part !== 'Other').join(' · ') || null
}

/** Event types shown in the timeline, grouped for the filter chips. */
export const EVENT_GROUPS = {
	playback: ['play', 'pause', 'seeked', 'ended', 'subtitle_change', 'quality_change', 'volume_change', 'fullscreen_enter', 'fullscreen_exit'],
	attention: ['visibility_hidden', 'visibility_visible', 'window_blur', 'window_focus', 'page_open', 'page_leave', 'session_start'],
	access: ['password_ok', 'password_fail', 'otp_requested', 'otp_verified', 'otp_fail', 'access_denied'],
	feedback: ['note_added', 'questions_answered'],
	technical: ['player_ready', 'buffering_start', 'buffering_end', 'error', 'playback_stopped'],
}

export function eventGroup(type) {
	return Object.keys(EVENT_GROUPS).find((group) => EVENT_GROUPS[group].includes(type)) ?? 'technical'
}

const STOP_REASONS = {
	replaced: () => t('screenings', 'opened elsewhere'),
	revoked: () => t('screenings', 'access revoked'),
	expired: () => t('screenings', 'screening ended'),
	stale: () => t('screenings', 'connection lost'),
	watermark: () => t('screenings', 'page was modified'),
	unsupported: () => t('screenings', 'browser not supported'),
}

/** A one-line, human description of a tracking event. */
export function eventText(event) {
	const d = event.data ?? {}
	switch (event.type) {
	case 'page_open': return t('screenings', 'Opened the link')
	case 'session_start': return t('screenings', 'Started a viewing session')
	case 'access_denied': return t('screenings', 'Was refused access ({reason})', { reason: d.reason ?? '?' })
	case 'password_ok': return t('screenings', 'Entered the correct password')
	case 'password_fail': return t('screenings', 'Entered a wrong password')
	case 'otp_requested': return t('screenings', 'Requested an email code')
	case 'otp_verified': return t('screenings', 'Confirmed their email address')
	case 'otp_fail': return t('screenings', 'Entered a wrong email code')
	case 'player_ready': return d.screen ? t('screenings', 'Player loaded (screen {w}×{h})', { w: d.screen[0], h: d.screen[1] }) : t('screenings', 'Player loaded')
	case 'play': return t('screenings', 'Played')
	case 'pause': return t('screenings', 'Paused')
	case 'seeked': return t('screenings', 'Jumped from {from} to {to}', { from: formatDuration(d.from), to: formatDuration(d.to) })
	case 'ended': return t('screenings', 'Reached the end')
	case 'subtitle_change': return d.to ? t('screenings', 'Turned subtitles to {lang}', { lang: d.to }) : t('screenings', 'Turned subtitles off')
	case 'quality_change': return d.auto
		? t('screenings', 'Quality changed automatically to {to}', { to: d.to ?? '?' })
		: t('screenings', 'Chose quality {to}', { to: d.to ?? '?' })
	case 'volume_change': return d.muted ? t('screenings', 'Muted') : t('screenings', 'Set volume to {volume}%', { volume: Math.round((d.volume ?? 0) * 100) })
	case 'fullscreen_enter': return t('screenings', 'Went full screen')
	case 'fullscreen_exit': return t('screenings', 'Left full screen')
	case 'buffering_start': return t('screenings', 'Playback stalled (buffering)')
	case 'buffering_end': return t('screenings', 'Resumed after buffering for {seconds} s', { seconds: d.seconds ?? '?' })
	case 'error': return t('screenings', 'Player error: {reason}', { reason: d.reason ?? '?' })
	case 'visibility_hidden': return t('screenings', 'Switched to another tab or app')
	case 'visibility_visible': return t('screenings', 'Came back to the tab')
	case 'window_blur': return t('screenings', 'Window lost focus')
	case 'window_focus': return t('screenings', 'Window regained focus')
	case 'page_leave': return t('screenings', 'Left the page')
	case 'note_added': return d.text
		? t('screenings', 'Left a note: {text}', { text: d.text })
		: t('screenings', 'Left a note (since deleted)')
	case 'questions_answered': return n('screenings', 'Answered {count} closing question', 'Answered {count} closing questions', d.answered ?? 0, { count: d.answered ?? 0 })
	case 'playback_stopped': return t('screenings', 'Playback was stopped: {reason}', { reason: STOP_REASONS[d.reason]?.() ?? d.reason ?? '?' })
	default: return event.type
	}
}
