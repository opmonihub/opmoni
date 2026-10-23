import { computed, onScopeDispose, ref, watch } from 'vue'
import type {
  ErpCatalogLabelResponse,
  ErpCatalogPrintLayout,
  ErpCatalogPrinterProfile,
  ErpCatalogPrintSettings
} from '#shared/types/erpCatalogs'
import { defaultErpCatalogPrintSettings, erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import { erpLabelSelectionKey, setErpLabelSelection, totalErpLabelCopies, updateErpLabelQuantity } from '#shared/utils/erpCatalogLabels'
import { apiFailure } from '#shared/utils/access'
import { renderErpCatalogLabelOutput } from '~/utils/erpCatalogLabelRenderer'
import { useErpAdministrationContext } from '~/composables/useErpAdministrationContext'
import { erpCompanyHasPermission } from '~/utils/erpModulesNav'

export type ErpCatalogLabelLayoutDraft = {
  name: string
  description: string
  label_width_mm: string
  label_height_mm: string
  paper_width_mm: string
  paper_height_mm: string
  top_margin_mm: string
  left_margin_mm: string
  row_spacing_mm: string
  column_spacing_mm: string
  columns_per_row: number
  labels_per_sheet: number
  is_continuous: boolean
}

export type ErpCatalogPrinterProfileDraft = {
  name: string
  capability_profile: ErpCatalogPrinterProfile['capability_profile']
}

const defaultLayoutDraft = (): ErpCatalogLabelLayoutDraft => ({
  name: '',
  description: '',
  label_width_mm: '50.0000',
  label_height_mm: '30.0000',
  paper_width_mm: '50.0000',
  paper_height_mm: '30.0000',
  top_margin_mm: '0.0000',
  left_margin_mm: '0.0000',
  row_spacing_mm: '0.0000',
  column_spacing_mm: '0.0000',
  columns_per_row: 1,
  labels_per_sheet: 1,
  is_continuous: false
})

const defaultProfileDraft = (): ErpCatalogPrinterProfileDraft => ({ name: '', capability_profile: 'default' })

function copySettings(value: ErpCatalogPrintSettings): ErpCatalogPrintSettings {
  return { ...value }
}

function copyLayoutDraft(value: ErpCatalogLabelLayoutDraft): ErpCatalogLabelLayoutDraft {
  return { ...value }
}

export function useErpCatalogLabels() {
  const { api, refreshCsrf } = useAccessApi()
  const auth = useAuth()
  const context = useErpAdministrationContext()
  const { user, currentCompany, currentAccount } = auth
  const settings = ref<ErpCatalogPrintSettings>(copySettings(defaultErpCatalogPrintSettings))
  const settingsBaseline = ref<ErpCatalogPrintSettings>(copySettings(defaultErpCatalogPrintSettings))
  const layouts = ref<ErpCatalogPrintLayout[]>([])
  const printerProfiles = ref<ErpCatalogPrinterProfile[]>([])
  const selectedLayoutId = ref<number | null>(null)
  const selectedLines = ref<Array<{ product_id: number, variant_id?: number, quantity: number }>>([])
  const layoutEditingId = ref<number | null>(null)
  const layoutDraft = ref<ErpCatalogLabelLayoutDraft>(defaultLayoutDraft())
  const layoutBaseline = ref<ErpCatalogLabelLayoutDraft>(defaultLayoutDraft())
  const profileEditingId = ref<number | null>(null)
  const profileDraft = ref<ErpCatalogPrinterProfileDraft>(defaultProfileDraft())
  const profileBaseline = ref<ErpCatalogPrinterProfileDraft>(defaultProfileDraft())
  const loadingConfiguration = ref(false)
  const mutationBusy = ref(false)
  const refreshingAuthority = ref(false)
  const savingSettings = ref(false)
  const savingLayout = ref(false)
  const savingProfile = ref(false)
  const pendingDefaultId = ref<number | null>(null)
  const pendingArchiveId = ref<number | null>(null)
  const error = ref('')
  const fieldErrors = ref<Record<string, string[]>>({})
  const fieldErrorTarget = ref<'settings' | 'layout' | 'profile' | 'output' | null>(null)
  const settingsError = ref('')
  const layoutError = ref('')
  const profileError = ref('')
  const outputError = ref('')
  const outputPending = ref(false)
  const outputUrl = ref('')
  const outputFilename = ref('etiquetas-produtos.html')
  const outputCopies = ref(0)
  const outputPages = ref(0)
  const outputMeta = ref<ErpCatalogLabelResponse['meta'] | null>(null)
  const blockedContextKey = ref<string | null>(null)

  const settingsDirty = computed(() => JSON.stringify(settings.value) !== JSON.stringify(settingsBaseline.value))
  const layoutDirty = computed(() => JSON.stringify(layoutDraft.value) !== JSON.stringify(layoutBaseline.value))
  const profileDirty = computed(() => JSON.stringify(profileDraft.value) !== JSON.stringify(profileBaseline.value))
  const selectedCopies = computed(() => totalErpLabelCopies(selectedLines.value))
  const selectionOverLimit = computed(() => selectedLines.value.length > 100 || selectedCopies.value > 250)
  const activeLayouts = computed(() => layouts.value.filter(layout => layout.status === 'active'))
  const authorityBlocked = computed(() => blockedContextKey.value === context.key.value)

  function hasPermission(permission: string, write: boolean): boolean {
    const actor = user.value
    const company = currentCompany.value
    if (!actor?.id || !company || company.status !== 'active')
      return false
    if (company.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== company.delegated_office_id)
        return false
      if (write && !['admin', 'operador'].includes(office.role))
        return false
      return erpCompanyHasPermission(company.permissions, permission)
    }
    if (company.access_kind !== 'direct' || (write && !['owner', 'manager', 'operator'].includes(company.role)))
      return false
    return erpCompanyHasPermission(company.permissions, permission)
  }

  const canReadProducts = computed(() => hasPermission('erp.products.index', false))
  const canGenerate = computed(() => hasPermission('erp.catalog.product-labels', true))
  const canReadSettings = computed(() => hasPermission('erp.catalog.print-settings.show', false))
  const canSaveSettings = computed(() => hasPermission('erp.catalog.print-settings.update', true))
  const canReadLayouts = computed(() => hasPermission('erp.catalog.label-layouts.index', false))
  const canCreateLayout = computed(() => hasPermission('erp.catalog.label-layouts.store', true))
  const canUpdateLayout = computed(() => hasPermission('erp.catalog.label-layouts.update', true))
  const canSaveLayouts = computed(() => hasPermission('erp.catalog.label-layouts.store', true) || hasPermission('erp.catalog.label-layouts.update', true))
  const canSetLayoutDefault = computed(() => hasPermission('erp.catalog.label-layouts.default', true))
  const canArchiveLayouts = computed(() => hasPermission('erp.catalog.label-layouts.archive', true))
  const canReadProfiles = computed(() => hasPermission('erp.catalog.printer-profiles.index', false))
  const canCreateProfile = computed(() => hasPermission('erp.catalog.printer-profiles.store', true))
  const canUpdateProfile = computed(() => hasPermission('erp.catalog.printer-profiles.update', true))
  const canSaveProfiles = computed(() => hasPermission('erp.catalog.printer-profiles.store', true) || hasPermission('erp.catalog.printer-profiles.update', true))
  const canArchiveProfiles = computed(() => hasPermission('erp.catalog.printer-profiles.archive', true))
  const defaultLayout = computed(() => activeLayouts.value.find(layout => layout.is_default) ?? null)

  let configurationSequence = 0
  let configurationController: AbortController | null = null
  let mutationSequence = 0
  let mutationController: AbortController | null = null
  let outputSequence = 0
  let outputController: AbortController | null = null
  let disposed = false

  function isCurrent(token: ReturnType<typeof context.capture>, sequence: number, currentSequence: number): boolean {
    return !disposed && sequence === currentSequence && context.isCurrent(token)
  }

  function revokeOutput(): void {
    if (outputUrl.value)
      URL.revokeObjectURL(outputUrl.value)
    outputUrl.value = ''
    outputCopies.value = 0
    outputPages.value = 0
    outputMeta.value = null
  }

  function invalidateOutput(clearError = true): void {
    outputSequence++
    outputController?.abort()
    outputController = null
    outputPending.value = false
    revokeOutput()
    if (clearError)
      outputError.value = ''
  }

  function invalidateConfiguration(): void {
    configurationSequence++
    configurationController?.abort()
    configurationController = null
    loadingConfiguration.value = false
  }

  function beginMutation(): { sequence: number, token: ReturnType<typeof context.capture>, controller: AbortController } | null {
    if (mutationBusy.value || authorityBlocked.value || disposed)
      return null
    invalidateConfiguration()
    invalidateOutput()
    mutationSequence++
    const controller = new AbortController()
    mutationController = controller
    mutationBusy.value = true
    return { sequence: mutationSequence, token: context.capture(), controller }
  }

  function finishMutation(request: { sequence: number, token: ReturnType<typeof context.capture>, controller: AbortController }): void {
    if (!isCurrent(request.token, request.sequence, mutationSequence))
      return
    mutationBusy.value = false
    if (mutationController === request.controller)
      mutationController = null
    savingSettings.value = false
    savingLayout.value = false
    savingProfile.value = false
    pendingDefaultId.value = null
    pendingArchiveId.value = null
  }

  function isAuthorityLoss(status: number, code: string): boolean {
    return status === 401 || status === 403 || (status === 409 && code === 'company_context_changed')
  }

  function clearPrivateState(): void {
    settings.value = copySettings(defaultErpCatalogPrintSettings)
    settingsBaseline.value = copySettings(defaultErpCatalogPrintSettings)
    layouts.value = []
    printerProfiles.value = []
    selectedLayoutId.value = null
    selectedLines.value = []
    layoutEditingId.value = null
    layoutDraft.value = defaultLayoutDraft()
    layoutBaseline.value = defaultLayoutDraft()
    profileEditingId.value = null
    profileDraft.value = defaultProfileDraft()
    profileBaseline.value = defaultProfileDraft()
    settingsError.value = ''
    layoutError.value = ''
    profileError.value = ''
    outputError.value = ''
    fieldErrors.value = {}
    fieldErrorTarget.value = null
    savingSettings.value = false
    savingLayout.value = false
    savingProfile.value = false
    pendingDefaultId.value = null
    pendingArchiveId.value = null
    mutationBusy.value = false
  }

  function blockAuthority(message: string, token: ReturnType<typeof context.capture>): void {
    if (!context.isCurrent(token))
      return
    blockedContextKey.value = context.key.value
    invalidateConfiguration()
    mutationSequence++
    mutationController?.abort()
    mutationController = null
    mutationBusy.value = false
    invalidateOutput()
    clearPrivateState()
    error.value = message
  }

  function accessError(): string {
    if (!user.value?.id || !currentCompany.value || currentCompany.value.status !== 'active')
      return 'Selecione uma empresa ERP ativa para consultar as configurações de etiquetas.'
    if (currentCompany.value.access_kind === 'delegated') {
      const office = currentAccount.value
      if (office?.status !== 'active' || office.id !== currentCompany.value.delegated_office_id)
        return 'Selecione o escritório autorizado para consultar as configurações de etiquetas.'
    }
    return 'Você não tem permissão para consultar as configurações de etiquetas nesta empresa.'
  }

  async function loadConfiguration(): Promise<void> {
    if (disposed || authorityBlocked.value || mutationBusy.value || (!canReadSettings.value && !canReadLayouts.value && !canReadProfiles.value))
      return
    configurationController?.abort()
    configurationSequence++
    const sequence = configurationSequence
    const token = context.capture()
    const controller = new AbortController()
    configurationController = controller
    loadingConfiguration.value = true
    error.value = ''
    const requests: Array<Promise<void>> = []
    if (canReadSettings.value) {
      requests.push(api<{ data: ErpCatalogPrintSettings }>(erpCatalogRoutes.printSettings, { signal: controller.signal })
        .then(({ data }) => {
          if (isCurrent(token, sequence, configurationSequence)) {
            settings.value = copySettings(data)
            settingsBaseline.value = copySettings(data)
          }
        }))
    }
    if (canReadLayouts.value) {
      requests.push(api<{ data: ErpCatalogPrintLayout[] }>(erpCatalogRoutes.labelLayouts, { signal: controller.signal })
        .then(({ data }) => {
          if (isCurrent(token, sequence, configurationSequence)) {
            layouts.value = data
            if (selectedLayoutId.value !== null && !data.some(layout => layout.id === selectedLayoutId.value && layout.status === 'active'))
              selectedLayoutId.value = null
          }
        }))
    }
    if (canReadProfiles.value) {
      requests.push(api<{ data: ErpCatalogPrinterProfile[] }>(erpCatalogRoutes.printerProfiles, { signal: controller.signal })
        .then(({ data }) => {
          if (isCurrent(token, sequence, configurationSequence))
            printerProfiles.value = data
        }))
    }
    try {
      const results = await Promise.allSettled(requests)
      if (!isCurrent(token, sequence, configurationSequence))
        return
      const failure = results.find(result => result.status === 'rejected' && isAuthorityLoss(apiFailure(result.reason).status, apiFailure(result.reason).code))
        ?? results.find(result => result.status === 'rejected')
      if (failure?.status === 'rejected') {
        const details = apiFailure(failure.reason)
        error.value = details.message
        if (isAuthorityLoss(details.status, details.code))
          blockAuthority(details.message, token)
      }
    } finally {
      if (isCurrent(token, sequence, configurationSequence)) {
        loadingConfiguration.value = false
        if (configurationController === controller)
          configurationController = null
      }
    }
  }

  async function saveSettings(): Promise<void> {
    if (!canSaveSettings.value || savingSettings.value)
      return
    const request = beginMutation()
    if (!request)
      return
    savingSettings.value = true
    settingsError.value = ''
    fieldErrors.value = {}
    fieldErrorTarget.value = 'settings'
    try {
      await refreshCsrf()
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const result = await api<{ data: ErpCatalogPrintSettings }>(erpCatalogRoutes.printSettings, {
        method: 'PATCH',
        body: copySettings(settings.value),
        signal: request.controller.signal
      })
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      settings.value = copySettings(result.data)
      settingsBaseline.value = copySettings(result.data)
      invalidateOutput()
    } catch (failure) {
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const details = apiFailure(failure)
      settingsError.value = details.message
      fieldErrors.value = details.errors ?? {}
      if (isAuthorityLoss(details.status, details.code))
        blockAuthority(details.message, request.token)
    } finally {
      finishMutation(request)
    }
  }

  function editLayout(layout: ErpCatalogPrintLayout | null): void {
    layoutEditingId.value = layout?.id ?? null
    layoutDraft.value = layout
      ? {
          name: layout.name,
          description: layout.description ?? '',
          label_width_mm: layout.label_width_mm,
          label_height_mm: layout.label_height_mm,
          paper_width_mm: layout.paper_width_mm,
          paper_height_mm: layout.paper_height_mm ?? '30.0000',
          top_margin_mm: layout.top_margin_mm,
          left_margin_mm: layout.left_margin_mm,
          row_spacing_mm: layout.row_spacing_mm,
          column_spacing_mm: layout.column_spacing_mm,
          columns_per_row: layout.columns_per_row,
          labels_per_sheet: layout.labels_per_sheet ?? 1,
          is_continuous: layout.is_continuous
        }
      : defaultLayoutDraft()
    layoutBaseline.value = copyLayoutDraft(layoutDraft.value)
    layoutError.value = ''
    fieldErrors.value = {}
    fieldErrorTarget.value = 'layout'
  }

  async function saveLayout(): Promise<void> {
    const permission = layoutEditingId.value === null ? 'erp.catalog.label-layouts.store' : 'erp.catalog.label-layouts.update'
    if (!hasPermission(permission, true) || savingLayout.value)
      return
    const request = beginMutation()
    if (!request)
      return
    savingLayout.value = true
    layoutError.value = ''
    fieldErrors.value = {}
    fieldErrorTarget.value = 'layout'
    try {
      await refreshCsrf()
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const draft = layoutDraft.value
      const body = {
        ...draft,
        description: draft.description || null,
        paper_height_mm: draft.is_continuous ? null : draft.paper_height_mm,
        labels_per_sheet: draft.is_continuous ? null : draft.labels_per_sheet
      }
      const path = layoutEditingId.value === null ? erpCatalogRoutes.labelLayouts : `${erpCatalogRoutes.labelLayouts}/${layoutEditingId.value}`
      const response = await api<{ data: ErpCatalogPrintLayout }>(path, {
        method: layoutEditingId.value === null ? 'POST' : 'PATCH',
        body,
        signal: request.controller.signal
      })
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const updated = layouts.value.filter(layout => layout.id !== response.data.id)
      updated.push(response.data)
      layouts.value = updated
      editLayout(response.data)
      invalidateOutput()
    } catch (failure) {
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const details = apiFailure(failure)
      layoutError.value = details.message
      fieldErrors.value = details.errors ?? {}
      if (isAuthorityLoss(details.status, details.code))
        blockAuthority(details.message, request.token)
    } finally {
      finishMutation(request)
    }
  }

  async function makeLayoutDefault(id: number): Promise<void> {
    if (!canSetLayoutDefault.value || pendingDefaultId.value !== null)
      return
    const request = beginMutation()
    if (!request)
      return
    pendingDefaultId.value = id
    layoutError.value = ''
    try {
      await refreshCsrf()
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const { data } = await api<{ data: ErpCatalogPrintLayout }>(`${erpCatalogRoutes.labelLayouts}/${id}/default`, {
        method: 'PATCH',
        body: {},
        signal: request.controller.signal
      })
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      layouts.value = layouts.value.map(layout => ({ ...layout, is_default: layout.id === data.id }))
      invalidateOutput()
    } catch (failure) {
      if (isCurrent(request.token, request.sequence, mutationSequence)) {
        const details = apiFailure(failure)
        layoutError.value = details.message
        if (isAuthorityLoss(details.status, details.code))
          blockAuthority(details.message, request.token)
      }
    } finally {
      finishMutation(request)
    }
  }

  async function archiveLayout(id: number): Promise<void> {
    if (!canArchiveLayouts.value || pendingArchiveId.value !== null)
      return
    const request = beginMutation()
    if (!request)
      return
    pendingArchiveId.value = id
    layoutError.value = ''
    try {
      await refreshCsrf()
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const { data } = await api<{ data: ErpCatalogPrintLayout }>(`${erpCatalogRoutes.labelLayouts}/${id}`, {
        method: 'DELETE',
        signal: request.controller.signal
      })
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      layouts.value = layouts.value.map(layout => layout.id === data.id ? data : layout)
      if (selectedLayoutId.value === data.id)
        selectedLayoutId.value = null
      if (layoutEditingId.value === data.id)
        editLayout(null)
      invalidateOutput()
    } catch (failure) {
      if (isCurrent(request.token, request.sequence, mutationSequence)) {
        const details = apiFailure(failure)
        layoutError.value = details.message
        if (isAuthorityLoss(details.status, details.code))
          blockAuthority(details.message, request.token)
      }
    } finally {
      finishMutation(request)
    }
  }

  async function saveProfile(): Promise<void> {
    const permission = profileEditingId.value === null ? 'erp.catalog.printer-profiles.store' : 'erp.catalog.printer-profiles.update'
    if (!hasPermission(permission, true) || savingProfile.value)
      return
    const request = beginMutation()
    if (!request)
      return
    savingProfile.value = true
    profileError.value = ''
    fieldErrors.value = {}
    fieldErrorTarget.value = 'profile'
    try {
      await refreshCsrf()
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const path = profileEditingId.value === null ? erpCatalogRoutes.printerProfiles : `${erpCatalogRoutes.printerProfiles}/${profileEditingId.value}`
      const { data } = await api<{ data: ErpCatalogPrinterProfile }>(path, {
        method: profileEditingId.value === null ? 'POST' : 'PATCH',
        body: profileDraft.value,
        signal: request.controller.signal
      })
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      printerProfiles.value = [...printerProfiles.value.filter(profile => profile.id !== data.id), data]
      profileEditingId.value = data.id
      profileDraft.value = { name: data.name, capability_profile: data.capability_profile }
      profileBaseline.value = { ...profileDraft.value }
    } catch (failure) {
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const details = apiFailure(failure)
      profileError.value = details.message
      fieldErrors.value = details.errors ?? {}
      if (isAuthorityLoss(details.status, details.code))
        blockAuthority(details.message, request.token)
    } finally {
      finishMutation(request)
    }
  }

  async function archiveProfile(id: number): Promise<void> {
    if (!canArchiveProfiles.value || pendingArchiveId.value !== null)
      return
    const request = beginMutation()
    if (!request)
      return
    pendingArchiveId.value = id
    profileError.value = ''
    try {
      await refreshCsrf()
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      const { data } = await api<{ data: ErpCatalogPrinterProfile }>(`${erpCatalogRoutes.printerProfiles}/${id}`, {
        method: 'DELETE',
        signal: request.controller.signal
      })
      if (!isCurrent(request.token, request.sequence, mutationSequence))
        return
      printerProfiles.value = printerProfiles.value.map(profile => profile.id === data.id ? data : profile)
      if (profileEditingId.value === data.id) {
        profileEditingId.value = null
        profileDraft.value = defaultProfileDraft()
      }
    } catch (failure) {
      if (isCurrent(request.token, request.sequence, mutationSequence)) {
        const details = apiFailure(failure)
        profileError.value = details.message
        if (isAuthorityLoss(details.status, details.code))
          blockAuthority(details.message, request.token)
      }
    } finally {
      finishMutation(request)
    }
  }

  function toggleSelection(line: { product_id: number, variant_id?: number, quantity: number }, selected: boolean): void {
    if (disposed || authorityBlocked.value || !canGenerate.value)
      return
    selectedLines.value = setErpLabelSelection(selectedLines.value, line, selected)
  }

  function setQuantity(line: { product_id: number, variant_id?: number, quantity: number }, quantity: number): void {
    selectedLines.value = updateErpLabelQuantity(selectedLines.value, erpLabelSelectionKey(line), quantity)
  }

  function selectionQuantity(line: { product_id: number, variant_id?: number }): number {
    return selectedLines.value.find(candidate => erpLabelSelectionKey(candidate) === erpLabelSelectionKey(line))?.quantity ?? 1
  }

  function hasSelection(line: { product_id: number, variant_id?: number }): boolean {
    return selectedLines.value.some(candidate => erpLabelSelectionKey(candidate) === erpLabelSelectionKey(line))
  }

  function clearSelection(): void {
    selectedLines.value = []
  }

  async function generateOutput(): Promise<void> {
    if (disposed || authorityBlocked.value || mutationBusy.value || loadingConfiguration.value || error.value || !canGenerate.value || outputPending.value || settingsDirty.value || layoutDirty.value || selectedLines.value.length < 1 || selectionOverLimit.value)
      return
    invalidateOutput(false)
    outputError.value = ''
    fieldErrors.value = {}
    fieldErrorTarget.value = 'output'
    outputPending.value = true
    outputSequence++
    const sequence = outputSequence
    const token = context.capture()
    const controller = new AbortController()
    outputController = controller
    try {
      await refreshCsrf()
      if (!isCurrent(token, sequence, outputSequence))
        return
      const body = {
        items: selectedLines.value.map(line => ({
          product_id: line.product_id,
          ...(line.variant_id === undefined ? {} : { variant_id: line.variant_id }),
          quantity: line.quantity
        })),
        ...(selectedLayoutId.value === null ? {} : { layout_id: selectedLayoutId.value })
      }
      const response = await api<ErpCatalogLabelResponse>(erpCatalogRoutes.productLabels, {
        method: 'POST',
        body,
        signal: controller.signal
      })
      if (!isCurrent(token, sequence, outputSequence))
        return
      const output = await renderErpCatalogLabelOutput(response, () => isCurrent(token, sequence, outputSequence))
      if (!isCurrent(token, sequence, outputSequence))
        return
      const nextUrl = URL.createObjectURL(output.blob)
      if (!isCurrent(token, sequence, outputSequence)) {
        URL.revokeObjectURL(nextUrl)
        return
      }
      revokeOutput()
      outputUrl.value = nextUrl
      outputFilename.value = output.filename
      outputCopies.value = output.copies
      outputPages.value = output.pages
      outputMeta.value = response.meta
    } catch (failure) {
      if (!isCurrent(token, sequence, outputSequence))
        return
      if (failure instanceof DOMException && failure.name === 'AbortError')
        return
      const details = apiFailure(failure)
      outputError.value = failure instanceof Error && !details.status ? failure.message : details.message
      fieldErrors.value = details.errors ?? {}
      if (isAuthorityLoss(details.status, details.code))
        blockAuthority(details.message, token)
    } finally {
      if (isCurrent(token, sequence, outputSequence)) {
        outputPending.value = false
        if (outputController === controller)
          outputController = null
      }
    }
  }

  function downloadOutput(): void {
    const token = context.capture()
    if (disposed || authorityBlocked.value || !canGenerate.value || !outputUrl.value || !context.isCurrent(token))
      return
    const link = document.createElement('a')
    link.href = outputUrl.value
    link.download = outputFilename.value
    document.body.append(link)
    link.click()
    link.remove()
  }

  function printOutput(frame: HTMLIFrameElement | null): void {
    const token = context.capture()
    if (disposed || authorityBlocked.value || !canGenerate.value || !outputUrl.value || !context.isCurrent(token))
      return
    if (frame?.getAttribute('src') !== outputUrl.value)
      return
    frame.contentWindow?.focus()
    frame.contentWindow?.print()
  }

  const activeLayoutOptions = computed(() => activeLayouts.value.filter((layout): layout is ErpCatalogPrintLayout & { id: number } => layout.id !== null).map(layout => ({ label: `${layout.name}${layout.is_default ? ' · padrão' : ''}`, value: layout.id })))
  const activeProfileOptions = computed(() => printerProfiles.value.filter(profile => profile.status === 'active').map(profile => ({ label: `${profile.name} · ${profile.capability_profile}`, value: profile.id })))

  function editProfile(profile: ErpCatalogPrinterProfile | null): void {
    profileEditingId.value = profile?.id ?? null
    profileDraft.value = profile ? { name: profile.name, capability_profile: profile.capability_profile } : defaultProfileDraft()
    profileBaseline.value = { ...profileDraft.value }
    profileError.value = ''
    fieldErrors.value = {}
    fieldErrorTarget.value = 'profile'
  }

  async function retryConfiguration(): Promise<void> {
    if (disposed || refreshingAuthority.value || mutationBusy.value)
      return
    if (!authorityBlocked.value)
      return loadConfiguration()
    const token = context.capture()
    refreshingAuthority.value = true
    try {
      const identity = await auth.refresh()
      if (disposed || !context.isCurrent(token))
        return
      if (!identity) {
        error.value = 'Confirme sua sessão para consultar as etiquetas.'
        return
      }
      blockedContextKey.value = null
      error.value = ''
      if (!canReadSettings.value && !canReadLayouts.value && !canReadProfiles.value) {
        if (!canGenerate.value)
          error.value = accessError()
        return
      }
      await loadConfiguration()
    } catch (failure) {
      if (context.isCurrent(token))
        error.value = apiFailure(failure).message
    } finally {
      refreshingAuthority.value = false
    }
  }

  watch(context.key, () => {
    invalidateConfiguration()
    mutationSequence++
    mutationController?.abort()
    mutationController = null
    invalidateOutput()
    clearPrivateState()
    blockedContextKey.value = null
    error.value = ''
    if (canReadSettings.value || canReadLayouts.value || canReadProfiles.value)
      void loadConfiguration()
    else if (!canGenerate.value)
      error.value = accessError()
  }, { flush: 'sync', immediate: true })

  watch([selectedLines, selectedLayoutId, settings, layoutDraft], () => invalidateOutput(), { deep: true, flush: 'sync' })

  onScopeDispose(() => {
    disposed = true
    configurationSequence++
    configurationController?.abort()
    mutationSequence++
    mutationController?.abort()
    invalidateOutput()
  })

  return {
    contextKey: context.key,
    rejectAuthority: (message: string) => blockAuthority(message, context.capture()),
    settings, layouts, printerProfiles, selectedLayoutId, selectedLines, layoutEditingId, layoutDraft, profileEditingId, profileDraft,
    mutationBusy, authorityBlocked, refreshingAuthority, profileDirty, canCreateLayout, canUpdateLayout, canCreateProfile, canUpdateProfile,
    loadingConfiguration, savingSettings, savingLayout, savingProfile, pendingDefaultId, pendingArchiveId,
    error, fieldErrors, fieldErrorTarget, settingsError, layoutError, profileError, outputError, outputPending,
    outputUrl, outputFilename, outputCopies, outputPages, outputMeta,
    settingsDirty, layoutDirty, selectedCopies, selectionOverLimit, activeLayouts, activeLayoutOptions, activeProfileOptions, defaultLayout,
    canReadProducts, canGenerate, canReadSettings, canSaveSettings, canReadLayouts, canSaveLayouts, canSetLayoutDefault, canArchiveLayouts,
    canReadProfiles, canSaveProfiles, canArchiveProfiles,
    retryConfiguration, editProfile, loadConfiguration, saveSettings, editLayout, saveLayout, makeLayoutDefault, archiveLayout, saveProfile, archiveProfile,
    toggleSelection, setQuantity, selectionQuantity, hasSelection, clearSelection, generateOutput, downloadOutput, printOutput, invalidateOutput
  }
}
