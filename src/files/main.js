import { mdiMovieOpenPlusOutline } from '@mdi/js'
import { showError } from '@nextcloud/dialogs'
import { FileType, Permission, registerFileAction } from '@nextcloud/files'
import { t } from '@nextcloud/l10n'
import { generateUrl } from '@nextcloud/router'
import api, { errorMessage } from '../portal/api.js'

// Nextcloud maps .m3u8 to audio/mpegurl; some servers use the Apple type.
const PLAYLIST_MIMES = ['audio/mpegurl', 'application/vnd.apple.mpegurl']

/** The delivery mode for a node, or null when it can't become a screening. */
function deliveryMode(node) {
	if (node.mime === 'video/mp4') {
		return 'mp4'
	}
	if (PLAYLIST_MIMES.includes(node.mime) || node.basename.toLowerCase().endsWith('.m3u8')) {
		return 'hls'
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

registerFileAction({
	id: 'screenings-create',
	displayName: () => t('screenings', 'Create screening'),
	iconSvgInline: () => `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><path d="${mdiMovieOpenPlusOutline}"/></svg>`,
	order: 50,

	enabled({ nodes, view }) {
		if (nodes.length !== 1 || view.id === 'trashbin') {
			return false
		}
		const node = nodes[0]
		return node.type === FileType.File
			&& (node.permissions & Permission.READ) !== 0
			&& deliveryMode(node) !== null
	},

	async exec({ nodes }) {
		const node = nodes[0]
		const mode = deliveryMode(node)
		try {
			const screening = await api.createScreening({
				title: defaultTitle(node, mode),
				deliveryMode: mode,
				sourcePath: node.path,
			})
			window.location.href = generateUrl('/apps/screenings/') + `#/screenings/${screening.id}`
			return null
		} catch (error) {
			showError(errorMessage(error, t('screenings', 'Could not create the screening')))
			return null
		}
	},
})
