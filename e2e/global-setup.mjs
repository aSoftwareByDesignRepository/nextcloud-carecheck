import { acquireSharedInstanceLock } from './shared-instance-lock.mjs'
import { resetE2eUserState } from '../../_shared/e2e/reset-e2e-user-state.mjs'

export default async function globalSetup() {
	await acquireSharedInstanceLock('carecheck')
	resetE2eUserState()
}
