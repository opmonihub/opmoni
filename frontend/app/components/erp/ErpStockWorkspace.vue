<script setup lang="ts">
import { erpOperationalTableUi } from '~/components/erp/erpPresentation'
import type { TableColumn } from '@nuxt/ui'
import type { ErpStockAdjustment, ErpStockBalance, ErpStockLot, ErpStockMovement, ErpStockTransfer } from '#shared/types/erpBusiness'
import { stockCostDescription } from '#shared/utils/erpStock'
import { useErpStockWorkspace } from '~/composables/useErpStockWorkspace'
import { erpWorkspaceModuleDestinations, erpWorkspaceModuleSelection } from '~/utils/erpWorkspaceNav'

type StockArea = 'balances' | 'movements' | 'lots' | 'adjustments' | 'transfers'
const workspace = useErpStockWorkspace()
const route = useRoute()
const mounted = ref(false)
const permittedAreas = computed(() => erpWorkspaceModuleDestinations('estoque', workspace.allowed))
const selection = computed(() => erpWorkspaceModuleSelection('estoque', mounted.value ? route.hash : '', workspace.allowed))
const area = computed(() => (selection.value.destination?.id ?? 'balances') as StockArea)
const adjustmentOpen = ref(false)
const transferOpen = ref(false)
const adjustmentFormKey = ref(0)
const transferFormKey = ref(0)
const adjustmentForm = ref<{ state: Record<string, string> } | null>(null)
const transferForm = ref<{ state: Record<string, string> } | null>(null)
type RecoveryOwner = { kind: 'adjustment' | 'transfer', authority: number, attempt: number, fingerprint: string }
const recoveryOwners = new Map<string, RecoveryOwner>()
const recoveryOverlay = ref<{ key: string, kind: 'adjustment' | 'transfer', attempt: number, authority: number, fingerprint: string | null } | null>(null)
let adjustmentAttempt = 0
let transferAttempt = 0
const fieldErrors = computed(() => workspace.fieldErrors.value)
const activeRows = computed(() => {
  if (area.value === 'balances') return workspace.balances.value
  if (area.value === 'movements') return workspace.movements.value
  if (area.value === 'lots') return workspace.lots.value
  if (area.value === 'adjustments') return workspace.adjustments.value
  return workspace.transfers.value
})
const activeMeta = computed(() => workspace.metas[area.value])
const detailTitle = computed(() => workspace.detail.value?.kind === 'movement'
  ? `Movimentação #${workspace.detail.value.row.id}`
  : workspace.detail.value?.kind === 'adjustment'
    ? `Ajuste #${workspace.detail.value.row.id}`
    : workspace.detail.value?.kind === 'transfer'
      ? `Transferência #${workspace.detail.value.row.id}`
      : 'Detalhe do estoque')
const pendingReversal = computed(() => {
  const operation = workspace.selectedOperation.value
  if (operation?.operation === 'erp.stock.movements.reverse') {
    const id = operation.path.match(/^\/erp\/stock\/movements\/(\d+)\/reverse$/)?.[1]
    return id ? { kind: 'movement' as const, id: Number(id), operation } : null
  }
  if (operation?.operation === 'erp.stock.transfers.reverse') {
    const id = operation.path.match(/^\/erp\/stock\/transfers\/(\d+)\/reverse$/)?.[1]
    return id ? { kind: 'transfer' as const, id: Number(id), operation } : null
  }
  return null
})
const establishmentNames = computed(() => new Map(workspace.establishments.value.map(row => [row.id, row.name])))
const variantNames = computed(() => new Map(workspace.variants.value.map(row => [row.id, `${row.product.name} · ${row.name}${row.sku ? ` · ${row.sku}` : ''}`])))
const columns: TableColumn<ErpStockBalance | ErpStockMovement | ErpStockLot | ErpStockAdjustment | ErpStockTransfer>[] = [
  { id: 'record', header: 'Registro' },
  { id: 'context', header: 'Estabelecimento e produto' },
  { id: 'value', header: 'Quantidade / custo' },
  { id: 'actions', header: 'Ações' }
]
const areaLinks = computed(() => [permittedAreas.value.map(item => ({
  label: item.label,
  to: { path: route.path, query: route.query, hash: `#${item.id}` },
  active: area.value === item.id
}))])

