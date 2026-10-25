import { loadState } from '@nextcloud/initial-state'
import './viewer.css'

// Phase 1 stub. The real flow (password/OTP, player, tracking) lands in phases 4-6.
const token = loadState('screenings', 'token')
const root = document.getElementById('screenings-viewer')
root.innerHTML = '<p class="screenings-viewer__placeholder">Screening <code></code> is not available yet.</p>'
root.querySelector('code').textContent = token
