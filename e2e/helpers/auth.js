/**
 * CareCheck login helper — request-context login so the session
 * cookie lands in the page's own jar (same trick as tourcheck/whistlecheck e2e).
 */
export async function login(page, { username, password }) {
  const base = (process.env.NC_BASE_URL || 'http://localhost:8081').replace(/\/$/, '')
  const request = page.context().request
  const loginPage = await request.get(`${base}/login`)
  const html = await loginPage.text()
  const tokenMatch = html.match(/data-requesttoken="([^"]+)"/)
  if (!tokenMatch) {
    throw new Error('Login page did not yield a requesttoken')
  }
  const requesttoken = decodeURIComponent(tokenMatch[1])
  const res = await request.post(`${base}/login`, {
    form: { user: username, password, requesttoken },
    headers: { Origin: process.env.NC_BASE_URL || 'http://localhost:8081' },
    maxRedirects: 0,
  })
  const location = res.headers()['location'] || ''
  if (res.status() !== 303 || location.startsWith('/login')) {
    throw new Error(`Login rejected for "${username}"`)
  }
}