watch(() => [mounted.value, area.value, selection.value.permitted, workspace.authorityGeneration.value] as const, (_next, previous) => {
  if (!mounted.value) return
  if (previous?.[0] && previous[3] === workspace.authorityGeneration.value) workspace.beginManualIntent()
  workspace.filters.page = 1
  if (selection.value.permitted) void workspace.load(area.value, 1)
})
watch(() => workspace.resolvedOperation.value, (resolved) => {
  if (!resolved) return
  const owner = recoveryOwners.get(resolved.key)
  recoveryOwners.delete(resolved.key)
  if (!owner) {
    const overlay = recoveryOverlay.value
    recoveryOverlay.value = null
    if (!overlay || overlay.key !== resolved.key || overlay.authority !== workspace.authorityGeneration.value || overlay.fingerprint === null) return
    if (overlay.kind === 'adjustment' && resolved.operation === 'erp.stock.adjustments.store' && JSON.stringify(adjustmentForm.value?.state) === overlay.fingerprint) {
      if (overlay.attempt === adjustmentAttempt && adjustmentOpen.value) adjustmentOpen.value = false
      adjustmentFormKey.value++
    }
    if (overlay.kind === 'transfer' && resolved.operation === 'erp.stock.transfers.store' && JSON.stringify(transferForm.value?.state) === overlay.fingerprint) {
      if (overlay.attempt === transferAttempt && transferOpen.value) transferOpen.value = false
      transferFormKey.value++
    }
    return
  }
  if (owner.authority !== workspace.authorityGeneration.value) return
  const overlay = recoveryOverlay.value
  if (overlay?.key === resolved.key) recoveryOverlay.value = null
  if (owner.kind === 'adjustment' && resolved.operation === 'erp.stock.adjustments.store'
    && JSON.stringify(adjustmentForm.value?.state) === owner.fingerprint) {
    const resumed = overlay?.key === resolved.key && overlay.kind === 'adjustment' && overlay.attempt === adjustmentAttempt
      && overlay.authority === workspace.authorityGeneration.value && overlay.fingerprint === owner.fingerprint
    if ((owner.attempt === adjustmentAttempt || resumed) && adjustmentOpen.value) adjustmentOpen.value = false
    adjustmentFormKey.value++
  }
  if (owner.kind === 'transfer' && resolved.operation === 'erp.stock.transfers.store'
    && JSON.stringify(transferForm.value?.state) === owner.fingerprint) {
    const resumed = overlay?.key === resolved.key && overlay.kind === 'transfer' && overlay.attempt === transferAttempt
      && overlay.authority === workspace.authorityGeneration.value && overlay.fingerprint === owner.fingerprint
    if ((owner.attempt === transferAttempt || resumed) && transferOpen.value) transferOpen.value = false
    transferFormKey.value++
  }
})
function trackRecoveryOverlay(key: string, kind: 'adjustment' | 'transfer') {
  const overlay = { key, kind, attempt: kind === 'adjustment' ? adjustmentAttempt : transferAttempt, authority: workspace.authorityGeneration.value, fingerprint: null as string | null }
  recoveryOverlay.value = overlay
  void nextTick(() => {
    const current = recoveryOverlay.value
    if (current?.key === key && current.kind === kind && current.attempt === overlay.attempt && current.fingerprint === null) {
      current.fingerprint = kind === 'adjustment' ? JSON.stringify(adjustmentForm.value?.state) : JSON.stringify(transferForm.value?.state)
    }
  })
}
watch(() => [workspace.selectedOperation.value?.key, workspace.selectedOperation.value?.operation, workspace.allowed('erp.stock.adjustments.store'), workspace.allowed('erp.stock.transfers.store')] as const, ([key, operation, canAdjust, canTransfer]) => {
  if (operation === 'erp.stock.adjustments.store' && canAdjust) {
    if (key && !recoveryOwners.has(key)) trackRecoveryOverlay(key, 'adjustment')
    adjustmentOpen.value = true
  } else if (operation === 'erp.stock.adjustments.store') adjustmentOpen.value = false
  if (operation === 'erp.stock.transfers.store' && canTransfer) {
    if (key && !recoveryOwners.has(key)) trackRecoveryOverlay(key, 'transfer')
    transferOpen.value = true
  } else if (operation === 'erp.stock.transfers.store') transferOpen.value = false
}, { immediate: true })
watch(() => workspace.authorityGeneration.value, () => {
  recoveryOwners.clear()
  recoveryOverlay.value = null
  adjustmentOpen.value = false
  transferOpen.value = false
  adjustmentFormKey.value++
  transferFormKey.value++
})
onMounted(() => {
  mounted.value = true
})

