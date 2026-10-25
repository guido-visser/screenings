import { createAppConfig } from '@nextcloud/vite-config'

// Bundles: the owner portal, the public viewer page, the file action loaded
// into the Files app, and the admin settings. Output: js/screenings-<name>.mjs
export default createAppConfig({
	portal: 'src/portal/main.js',
	viewer: 'src/viewer/main.js',
	files: 'src/files/main.js',
	admin: 'src/admin/main.js',
}, {
	// Inject each entry's CSS into that entry's own bundle (default puts it all in one).
	inlineCSS: { relativeCSSInjection: true },
})
