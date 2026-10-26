import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (path) => generateUrl('/apps/screenings/api' + path)

/** Absolute public URL for a screening token (invite or open link). */
export const publicLink = (token) => window.location.origin + generateUrl('/apps/screenings/s/{token}', { token })

/** The server's error message, or a fallback. */
export const errorMessage = (error, fallback) => error?.response?.data?.message ?? fallback

export default {
	async listScreenings() {
		return (await axios.get(url('/screenings'))).data
	},
	/** @return {Promise<Object<string, number>>} file id => newest screening id */
	async screeningsBySource() {
		return (await axios.get(url('/screenings/by-source'))).data
	},
	async getScreening(id) {
		return (await axios.get(url(`/screenings/${id}`))).data
	},
	async createScreening(data) {
		return (await axios.post(url('/screenings'), data)).data
	},
	async updateScreening(id, data) {
		return (await axios.put(url(`/screenings/${id}`), data)).data
	},
	async deleteScreening(id) {
		await axios.delete(url(`/screenings/${id}`))
	},
	async reprocessMedia(id) {
		return (await axios.post(url(`/screenings/${id}/media/reprocess`))).data
	},
	async regenerateOpenLink(id) {
		return (await axios.post(url(`/screenings/${id}/open-link/regenerate`))).data
	},

	async uploadSubtitle(screeningId, file, lang, label) {
		const form = new FormData()
		form.append('file', file)
		form.append('lang', lang)
		form.append('label', label)
		return (await axios.post(url(`/screenings/${screeningId}/subtitles`), form)).data
	},
	async importSubtitles(screeningId) {
		return (await axios.post(url(`/screenings/${screeningId}/subtitles/import`))).data
	},
	async updateSubtitle(id, data) {
		return (await axios.put(url(`/subtitles/${id}`), data)).data
	},
	async deleteSubtitle(id) {
		await axios.delete(url(`/subtitles/${id}`))
	},

	/** The result has `mailError` when the invite was created but the email failed. */
	async dashboard(screeningId) {
		return (await axios.get(url(`/screenings/${screeningId}/dashboard`))).data
	},
	async live(screeningId) {
		return (await axios.get(url(`/screenings/${screeningId}/live`))).data
	},
	async viewerActivity(inviteId, before = null) {
		return (await axios.get(url(`/invites/${inviteId}/activity`), { params: before ? { before } : {} })).data
	},

	async createInvite(screeningId, name, email, sendEmail = false, message = '') {
		return (await axios.post(url(`/screenings/${screeningId}/invites`), { name, email, sendEmail, message })).data
	},
	async sendInvite(id, message = '') {
		return (await axios.post(url(`/invites/${id}/send`), { message })).data
	},
	async setInviteRevoked(id, revoked) {
		return (await axios.post(url(`/invites/${id}/${revoked ? 'revoke' : 'restore'}`))).data
	},
	async deleteInvite(id) {
		await axios.delete(url(`/invites/${id}`))
	},
}
