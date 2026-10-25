import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'

const url = (token, path) => generateUrl('/apps/screenings/s/{token}', { token }) + path

/** The server's error message, or a fallback. */
export const errorMessage = (error, fallback) => error?.response?.data?.message ?? fallback

export default {
	async unlock(token, password) {
		await axios.post(url(token, '/password'), { password })
	},
	async requestCode(token, name, email) {
		await axios.post(url(token, '/code'), { name, email })
	},
	/** @return {Promise<string>} the viewer's personal link */
	async verifyCode(token, email, code) {
		return (await axios.post(url(token, '/code/verify'), { email, code })).data.url
	},
}
