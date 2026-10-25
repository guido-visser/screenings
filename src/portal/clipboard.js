import { showError, showSuccess } from '@nextcloud/dialogs'
import { t } from '@nextcloud/l10n'

export async function copyLink(link) {
	try {
		await navigator.clipboard.writeText(link)
		showSuccess(t('screenings', 'Link copied'))
	} catch {
		// Clipboard API needs a secure context; fall back to showing the link.
		showError(t('screenings', 'Could not copy automatically. The link is: {link}', { link }))
	}
}
