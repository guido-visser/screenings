import { mdiMovieOpenPlayOutline, mdiMovieOpenPlusOutline } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { FileType, Permission, registerFileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import api, { errorMessage } from '../portal/api.js'

// Nextcloud maps .m3u8 to audio/mpegurl; some servers use the Apple type.
const PLAYLIST_MIMES = ['audio/mpegurl', 'application/vnd.apple.mpegurl']

/**
 * File id => newest screening id, used to pick the menu label. Menus are
 * built synchronously, so this is a cache; actions re-check before acting.
 */
let screeningsBySource = {}

async function refreshScreenings() {
	try {
		screeningsBySource = await api.screeningsBySource()
	} catch {
		// Keep the previous map; the actions re-check anyway.
	}
	return screeningsBySource
}

refreshScreenings()
// Screenings may be created or deleted in another tab meanwhile.
document.addEventListener('visibilitychange', () => {
	if (document.visibilityState === 'visible') {
		refreshScreenings()
	}
})

/** The delivery mode for a node, or null when it can't become a screening. */
function deliveryMode(node) {
	if (PLAYLIST_MIMES.includes(node.mime) || node.basename.toLowerCase().endsWith('.m3u8')) {
		return 'hls'
	}
	// Auto plays what browsers can and converts the rest. Nextcloud has no
	// type for MXF; see ScreeningService::OTHER_VIDEO_EXTENSIONS.
	if (node.mime?.startsWith('video/') || node.basename.toLowerCase().endsWith('.mxf')) {
		return 'auto'
	}
	return null
}

/**
 * "film.mp4" becomes "film". HLS playlists are usually called "master.m3u8"
 * or "index.m3u8", so use the folder name for those instead.
 */
function defaultTitle(node, mode) {
	const name = node.basename.replace(/\.[^.]+$/, '')
	if (mode === 'hls' && /^(master|index|playlist|main)$/i.test(name)) {
		const folder = node.dirname.split('/').filter(Boolean).pop()
		return folder || name
	}
	return name
}

function isCandidate({ nodes, view }) {
	if (nodes.length !== 1 || view.id === 'trashbin') {
		return false
	}
	const node = nodes[0]
	return node.type === FileType.File
		&& (node.permissions & Permission.READ) !== 0
		&& deliveryMode(node) !== null
}

function hasScreening(node) {
	return screeningsBySource[node.fileid] !== undefined
}

function icon(path) {
	return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${path}"/></svg>`
}

/**
 * Opens the file's screening, creating it first if there is none. Both menu
 * entries use this, so a stale label never creates a duplicate or opens a
 * deleted screening.
 */
async function openOrCreate(node) {
	try {
		let id = (await refreshScreenings())[node.fileid]
		// Existing screenings open on their dashboard, new ones on their settings.
		let view = ''
		if (id === undefined) {
			const mode = deliveryMode(node)
			const screening = await api.createScreening({
				title: defaultTitle(node, mode),
				deliveryMode: mode,
				sourcePath: node.path,
			})
			id = screening.id
			view = '/settings'
		}
		window.location.href = generateUrl('/apps/screenings/') + `#/screenings/${id}${view}`
	} catch (error) {
		showError(errorMessage(error, t('screenings', 'Could not open the screening')))
	}
	return null
}

registerFileAction({
	id: 'screenings-create',
	displayName: () => t('screenings', 'Create screening'),
	iconSvgInline: () => icon(mdiMovieOpenPlusOutline),
	order: 50,
	enabled: (context) => isCandidate(context) && !hasScreening(context.nodes[0]),
	exec: ({ nodes }) => openOrCreate(nodes[0]),
})

registerFileAction({
	id: 'screenings-goto',
	displayName: () => t('screenings', 'Go to screening'),
	iconSvgInline: () => icon(mdiMovieOpenPlayOutline),
	order: 50,
	enabled: (context) => isCandidate(context) && hasScreening(context.nodes[0]),
	exec: ({ nodes }) => openOrCreate(nodes[0]),
})