function applyFilters() {
  workspace.filters.page = 1
  if (selection.value.permitted) void workspace.load(area.value, 1)
}
function clearFilters() {
  workspace.filters.erp_establishment_id = ''
  workspace.filters.erp_product_variant_id = ''
  workspace.filters.source_type = ''
  workspace.filters.source_id = ''
  workspace.filters.direction = ''
  workspace.filters.date_from = ''
  workspace.filters.date_to = ''
  workspace.filters.status = ''
  applyFilters()
}
function inspectLotMovement(lot: ErpStockLot) {
  if (lot.erp_stock_movement_id !== null) void workspace.inspectMovement(lot.erp_stock_movement_id)
}
function openAdjustment() {
  if (!workspace.allowed('erp.stock.adjustments.store')) return
  const pending = workspace.selectedOperation.value
  if (pending && pending.operation !== 'erp.stock.adjustments.store') return
  workspace.beginManualIntent()
  adjustmentAttempt++
  if (pending?.key) trackRecoveryOverlay(pending.key, 'adjustment')
  else workspace.clearOperationNotice()
  adjustmentOpen.value = true
}
function openTransfer() {
  if (!workspace.allowed('erp.stock.transfers.store')) return
  const pending = workspace.selectedOperation.value
  if (pending && pending.operation !== 'erp.stock.transfers.store') return
  workspace.beginManualIntent()
  transferAttempt++
  if (pending?.key) trackRecoveryOverlay(pending.key, 'transfer')
  else workspace.clearOperationNotice()
  transferOpen.value = true
}
function updateAdjustmentOpen(open: boolean) {
  adjustmentOpen.value = open
  if (!open) {
    adjustmentAttempt++
    if (recoveryOverlay.value?.kind === 'adjustment') recoveryOverlay.value = null
  }
}
function updateTransferOpen(open: boolean) {
  transferOpen.value = open
  if (!open) {
    transferAttempt++
    if (recoveryOverlay.value?.kind === 'transfer') recoveryOverlay.value = null
  }
}
async function submitAdjustment(payload: { erp_establishment_id: string, erp_product_variant_id: string, quantity_delta: string, reason: string }) {
  const previousIntent = workspace.operationIntentSequence.value
  const request = workspace.postAdjustment(payload)
  const pending = workspace.selectedOperation.value
  const ownerKey = pending?.key
  if (workspace.operationIntentSequence.value > previousIntent && pending?.key && pending.operation === 'erp.stock.adjustments.store'
    && JSON.stringify(pending.payload) === JSON.stringify({ erp_establishment_id: Number(payload.erp_establishment_id), erp_product_variant_id: Number(payload.erp_product_variant_id), quantity_delta: payload.quantity_delta, reason: payload.reason.trim() })) {
    recoveryOwners.set(pending.key, { kind: 'adjustment', authority: workspace.authorityGeneration.value, attempt: adjustmentAttempt, fingerprint: JSON.stringify(adjustmentForm.value?.state) })
  }
  await request
  if (ownerKey && workspace.resolvedOperation.value?.key !== ownerKey && workspace.selectedOperation.value?.key !== ownerKey) recoveryOwners.delete(ownerKey)
}
async function submitTransfer(payload: { from_establishment_id: string, to_establishment_id: string, erp_product_variant_id: string, quantity: string }) {
  const previousIntent = workspace.operationIntentSequence.value
  const request = workspace.postTransfer(payload)
  const pending = workspace.selectedOperation.value
  const ownerKey = pending?.key
  if (workspace.operationIntentSequence.value > previousIntent && pending?.key && pending.operation === 'erp.stock.transfers.store'
    && JSON.stringify(pending.payload) === JSON.stringify({ from_establishment_id: Number(payload.from_establishment_id), to_establishment_id: Number(payload.to_establishment_id), erp_product_variant_id: Number(payload.erp_product_variant_id), quantity: payload.quantity })) {
    recoveryOwners.set(pending.key, { kind: 'transfer', authority: workspace.authorityGeneration.value, attempt: transferAttempt, fingerprint: JSON.stringify(transferForm.value?.state) })
  }
  await request
  if (ownerKey && workspace.resolvedOperation.value?.key !== ownerKey && workspace.selectedOperation.value?.key !== ownerKey) recoveryOwners.delete(ownerKey)
}
function movementOperationMatches() {
  const operation = workspace.selectedOperation.value
  return operation?.operation === 'erp.stock.movements.reverse' && operation.path === `/erp/stock/movements/${workspace.detail.value?.row.id}/reverse`
}
function openTransferFromMovement(id: number) {
  if (!workspace.allowed('erp.stock.transfers.show')) return
  void workspace.inspectTransfer(id)
}
function transferOperationMatches() {
  const operation = workspace.selectedOperation.value
  return operation?.operation === 'erp.stock.transfers.reverse' && operation.path === `/erp/stock/transfers/${workspace.detail.value?.row.id}/reverse`
}
function balanceCost(balance: ErpStockBalance) {
  return balance.average_cost === null ? 'Custo médio não disponível' : `Custo médio de exibição: ${balance.average_cost}`
}
function movementSummary(movement: ErpStockMovement) {
  return stockCostDescription(movement)
}
function sourceTypeLabel(value: string) {
  const labels: Record<string, string> = {
    erp_purchase: 'Compra', erp_sale: 'Venda', erp_purchase_return: 'Devolução de compra', erp_sale_return: 'Devolução de venda',
    erp_stock_adjustment: 'Ajuste de estoque', erp_stock_transfer: 'Transferência', erp_stock_transfer_return: 'Retorno de transferência', erp_stock_reversal: 'Correção de movimento'
  }
  return labels[value] ?? `Origem não reconhecida (${value})`
}
function costStatusLabel(value: string | null | undefined) {
  const labels: Record<string, string> = { known: 'Conhecido', unvalued: 'Sem valoração', mixed_currency: 'Moedas diferentes', legacy_unknown: 'Legado desconhecido' }
  return value ? labels[value] ?? `Situação de custo não reconhecida (${value})` : 'Situação de custo não informada'
}
function transferStatusLabel(value: string) {
  const labels: Record<string, string> = { posted: 'Lançada' }
  return labels[value] ?? `Situação da transferência não reconhecida (${value})`
}
function mutationColor() {
  return workspace.mutationError.value.startsWith('Operação confirmada') ? 'success' : 'error'
}
</script>

