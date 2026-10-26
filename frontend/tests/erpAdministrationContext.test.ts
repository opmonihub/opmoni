import assert from 'node:assert/strict'
import { test } from 'node:test'
import * as Vue from 'vue'
import { loadAppComposable } from './helpers/loadAppComposable.ts'

for (const transition of ['office-role', 'office-status', 'company-role', 'company-grants', 'company-status', 'delegated-office']) {
  test(`administration tokens invalidate synchronously when effective ${transition} changes independently of selected metadata`, () => {
    const office = { id: 3, role: 'admin', status: 'active' }
    const company = { id: 7, role: 'owner', status: 'active', access_kind: 'direct', delegated_office_id: null as number | null, permissions: ['erp.read', 'erp.write'] }
    const user = Vue.ref({ id: 91, accounts: [{ ...office }], companies: [{ ...company, permissions: [...company.permissions] }] })
    const currentAccount = Vue.ref({ ...office })
    const currentCompany = Vue.ref({ ...company })
    const generation = Vue.ref(1)
    const { useErpAdministrationContext } = loadAppComposable<{ useErpAdministrationContext: () => { key: Vue.Ref<string>, capture: () => { key: string, generation: number }, isCurrent: (token: { key: string, generation: number }) => boolean } }>('composables/useErpAdministrationContext.ts', {
      ref: Vue.ref, computed: Vue.computed, watch: Vue.watch, onScopeDispose: Vue.onScopeDispose,
      useAuth: () => ({ user, currentAccount, currentCompany }), useState: () => generation
    })
    const scope = Vue.effectScope()
    const context = scope.run(useErpAdministrationContext)!
    try {
      const token = context.capture()
      assert.equal(context.isCurrent(token), true)
      if (transition === 'office-role') user.value.accounts[0]!.role = 'user'
      if (transition === 'office-status') user.value.accounts[0]!.status = 'suspended'
      if (transition === 'company-role') user.value.companies[0]!.role = 'viewer'
      if (transition === 'company-grants') user.value.companies[0]!.permissions = []
      if (transition === 'company-status') user.value.companies[0]!.status = 'suspended'
      if (transition === 'delegated-office') user.value.companies[0]!.delegated_office_id = 9
      assert.equal(context.isCurrent(token), false)
      assert.notEqual(context.key.value, token.key)
      assert.deepEqual(currentAccount.value, office)
      assert.deepEqual(currentCompany.value, company)
    } finally { scope.stop() }
  })
}
