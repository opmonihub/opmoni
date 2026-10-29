import { captureAccessContinuationFromLocation } from '~/utils/accessContinuationFragment'

export default defineNuxtPlugin(() => {
  captureAccessContinuationFromLocation('member-invitation')
  captureAccessContinuationFromLocation('password-recovery')
})
