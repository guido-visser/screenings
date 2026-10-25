import { createAppConfig } from '@nextcloud/vite-config'

// Two bundles: the owner portal (inside Nextcloud) and the public viewer page.
// Output: js/screenings-portal.mjs and js/screenings-viewer.mjs
export default createAppConfig({
	portal: 'src/portal/main.js',
	viewer: 'src/viewer/main.js',
}, {
	// Inject each entry's CSS into that entry's own bundle (default puts it all in one).
	inlineCSS: { relativeCSSInjection: true },
})
