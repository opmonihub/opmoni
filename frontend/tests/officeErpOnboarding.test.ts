import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { describe, it } from 'node:test'
import { compileVueComponent, vueSource } from './helpers/compileVueComponent.ts'
import { officeErpCompanyListQuery } from '../app/utils/officeErpCompanyNavigation.ts'
import { officeErpSidebarItem } from '../app/utils/sidebarNav.ts'

describe('Company registration and commercial navigation SFCs', () => {
  it('compiles the real Company portfolio, registration, profile and access pages', () => {
    for (const path of [
      'pages/gestao-erp/empresas/index.vue',
      'pages/gestao-erp/empresas/nova.vue',
      'components/office-erp/OfficeErpCompanyPortfolioModal.vue',
      'pages/gestao-erp/assinaturas/index.vue',
      'pages/gestao-erp/assinaturas/empresas/[companyId].vue',
      'pages/gestao-erp/assinaturas/empresas/[companyId]/membros.vue'
    ]) assert.ok(compileVueComponent(path), path)
  })

  it('uses the Companies portfolio as the canonical route and retains safe legacy intent', () => {
    const items = officeErpSidebarItem('/gestao-erp/empresas/nova').children
    assert.deepEqual(items.filter(item => item.active).map(item => item.to), ['/gestao-erp/empresas'])
    assert.deepEqual(officeErpCompanyListQuery({ q: 'Cliente', pagina: '3', porPagina: '50', cliente: '22', token: 'ignore' }), {
      q: 'Cliente', pagina: '3', porPagina: '50', cliente: '22'
    })
    assert.equal(officeErpSidebarItem('/gestao-erp/assinaturas/123').children.find(item => item.label === 'Assinaturas')?.active, true)
  })

  it('registers portfolio clients as bounded batches without creating assignments', () => {
    const page = vueSource('pages/gestao-erp/empresas/nova.vue')
    const picker = vueSource('components/office-erp/OfficeErpCompanyPortfolioModal.vue')
    assert.match(page, /client_ids: selectableIds\(\)/)
    assert.match(page, /selectionLimit = 100/)
    assert.match(page, /'company\.register', '\/erp-commercial\/companies'/)
    assert.match(page, /result\.status === 'unknown'/)
    assert.match(page, /result\.status === 'failed'/)
    assert.match(picker, /maxSelection = 100/)
    assert.match(picker, /'indeterminate'/)
    assert.match(picker, /erp_registration/)
    assert.match(picker, /:disabled="!!row\.original\.erp_registration"/)
    assert.match(picker, /\/erp-commercial\/clients/)
    assert.doesNotMatch(page, /onboarding-preview|\/assignments/)
  })

  it('requires the office portfolio registration marker before a client can be selected again', () => {
    const portfolioClient = readFileSync(new URL('../shared/types/officeErpCommercial.ts', import.meta.url), 'utf8')
    const picker = vueSource('components/office-erp/OfficeErpCompanyPortfolioModal.vue')
    assert.match(portfolioClient, /erp_registration: \{ registration_id: number, company_id: number \} \| null/)
    assert.match(picker, /const eligibleRows = computed\(\(\) => rows\.value\.filter\(row => !row\.erp_registration\)\)/)
    assert.match(picker, /if \(rows\.value\.find\(row => row\.id === id\)\?\.erp_registration\) return/)
  })

  it('keeps profile writes limited to changed Company-owned fields and versioned PATCH', () => {
    const detail = vueSource('pages/gestao-erp/assinaturas/empresas/[companyId].vue')
    const editor = vueSource('components/office-erp/OfficeErpCompanyInspectionModal.vue')
    assert.match(detail, /<OfficeErpCompanyInspectionModal/)
    assert.match(editor, /expected_profile_version: expectedVersion/)
    assert.match(editor, /company\.profile\.update/)
    assert.match(editor, /response\.failure\.http_status === 409/)
    assert.match(editor, /'PATCH'/)
  })

  it('assigns using canonical decimal text and keeps owner setup and trial contract actions available separately', () => {
    const detail = vueSource('pages/gestao-erp/assinaturas/empresas/[companyId].vue')
    const assignment = vueSource('components/office-erp/OfficeErpCompanyAssignmentModal.vue')
    const contract = vueSource('pages/gestao-erp/assinaturas/[id].vue')
    assert.match(detail, /<OfficeErpCompanyAssignmentModal/)
    assert.match(detail, /@assigned="assignmentConfirmed"/)
    assert.match(assignment, /parseOfficeErpMoney\(price\.value\.trim\(\)\)/)
    assert.match(assignment, /currency: 'BRL'/)
    assert.match(assignment, /\/assignments/)
    assert.doesNotMatch(assignment, /v-model="price"[\s\S]{0,120}type="number"/)
    assert.match(detail, /\/owner-setup/)
    assert.match(detail, /subscription\.trial/)
    assert.match(detail, /Ver opções do período de teste/)
    assert.match(contract, /trial\.convert/)
  })
})