<template>
  <ErpCatalogPageShell title="Estoque" description="Consulte saldos, movimentos e camadas com origem rastreável. Custos desconhecidos permanecem identificados como desconhecidos." data-stock-workspace>
    <template #actions>
      <UButton
        v-if="workspace.allowed('erp.stock.adjustments.store')"
        icon="i-lucide-plus"
        :aria-label="workspace.selectedOperation.value?.operation === 'erp.stock.adjustments.store' ? 'Retomar ajuste pendente' : 'Novo ajuste'"
        :disabled="!!workspace.selectedOperation.value && workspace.selectedOperation.value.operation !== 'erp.stock.adjustments.store'"
        @click="openAdjustment"
      >
        <span class="hidden sm:inline">{{ workspace.selectedOperation.value?.operation === 'erp.stock.adjustments.store' ? 'Retomar ajuste' : 'Novo ajuste' }}</span>
      </UButton>
      <UButton
        v-if="workspace.allowed('erp.stock.transfers.store')"
        icon="i-lucide-arrow-left-right"
        color="neutral"
        variant="outline"
        :aria-label="workspace.selectedOperation.value?.operation === 'erp.stock.transfers.store' ? 'Retomar transferência pendente' : 'Nova transferência'"
        :disabled="!!workspace.selectedOperation.value && workspace.selectedOperation.value.operation !== 'erp.stock.transfers.store'"
        @click="openTransfer"
      >
        <span class="hidden sm:inline">{{ workspace.selectedOperation.value?.operation === 'erp.stock.transfers.store' ? 'Retomar transferência' : 'Nova transferência' }}</span>
      </UButton>
    </template>
    <template #toolbar>
      <div class="flex w-full min-w-0 flex-col items-stretch gap-2">
        <UNavigationMenu
          :items="areaLinks"
          highlight
          aria-label="Áreas de estoque"
          class="w-full min-w-0 overflow-x-auto"
        />
        <div class="flex w-full min-w-0 flex-wrap items-center gap-2">
          <USelect
            :model-value="workspace.filters.erp_establishment_id || 'all'"
            aria-label="Filtrar por estabelecimento"
            :items="[{ label: 'Todos os estabelecimentos', value: 'all' }, ...workspace.establishments.value.map(row => ({ label: row.name, value: String(row.id) }))]"
            @update:model-value="workspace.filters.erp_establishment_id = $event === 'all' ? '' : String($event)"
          />
          <USelect
            :model-value="workspace.filters.erp_product_variant_id || 'all'"
            aria-label="Filtrar por produto ou variante"
            :items="[{ label: 'Todos os produtos', value: 'all' }, ...workspace.variants.value.map(row => ({ label: `${row.product.name} · ${row.name}`, value: String(row.id) }))]"
            @update:model-value="workspace.filters.erp_product_variant_id = $event === 'all' ? '' : String($event)"
          />
          <template v-if="['movements', 'lots', 'adjustments', 'transfers'].includes(area)">
            <USelect
              :model-value="workspace.filters.source_type || 'all'"
              aria-label="Filtrar por tipo de origem"
              :items="[
                { label: 'Todas as origens', value: 'all' }, { label: 'Compra', value: 'erp_purchase' }, { label: 'Venda', value: 'erp_sale' },
                { label: 'Devolução de compra', value: 'erp_purchase_return' }, { label: 'Devolução de venda', value: 'erp_sale_return' },
                { label: 'Ajuste', value: 'erp_stock_adjustment' }, { label: 'Transferência', value: 'erp_stock_transfer' },
                { label: 'Correção de transferência', value: 'erp_stock_transfer_return' }, { label: 'Correção', value: 'erp_stock_reversal' }
              ]"
              @update:model-value="workspace.filters.source_type = $event === 'all' ? '' : String($event)"
            />
            <UInput
              v-model="workspace.filters.source_id"
              aria-label="ID da origem"
              placeholder="ID da origem"
              inputmode="numeric"
              class="w-32"
            />
          </template>
          <USelect
            v-if="area === 'movements'"
            :model-value="workspace.filters.direction || 'all'"
            aria-label="Filtrar direção"
            :items="[{ label: 'Entradas e saídas', value: 'all' }, { label: 'Entrada', value: 'in' }, { label: 'Saída', value: 'out' }]"
            @update:model-value="workspace.filters.direction = $event === 'all' ? '' : String($event)"
          />
          <USelect
            v-if="['adjustments', 'transfers'].includes(area)"
            :model-value="workspace.filters.status || 'all'"
            aria-label="Filtrar situação"
            :items="[{ label: 'Todas as situações', value: 'all' }, { label: 'Lançado', value: 'posted' }]"
            @update:model-value="workspace.filters.status = $event === 'all' ? '' : String($event)"
          />
          <UInput
            v-if="area !== 'balances'"
            v-model="workspace.filters.date_from"
            aria-label="Data inicial"
            type="date"
          />
          <UInput
            v-if="area !== 'balances'"
            v-model="workspace.filters.date_to"
            aria-label="Data final"
            type="date"
          />
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-filter"
            label="Aplicar filtros"
            @click="applyFilters"
          />
          <UButton
            color="neutral"
            variant="ghost"
            label="Limpar"
            @click="clearFilters"
          />
          <UButton
            to="/erp/configuracoes/empresa"
            color="neutral"
            variant="ghost"
            icon="i-lucide-settings-2"
            label="Método contábil"
          />
          <UButton
            color="neutral"
            variant="outline"
            icon="i-lucide-refresh-cw"
            label="Atualizar"
            :loading="workspace.loading[area]"
            @click="workspace.load(area, activeMeta?.current_page ?? 1)"
          />
        </div>
      </div>
    </template>
    <UAlert
      v-if="workspace.referencesError.value"
      color="warning"
      variant="subtle"
      :title="workspace.referencesError.value"
      description="As referências carregadas anteriormente permanecem disponíveis; atualize antes de iniciar uma operação."
    />
    <UAlert
      v-if="workspace.listErrors[area]"
      color="error"
      variant="subtle"
      :title="workspace.listErrors[area]"
      :description="workspace.stale[area] ? 'Exibindo o último resultado autorizado, marcado como desatualizado.' : 'Não há dados carregados para esta lista.'"
    />
    <UAlert
      v-if="workspace.stale[area]"
      color="warning"
      variant="subtle"
      title="Lista desatualizada"
      description="A atualização falhou. Os dados abaixo são o último resultado autorizado."
    />
    <p class="text-xs text-muted">
      O método contábil configurado afeta novas alocações. Os movimentos existentes preservam o método registrado na operação.
    </p>
    <UAlert
      v-if="workspace.mutationError.value && !workspace.selectedOperation.value"
      :color="mutationColor()"
      variant="subtle"
      :title="workspace.mutationError.value"
    >
      <template #actions>
        <UButton
          color="neutral"
          variant="ghost"
          label="Fechar aviso"
          @click="workspace.clearOperationNotice"
        />
      </template>
    </UAlert>

    <UAlert
      v-if="pendingReversal"
      color="warning"
      variant="subtle"
      title="Correção de estoque sem resultado confirmado"
      :description="pendingReversal.kind === 'movement'
        ? `Movimento #${pendingReversal.id} · identidade e solicitação original preservadas. Consulte ou repita exatamente esta operação.`
        : `Transferência #${pendingReversal.id} · identidade e solicitação original preservadas. Consulte ou repita exatamente esta operação.`"
    >
      <template #actions>
        <UButton
          v-if="pendingReversal.kind === 'movement' ? workspace.allowed('erp.stock.movements.show') : workspace.allowed('erp.stock.transfers.show')"
          color="neutral"
          variant="outline"
          label="Retomar correção pendente"
          :disabled="workspace.detailLoading.value"
          @click="workspace.openRecoveryDetail"
        />
        <UButton
          color="neutral"
          variant="outline"
          label="Consultar operação"
          :disabled="workspace.mutating.value"
          @click="workspace.reconcile"
        />
        <UButton
          v-if="workspace.canRetryExact.value"
          color="warning"
          variant="outline"
          label="Repetir exatamente"
          :disabled="workspace.mutating.value"
          @click="workspace.retryExact"
        />
      </template>
    </UAlert>

    <UAlert
      v-if="!selection.permitted"
      color="error"
      title="Seu acesso não permite consultar o estoque desta empresa."
    />
    <div v-else class="overflow-hidden rounded-lg border border-default" aria-live="polite">
      <UTable
        :ui="erpOperationalTableUi"
        :data="activeRows"
        :columns="columns"
        :loading="workspace.loading[area]"
        :empty="activeMeta ? 'Nenhum registro nesta página.' : 'Resultado indisponível.'"
      >
        <template #record-cell="{ row }">
          <template v-if="area === 'balances'">
            Saldo
          </template>
          <template v-else-if="area === 'movements'">
            Movimento #{{ (row.original as ErpStockMovement).id }} · {{ (row.original as ErpStockMovement).direction === 'in' ? 'Entrada' : 'Saída' }}
          </template>
          <template v-else-if="area === 'lots'">
            Camada #{{ (row.original as ErpStockLot).id }}
          </template>
          <template v-else-if="area === 'adjustments'">
            Ajuste #{{ (row.original as ErpStockAdjustment).id }}
          </template>
          <template v-else>
            Transferência #{{ (row.original as ErpStockTransfer).id }}
          </template>
        </template>
        <template #context-cell="{ row }">
          <template v-if="area === 'balances'">
            <p class="font-medium">
              {{ variantNames.get((row.original as ErpStockBalance).erp_product_variant_id) ?? `Variante #${(row.original as ErpStockBalance).erp_product_variant_id}` }}
            </p>
            <p class="text-sm text-muted">
              {{ establishmentNames.get((row.original as ErpStockBalance).erp_establishment_id) ?? `Estabelecimento #${(row.original as ErpStockBalance).erp_establishment_id}` }}
            </p>
          </template>
          <template v-else-if="area === 'movements'">
            <p>{{ establishmentNames.get((row.original as ErpStockMovement).erp_establishment_id) ?? `Estabelecimento #${(row.original as ErpStockMovement).erp_establishment_id}` }} · {{ variantNames.get((row.original as ErpStockMovement).erp_product_variant_id) ?? `Variante #${(row.original as ErpStockMovement).erp_product_variant_id}` }}</p>
            <p class="text-sm text-muted">
              Origem: {{ sourceTypeLabel((row.original as ErpStockMovement).source_type) }} #{{ (row.original as ErpStockMovement).source_id }}
            </p>
          </template>
          <template v-else-if="area === 'lots'">
            <p>{{ establishmentNames.get((row.original as ErpStockLot).erp_establishment_id) ?? `Estabelecimento #${(row.original as ErpStockLot).erp_establishment_id}` }} · {{ variantNames.get((row.original as ErpStockLot).erp_product_variant_id) ?? `Variante #${(row.original as ErpStockLot).erp_product_variant_id}` }}</p>
            <p class="text-sm text-muted">
              Movimento #{{ (row.original as ErpStockLot).erp_stock_movement_id }} · origem da camada #{{ (row.original as ErpStockLot).root_lot_id ?? (row.original as ErpStockLot).id }}
            </p>
          </template>
          <template v-else-if="area === 'adjustments'">
            <p>{{ establishmentNames.get((row.original as ErpStockAdjustment).erp_establishment_id) ?? `Estabelecimento #${(row.original as ErpStockAdjustment).erp_establishment_id}` }} · {{ variantNames.get((row.original as ErpStockAdjustment).erp_product_variant_id) ?? `Variante #${(row.original as ErpStockAdjustment).erp_product_variant_id}` }}</p>
            <p class="text-sm text-muted">
              {{ (row.original as ErpStockAdjustment).reason }}
            </p>
          </template>
          <template v-else>
            <p>{{ establishmentNames.get((row.original as ErpStockTransfer).from_establishment_id) ?? `#${(row.original as ErpStockTransfer).from_establishment_id}` }} → {{ establishmentNames.get((row.original as ErpStockTransfer).to_establishment_id) ?? `#${(row.original as ErpStockTransfer).to_establishment_id}` }}</p>
            <p class="text-sm text-muted">
              {{ variantNames.get((row.original as ErpStockTransfer).erp_product_variant_id) ?? `Variante #${(row.original as ErpStockTransfer).erp_product_variant_id}` }} · {{ transferStatusLabel((row.original as ErpStockTransfer).status) }}
            </p>
          </template>
        </template>
        <template #value-cell="{ row }">
          <template v-if="area === 'balances'">
            <p class="font-medium tabular-nums">
              {{ (row.original as ErpStockBalance).quantity_on_hand }}
            </p>
            <p class="text-sm text-muted">
              {{ balanceCost(row.original as ErpStockBalance) }}
            </p>
            <p v-if="(row.original as ErpStockBalance).reconciliation?.needs_reconciliation" class="text-xs text-warning">
              Saldo precisa de conciliação
            </p>
          </template>
          <template v-else-if="area === 'movements'">
            <p class="font-medium tabular-nums">
              {{ (row.original as ErpStockMovement).quantity }}
            </p>
            <p class="text-sm text-muted">
              {{ movementSummary(row.original as ErpStockMovement) }}
            </p>
          </template>
          <template v-else-if="area === 'lots'">
            <p class="font-medium tabular-nums">
              {{ (row.original as ErpStockLot).available_quantity }} / {{ (row.original as ErpStockLot).quantity }}
            </p>
            <p class="text-sm text-muted">
              {{ (row.original as ErpStockLot).total_cost ?? 'Custo desconhecido' }} {{ (row.original as ErpStockLot).currency ?? 'moeda não identificada' }} · {{ costStatusLabel((row.original as ErpStockLot).cost_status) }}
            </p>
            <p class="text-xs text-muted">
              Lote pai {{ (row.original as ErpStockLot).parent_lot_id ?? 'nenhum' }} · alocação pai {{ (row.original as ErpStockLot).parent_allocation_id ?? 'nenhuma' }} · conciliação pai {{ (row.original as ErpStockLot).parent_settlement_id ?? 'nenhuma' }}
            </p>
          </template>
          <template v-else-if="area === 'adjustments'">
            <p class="font-medium tabular-nums">
              {{ (row.original as ErpStockAdjustment).quantity_delta }}
            </p>
            <p class="text-sm text-muted">
              {{ (row.original as ErpStockAdjustment).posted_at ?? 'Data não informada' }}
            </p>
          </template>
          <template v-else>
            <p class="font-medium tabular-nums">
              {{ (row.original as ErpStockTransfer).quantity }}
            </p>
            <p class="text-sm text-muted">
              {{ (row.original as ErpStockTransfer).posted_at ?? 'Data não informada' }}
            </p>
          </template>
        </template>
        <template #actions-cell="{ row }">
          <div class="flex justify-end gap-1">
            <UButton
              v-if="area === 'movements' && workspace.allowed('erp.stock.movements.show')"
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-eye"
              :aria-label="`Abrir movimento ${(row.original as ErpStockMovement).id}`"
              @click="workspace.inspectMovement((row.original as ErpStockMovement).id)"
            />
            <UButton
              v-else-if="area === 'lots' && workspace.allowed('erp.stock.movements.show') && (row.original as ErpStockLot).erp_stock_movement_id"
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-eye"
              :aria-label="`Abrir movimento de origem ${(row.original as ErpStockLot).erp_stock_movement_id}`"
              @click="inspectLotMovement(row.original as ErpStockLot)"
            />
            <UButton
              v-else-if="area === 'adjustments' && workspace.allowed('erp.stock.adjustments.show')"
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-eye"
              :aria-label="`Abrir ajuste ${(row.original as ErpStockAdjustment).id}`"
              @click="workspace.inspectAdjustment((row.original as ErpStockAdjustment).id)"
            />
            <UButton
              v-else-if="area === 'transfers' && workspace.allowed('erp.stock.transfers.show')"
              size="xs"
              color="neutral"
              variant="ghost"
              icon="i-lucide-eye"
              :aria-label="`Abrir transferência ${(row.original as ErpStockTransfer).id}`"
              @click="workspace.inspectTransfer((row.original as ErpStockTransfer).id)"
            />
          </div>
        </template>
      </UTable>
      <ErpListFooter
        v-if="activeMeta"
        :page="activeMeta.current_page"
        :meta="activeMeta"
        :pending="workspace.loading[area]"
        @update:page="workspace.setPage(area, $event)"
      />
    </div>

    <UModal
      :open="adjustmentOpen"
      title="Novo ajuste de estoque"
      :unmount-on-hide="false"
      :ui="{ content: 'sm:max-w-3xl' }"
      @update:open="updateAdjustmentOpen"
    >
      <template #body>
        <UAlert
          v-if="workspace.selectedOperation.value?.operation === 'erp.stock.adjustments.store'"
          color="warning"
          variant="subtle"
          title="Operação de ajuste pendente"
        >
          <template #description>
            <span>Resultado original: estabelecimento {{ workspace.selectedOperation.value.payload.erp_establishment_id }}, variante {{ workspace.selectedOperation.value.payload.erp_product_variant_id }}, diferença {{ workspace.selectedOperation.value.payload.quantity_delta }}.</span>
          </template>
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              :disabled="workspace.mutating.value"
              label="Consultar operação"
              @click="workspace.reconcile"
            />
            <UButton
              v-if="workspace.canRetryExact.value"
              color="warning"
              variant="outline"
              :disabled="workspace.mutating.value"
              label="Repetir exatamente"
              @click="workspace.retryExact"
            />
          </template>
        </UAlert>
        <UAlert
          v-else-if="workspace.mutationError.value"
          :color="mutationColor()"
          variant="subtle"
          :title="workspace.mutationError.value"
        />
        <ErpStockAdjustmentForm
          ref="adjustmentForm"
          :key="`adjustment-form-${adjustmentFormKey}`"
          :establishments="workspace.establishments.value"
          :variants="workspace.variants.value"
          :busy="workspace.mutating.value"
          :locked="workspace.selectedOperation.value?.operation === 'erp.stock.adjustments.store'"
          :errors="fieldErrors"
          @submit="submitAdjustment"
        />
      </template>
    </UModal>
    <UModal
      :open="transferOpen"
      title="Nova transferência de estoque"
      :unmount-on-hide="false"
      :ui="{ content: 'sm:max-w-3xl' }"
      @update:open="updateTransferOpen"
    >
      <template #body>
        <UAlert
          v-if="workspace.selectedOperation.value?.operation === 'erp.stock.transfers.store'"
          color="warning"
          variant="subtle"
          title="Operação de transferência pendente"
        >
          <template #description>
            <span>Resultado original: origem {{ workspace.selectedOperation.value.payload.from_establishment_id }}, destino {{ workspace.selectedOperation.value.payload.to_establishment_id }}, quantidade {{ workspace.selectedOperation.value.payload.quantity }}.</span>
          </template>
          <template #actions>
            <UButton
              color="neutral"
              variant="outline"
              :disabled="workspace.mutating.value"
              label="Consultar operação"
              @click="workspace.reconcile"
            />
            <UButton
              v-if="workspace.canRetryExact.value"
              color="warning"
              variant="outline"
              :disabled="workspace.mutating.value"
              label="Repetir exatamente"
              @click="workspace.retryExact"
            />
          </template>
        </UAlert>
        <UAlert
          v-else-if="workspace.mutationError.value"
          :color="mutationColor()"
          variant="subtle"
          :title="workspace.mutationError.value"
        />
        <ErpStockTransferForm
          ref="transferForm"
          :key="`transfer-form-${transferFormKey}`"
          :establishments="workspace.establishments.value"
          :variants="workspace.variants.value"
          :busy="workspace.mutating.value"
          :locked="workspace.selectedOperation.value?.operation === 'erp.stock.transfers.store'"
          :errors="fieldErrors"
          @submit="submitTransfer"
        />
      </template>
    </UModal>

    <UModal
      v-model:open="workspace.detailOpen.value"
      :title="detailTitle"
      :unmount-on-hide="false"
      scrollable
      :ui="{ content: 'sm:max-w-5xl' }"
      @update:open="open => !open && workspace.closeDetail()"
    >
      <template #body>
        <UAlert
          v-if="workspace.detailError.value"
          color="error"
          variant="subtle"
          :title="workspace.detailError.value"
        />
        <div v-if="workspace.detailLoading.value && !workspace.detail.value" class="space-y-3" role="status">
          <USkeleton class="h-12 w-full" /><USkeleton class="h-32 w-full" /><span class="sr-only">Carregando detalhe do estoque</span>
        </div>
        <template v-else-if="workspace.detail.value?.kind === 'movement'">
          <UAlert
            v-if="movementOperationMatches()"
            color="warning"
            variant="subtle"
            title="Correção de movimento pendente"
          >
            <template #actions>
              <UButton
                color="neutral"
                variant="outline"
                :disabled="workspace.mutating.value"
                label="Consultar operação"
                @click="workspace.reconcile"
              />
              <UButton
                v-if="workspace.canRetryExact.value"
                color="warning"
                variant="outline"
                :disabled="workspace.mutating.value"
                label="Repetir exatamente"
                @click="workspace.retryExact"
              />
            </template>
          </UAlert>
          <UAlert
            v-else-if="workspace.mutationError.value"
            color="error"
            variant="subtle"
            :title="workspace.mutationError.value"
          />
          <ErpStockMovementDetail
            :key="`${workspace.detail.value.row.id}-${workspace.detail.value.row.reversal.remaining_quantity}`"
            :detail="workspace.detail.value.row"
            :can-reverse="workspace.allowed('erp.stock.movements.reverse') && workspace.detail.value.row.reversal.permission === 'erp.stock.movements.reverse'"
            :busy="workspace.mutating.value"
            :mutation-error="movementOperationMatches() ? '' : workspace.mutationError.value"
            :field-errors="fieldErrors"
            :can-open-transfer="workspace.allowed('erp.stock.transfers.show')"
            :can-open-purchase-return="workspace.allowed('erp.purchase-returns.index')"
            :can-open-sale-return="workspace.allowed('erp.sale-returns.index')"
            :operation-pending="movementOperationMatches()"
            :can-retry-exact="workspace.canRetryExact.value"
            @reverse="payload => workspace.reverseMovement(workspace.detail.value!.row.id, payload)"
            @reconcile="workspace.reconcile"
            @retry="workspace.retryExact"
            @open-transfer="openTransferFromMovement"
          />
        </template>
        <ErpStockTransferDetail
          v-else-if="workspace.detail.value?.kind === 'transfer'"
          :key="`${workspace.detail.value.row.id}-${workspace.detail.value.row.returned_quantity}`"
          :detail="workspace.detail.value.row"
          :can-reverse="workspace.allowed('erp.stock.transfers.reverse')"
          :busy="workspace.mutating.value"
          :mutation-error="transferOperationMatches() ? '' : workspace.mutationError.value"
          :field-errors="fieldErrors"
          :operation-pending="transferOperationMatches()"
          :can-retry-exact="workspace.canRetryExact.value"
          @reverse="payload => workspace.reverseTransfer(workspace.detail.value!.row.id, payload)"
          @reconcile="workspace.reconcile"
          @retry="workspace.retryExact"
        />
        <section v-else-if="workspace.detail.value?.kind === 'adjustment'" class="space-y-3" data-stock-adjustment-detail>
          <div class="grid gap-3 sm:grid-cols-2">
            <p>Ajuste #{{ workspace.detail.value.row.id }} · quantidade {{ workspace.detail.value.row.quantity_delta }}</p>
            <p>Motivo: {{ workspace.detail.value.row.reason }}</p>
            <p>Estabelecimento {{ establishmentNames.get(workspace.detail.value.row.erp_establishment_id) ?? `#${workspace.detail.value.row.erp_establishment_id}` }}</p>
            <p>Variante {{ variantNames.get(workspace.detail.value.row.erp_product_variant_id) ?? `#${workspace.detail.value.row.erp_product_variant_id}` }}</p>
          </div>
          <p class="text-sm text-muted">
            Movimentos vinculados: {{ workspace.detail.value.row.movement_ids.map(id => `#${id}`).join(', ') || 'nenhum' }}
          </p>
          <UButton
            v-if="workspace.allowed('erp.stock.movements.show') && workspace.detail.value.row.movement_ids[0]"
            color="neutral"
            variant="outline"
            label="Abrir movimentação vinculada"
            @click="workspace.inspectMovement(workspace.detail.value!.row.movement_ids[0]!)"
          />
        </section>
      </template>
    </UModal>
  </ErpCatalogPageShell>
</template>
