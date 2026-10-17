import assert from 'node:assert/strict'
import { describe, it } from 'node:test'
import {
  erpErrorCodes,
  erpRoutePrefixes,
  sampleCompanyOnlyIdentity,
  sampleOfficeFirstIdentity
} from '../shared/fixtures/erpFoundationContracts.ts'
import { accessDestination, focusAccessDestination } from '../shared/utils/access.ts'
import { erpCompanySelection, erpOperatingApiPath } from '../shared/utils/erp.ts'

describe('ERP foundation shared contracts', () => {
  it('freezes error code strings for client handling', () => {
    assert.equal(erpErrorCodes.companyContextChanged, 'company_context_changed')
    assert.equal(erpErrorCodes.erpOperatingDisabled, 'erp_operating_disabled')
  })

  it('company-only identity routes to /erp without office membership', () => {
    assert.equal(accessDestination(sampleCompanyOnlyIdentity), '/erp')
    assert.equal(focusAccessDestination(sampleCompanyOnlyIdentity, '/erp/configuracoes'), null)
  })

  it('office-first identity keeps home default; explicit ERP navigation stays on ERP paths', () => {
    assert.equal(accessDestination(sampleOfficeFirstIdentity), '/')
    assert.equal(focusAccessDestination(sampleOfficeFirstIdentity, '/erp'), null)
  })

  it('operating API paths require company assertion except access/setup routes', () => {
    assert.equal(erpOperatingApiPath(erpRoutePrefixes.operating), true)
    assert.equal(erpOperatingApiPath(erpRoutePrefixes.settings), true)
    assert.equal(erpOperatingApiPath(erpRoutePrefixes.switch), false)
    assert.equal(erpOperatingApiPath(erpRoutePrefixes.delegations), false)
  })

  it('erpCompanySelection returns active current company only', () => {
    assert.equal(erpCompanySelection(sampleCompanyOnlyIdentity)?.id, 42)
    assert.equal(erpCompanySelection({ ...sampleCompanyOnlyIdentity, current_company: null }), null)
  })
})
