<script setup lang="ts">
import { erpOperationalTableUi } from '~/components/erp/erpPresentation'
import type { TableColumn } from '@nuxt/ui'
import type { ErpProductRecord } from '#shared/types/erpCatalogs'
import type { ErpCatalogLabelLayoutDraft } from '~/composables/useErpCatalogLabels'
import { useErpCatalogLabels } from '~/composables/useErpCatalogLabels'
import { erpLabelSelectionKey } from '#shared/utils/erpCatalogLabels'
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'
import ErpCatalogPageShell from '~/components/erp/ErpCatalogPageShell.vue'

definePageMeta({ layout: 'erp', middleware: 'auth' })
useHead({ title: 'Etiquetas e impressão · Cadastros ERP' })

const labels = useErpCatalogLabels()
const {
  settings, layouts, printerProfiles, selectedLayoutId, selectedLines, layoutEditingId, layoutDraft, profileEditingId, profileDraft,
  loadingConfiguration, mutationBusy, authorityBlocked, refreshingAuthority, savingSettings, savingLayout, savingProfile,
  error, fieldErrors, fieldErrorTarget, settingsError, layoutError, profileError, outputError, outputPending,
  outputUrl, outputCopies, outputPages, outputMeta, settingsDirty, layoutDirty, selectedCopies, selectionOverLimit,
  activeLayoutOptions, defaultLayout, canGenerate, canReadSettings, canSaveSettings, canReadLayouts, canCreateLayout, canUpdateLayout,
  canSetLayoutDefault, canArchiveLayouts, canReadProfiles, canCreateProfile, canUpdateProfile, canArchiveProfiles
} = labels
const products = useErpCatalogList<ErpProductRecord>(erpCatalogRoutes.products)
const { search, page, state, pending, searchPending, stale, error: productsError, canRead } = products
const outputFrame = ref<HTMLIFrameElement | null>(null)
const frameReady = ref(false)
const selectedNames = ref<Record<string, string>>({})
const controlsDisabled = computed(() => mutationBusy.value || authorityBlocked.value || loadingConfiguration.value)
const canPrepare = computed(() => canGenerate.value && !controlsDisabled.value && !outputPending.value && !settingsDirty.value && !layoutDirty.value && !error.value && selectedLines.value.length > 0 && !selectionOverLimit.value)
const layoutChoice = computed({
  get: () => selectedLayoutId.value === null ? 'automatic' : String(selectedLayoutId.value),
  set: (value: string) => { selectedLayoutId.value = value === 'automatic' ? null : Number(value) }
})
const layoutOptions = computed(() => [
  { label: !canReadLayouts.value ? 'Padrão da empresa' : defaultLayout.value ? `Padrão: ${defaultLayout.value.name}` : 'Configuração básica', value: 'automatic' },
  ...activeLayoutOptions.value.map(option => ({ ...option, value: String(option.value) }))
])

type ProductLine = { key: string, product_id: number, variant_id?: number, name: string, detail: string, barcode: string | null, active: boolean }
const productLines = computed<ProductLine[]>(() => (state.value?.data ?? []).flatMap(product => [
  { key: `product:${product.id}`, product_id: product.id, name: product.name, detail: product.sku ?? 'Produto', barcode: product.barcode, active: product.status === 'active' },
  ...product.variants.map(variant => ({ key: `variant:${variant.id}`, product_id: product.id, variant_id: variant.id, name: `${product.name} · ${variant.name}`, detail: variant.sku ?? 'Variante', barcode: variant.barcode, active: product.status === 'active' && variant.status === 'active' }))
]))
const columns: TableColumn<ProductLine>[] = [
  { id: 'selection', header: 'Selecionar' },
  { accessorKey: 'name', header: 'Produto / variante' },
  { accessorKey: 'barcode', header: 'Código de barras' }
]
const dimensions: Array<{ key: keyof Pick<ErpCatalogLabelLayoutDraft, 'label_width_mm' | 'label_height_mm' | 'paper_width_mm' | 'paper_height_mm' | 'top_margin_mm' | 'left_margin_mm' | 'row_spacing_mm' | 'column_spacing_mm'>, label: string }> = [
  { key: 'label_width_mm', label: 'Largura da etiqueta (mm)' },
  { key: 'label_height_mm', label: 'Altura da etiqueta (mm)' },
  { key: 'paper_width_mm', label: 'Largura do papel (mm)' },
  { key: 'paper_height_mm', label: 'Altura do papel (mm)' },
  { key: 'top_margin_mm', label: 'Margem superior (mm)' },
  { key: 'left_margin_mm', label: 'Margem esquerda (mm)' },
  { key: 'row_spacing_mm', label: 'Espaço entre linhas (mm)' },
  { key: 'column_spacing_mm', label: 'Espaço entre colunas (mm)' }
]
const capabilities = ['default', 'simple', 'SP2000', 'TEP-200M', 'P822D']
const fieldError = (field: string, target: 'settings' | 'layout' | 'profile' | 'output') => fieldErrorTarget.value === target ? fieldErrors.value[field]?.[0] : undefined
const selectionError = (index: number) => fieldErrorTarget.value !== 'output' ? '' : Object.entries(fieldErrors.value).filter(([key]) => key === `items.${index}` || key.startsWith(`items.${index}.`)).flatMap(([, messages]) => messages).join(' ')

function toggleProduct(line: ProductLine, selected: boolean) {
  labels.toggleSelection({ product_id: line.product_id, ...(line.variant_id === undefined ? {} : { variant_id: line.variant_id }), quantity: labels.selectionQuantity(line) }, selected)
  if (selected)
    selectedNames.value[line.key] = line.name
  else
    selectedNames.value = Object.fromEntries(Object.entries(selectedNames.value).filter(([key]) => key !== line.key))
}
function markFrameReady(event: Event) {
  frameReady.value = event.target === outputFrame.value && outputFrame.value?.getAttribute('src') === outputUrl.value
}
watch(products.authorityBlocked, (blocked) => {
  if (blocked)
    labels.rejectAuthority(productsError.value || 'Confirme sua sessão para consultar os produtos e as etiquetas.')
}, { flush: 'sync' })
watch(outputUrl, () => {
  frameReady.value = false
}, { flush: 'sync' })
watch(labels.contextKey, () => {
  selectedNames.value = {}
}, { flush: 'sync' })
</script>

<template>
  <ErpCatalogPageShell title="Etiquetas e impressão" description="Selecione produtos e variantes, defina as quantidades e confira a impressão.">
    <UAlert v-if="error" color="error" :title="error">
      <template #actions>
        <UButton
          label="Tentar novamente"
          color="neutral"
          variant="outline"
          :loading="loadingConfiguration || refreshingAuthority"
          @click="labels.retryConfiguration()"
        />
      </template>
    </UAlert>
    <p v-if="loadingConfiguration" role="status" class="text-muted text-sm">
      Carregando configurações de etiquetas…
    </p>

    <div class="grid min-w-0 gap-6 xl:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
      <section aria-labelledby="label-products-heading" class="min-w-0 space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 id="label-products-heading" class="text-highlighted text-lg font-semibold">
            Produtos e variantes
          </h2>
          <UButton
            label="Atualizar produtos"
            icon="i-lucide-refresh-cw"
            color="neutral"
            variant="ghost"
            :loading="pending"
            :disabled="searchPending"
            @click="products.retry()"
          />
        </div>
        <UFormField label="Buscar produtos" name="product_search">
          <UInput
            v-model="search"
            placeholder="Nome, SKU ou código de barras"
            icon="i-lucide-search"
            class="w-full"
            :disabled="!canRead || authorityBlocked"
          />
        </UFormField>
        <UAlert v-if="productsError" :title="productsError" color="error" />
        <p v-if="stale" role="status" class="text-warning text-sm">
          Aguarde a atualização da lista para selecionar produtos.
        </p>
        <p v-if="!state && (pending || searchPending)" role="status" class="text-muted text-sm">
          Carregando produtos…
        </p>
        <div v-if="state" class="overflow-x-auto rounded-lg border border-default">
          <UTable
            :data="productLines"
            :columns="columns"
            :loading="pending || searchPending"
            :ui="{ ...erpOperationalTableUi, td: `${erpOperationalTableUi.td} whitespace-normal`, empty: 'py-8' }"
          >
            <template #selection-cell="{ row }">
              <UCheckbox
                :model-value="labels.hasSelection(row.original)"
                :aria-label="`Selecionar ${row.original.name}`"
                :disabled="!row.original.active || !canGenerate || controlsDisabled || stale"
                @update:model-value="toggleProduct(row.original, $event === true)"
              />
            </template>
            <template #name-cell="{ row }">
              <div class="min-w-32 space-y-1">
                <p class="text-highlighted font-medium">
                  {{ row.original.name }}
                </p>
                <p class="text-muted text-xs">
                  {{ row.original.detail }}{{ row.original.active ? '' : ' · Arquivado' }}
                </p>
              </div>
            </template>
            <template #barcode-cell="{ row }">
              <span class="text-sm break-all">{{ row.original.barcode || 'Não informado' }}</span>
            </template>
            <template #empty>
              Nenhum produto encontrado para esta busca.
            </template>
          </UTable>
        </div>
        <UPagination
          v-if="state && state.meta.total > state.meta.per_page"
          v-model:page="page"
          :total="state.meta.total"
          :items-per-page="state.meta.per_page"
          :sibling-count="0"
          :disabled="pending || searchPending"
        />

        <div class="space-y-3 border-t border-default pt-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <h3 class="text-highlighted font-medium">
              Seleção: {{ selectedCopies }} etiqueta{{ selectedCopies === 1 ? '' : 's' }}
            </h3>
            <UButton
              v-if="selectedLines.length"
              label="Limpar seleção"
              color="neutral"
              variant="ghost"
              :disabled="controlsDisabled"
              @click="labels.clearSelection()"
            />
          </div>
          <p v-if="!selectedLines.length" class="text-muted text-sm">
            Marque os produtos acima. A seleção permanece ao buscar ou mudar de página.
          </p>
          <div v-for="(line, index) in selectedLines" :key="erpLabelSelectionKey(line)" class="space-y-2 border-b border-default pb-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <span class="min-w-0 flex-1 text-sm break-words">{{ selectedNames[erpLabelSelectionKey(line)] || 'Produto selecionado' }}</span>
              <UFormField
                :label="`Quantidade de ${selectedNames[erpLabelSelectionKey(line)] || 'produto'}`"
                :name="`items.${index}.quantity`"
                class="w-36"
                :ui="{ label: 'sr-only' }"
              >
                <UInputNumber
                  :model-value="line.quantity"
                  :min="1"
                  :max="250"
                  :step="1"
                  :disabled="controlsDisabled"
                  class="w-full"
                  @update:model-value="labels.setQuantity(line, $event)"
                />
              </UFormField>
              <UButton
                icon="i-lucide-x"
                :aria-label="`Remover ${selectedNames[erpLabelSelectionKey(line)] || 'produto'}`"
                color="neutral"
                variant="ghost"
                :disabled="controlsDisabled"
                @click="labels.toggleSelection(line, false)"
              />
            </div>
            <p v-if="selectionError(index)" role="alert" class="text-error text-sm">
              {{ selectionError(index) }}
            </p>
          </div>
          <p v-if="selectionOverLimit" role="alert" class="text-error text-sm">
            Selecione até 100 linhas e 250 etiquetas no total.
          </p>
          <p v-if="fieldError('items', 'output')" role="alert" class="text-error text-sm">
            {{ fieldError('items', 'output') }}
          </p>
        </div>
      </section>

      <section aria-labelledby="label-preview-heading" class="min-w-0 space-y-4">
        <h2 id="label-preview-heading" class="text-highlighted text-lg font-semibold">
          Prévia e impressão
        </h2>
        <UFormField
          label="Layout de impressão"
          name="layout_id"
          :error="fieldError('layout_id', 'output')"
          help="Sem um layout selecionado, usamos o padrão salvo ou a configuração básica."
        >
          <USelect
            v-model="layoutChoice"
            :items="layoutOptions"
            class="w-full"
            :disabled="controlsDisabled || !canGenerate"
          />
        </UFormField>
        <UAlert v-if="settingsDirty || layoutDirty" title="Salve as alterações de configuração antes de preparar as etiquetas." color="warning" />
        <UAlert v-if="outputError" color="error" :title="outputError" />
        <UButton
          label="Preparar etiquetas"
          icon="i-lucide-scan-barcode"
          :disabled="!canPrepare"
          :loading="outputPending"
          @click="labels.generateOutput()"
        />
        <p v-if="!canGenerate" class="text-muted text-sm">
          Você não tem permissão para gerar etiquetas nesta empresa.
        </p>
        <template v-if="outputUrl">
          <p role="status" class="text-muted text-sm">
            {{ outputCopies }} etiquetas em {{ outputPages }} folha{{ outputPages === 1 ? '' : 's' }} · {{ outputMeta?.layout.name }}
          </p>
          <div class="flex flex-wrap gap-2">
            <UButton
              label="Baixar HTML"
              icon="i-lucide-download"
              color="neutral"
              variant="outline"
              @click="labels.downloadOutput()"
            />
            <UButton
              label="Imprimir"
              icon="i-lucide-printer"
              :disabled="!frameReady"
              @click="labels.printOutput(outputFrame)"
            />
          </div>
          <iframe
            :key="outputUrl"
            ref="outputFrame"
            :src="outputUrl"
            title="Prévia das etiquetas"
            sandbox="allow-same-origin allow-modals"
            class="h-96 w-full rounded-lg border border-default bg-default"
            @load="markFrameReady"
          />
          <p class="text-muted text-xs">
            Imprima em tamanho real (100%), sem ajustar à página. A impressão é feita pelo diálogo do navegador.
          </p>
        </template>
        <p v-else class="text-muted text-sm">
          A prévia aparece após validar a seleção e o tamanho do papel. O arquivo HTML inclui os códigos de barras e funciona sem conexão.
        </p>
      </section>
    </div>

    <section v-if="canReadSettings" aria-labelledby="label-settings-heading" class="space-y-4 border-t border-default pt-6">
      <h2 id="label-settings-heading" class="text-highlighted text-lg font-semibold">
        Configuração básica
      </h2>
      <p class="text-muted text-sm">
        Preço e código de barras valem para todos os layouts. As dimensões básicas valem quando não há layout selecionado nem padrão salvo.
      </p>
      <UAlert v-if="settingsError" color="error" :title="settingsError" />
      <form class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="labels.saveSettings()">
        <UFormField label="Largura básica (mm)" name="label_width_mm" :error="fieldError('label_width_mm', 'settings')">
          <UInput
            v-model.number="settings.label_width_mm"
            type="number"
            min="20"
            max="200"
            step="1"
            class="w-full"
            :disabled="controlsDisabled || !canSaveSettings"
          />
        </UFormField>
        <UFormField label="Altura básica (mm)" name="label_height_mm" :error="fieldError('label_height_mm', 'settings')">
          <UInput
            v-model.number="settings.label_height_mm"
            type="number"
            min="10"
            max="200"
            step="1"
            class="w-full"
            :disabled="controlsDisabled || !canSaveSettings"
          />
        </UFormField>
        <div class="space-y-3 sm:self-center">
          <UCheckbox v-model="settings.show_price" label="Exibir preço" :disabled="controlsDisabled || !canSaveSettings" />
          <UCheckbox v-model="settings.show_barcode" label="Exibir código de barras" :disabled="controlsDisabled || !canSaveSettings" />
        </div>
        <UButton
          type="submit"
          label="Salvar configuração básica"
          class="justify-self-start self-end"
          :loading="savingSettings"
          :disabled="controlsDisabled || !canSaveSettings"
        />
      </form>
    </section>

    <UCollapsible v-if="canReadLayouts" :unmount-on-hide="false" class="space-y-4 border-t border-default pt-6">
      <UButton
        label="Gerenciar layouts de impressão"
        icon="i-lucide-panels-top-left"
        trailing-icon="i-lucide-chevron-down"
        color="neutral"
        variant="outline"
      />
      <template #content>
        <section aria-label="Layouts de impressão" class="space-y-5 pt-4">
          <UAlert v-if="layoutError" color="error" :title="layoutError" />
          <p v-if="!layouts.length" class="text-muted text-sm">
            Nenhum layout salvo. A configuração básica continua disponível.
          </p>
          <ul class="divide-y divide-default">
            <li v-for="layout in layouts" :key="layout.id!" class="flex flex-wrap items-center justify-between gap-3 py-3">
              <div>
                <p class="font-medium break-words">
                  {{ layout.name }}{{ layout.is_default ? ' · Padrão' : '' }}{{ layout.status === 'archived' ? ' · Arquivado' : '' }}
                </p><p class="text-muted text-sm">
                  {{ layout.label_width_mm }} × {{ layout.label_height_mm }} mm · {{ layout.is_continuous ? 'Contínuo' : `${layout.labels_per_sheet} por folha` }}
                </p>
              </div>
              <div v-if="layout.status === 'active'" class="flex flex-wrap gap-1">
                <UButton
                  v-if="canUpdateLayout"
                  label="Editar"
                  color="neutral"
                  variant="ghost"
                  :disabled="controlsDisabled"
                  @click="labels.editLayout(layout)"
                />
                <UButton
                  v-if="canSetLayoutDefault && !layout.is_default"
                  label="Usar como padrão"
                  color="neutral"
                  variant="ghost"
                  :disabled="controlsDisabled"
                  @click="labels.makeLayoutDefault(layout.id!)"
                />
                <UButton
                  v-if="canArchiveLayouts"
                  label="Arquivar"
                  color="error"
                  variant="ghost"
                  :disabled="controlsDisabled"
                  @click="labels.archiveLayout(layout.id!)"
                />
              </div>
            </li>
          </ul>
          <template v-if="canCreateLayout || (layoutEditingId !== null && canUpdateLayout)">
            <div class="flex flex-wrap items-center justify-between gap-3">
              <h3 class="text-highlighted font-medium">
                {{ layoutEditingId === null ? 'Novo layout' : 'Editar layout' }}
              </h3><UButton
                v-if="canCreateLayout && layoutEditingId !== null"
                label="Novo layout"
                color="neutral"
                variant="outline"
                :disabled="controlsDisabled"
                @click="labels.editLayout(null)"
              />
            </div>
            <form class="space-y-4" @submit.prevent="labels.saveLayout()">
              <fieldset :disabled="controlsDisabled" class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <UFormField
                  label="Nome do layout"
                  name="name"
                  :error="fieldError('name', 'layout')"
                  class="sm:col-span-2"
                >
                  <UInput
                    v-model="layoutDraft.name"
                    maxlength="100"
                    required
                    class="w-full"
                  />
                </UFormField>
                <UFormField
                  label="Descrição"
                  name="description"
                  :error="fieldError('description', 'layout')"
                  class="sm:col-span-2"
                >
                  <UInput v-model="layoutDraft.description" maxlength="500" class="w-full" />
                </UFormField>
                <UFormField
                  v-for="dimension in dimensions"
                  v-show="dimension.key !== 'paper_height_mm' || !layoutDraft.is_continuous"
                  :key="dimension.key"
                  :label="dimension.label"
                  :name="dimension.key"
                  :error="fieldError(dimension.key, 'layout')"
                >
                  <UInput
                    v-model="layoutDraft[dimension.key]"
                    inputmode="decimal"
                    placeholder="50.0000"
                    class="w-full"
                  />
                </UFormField>
                <UFormField label="Colunas por linha" name="columns_per_row" :error="fieldError('columns_per_row', 'layout')">
                  <UInputNumber
                    v-model="layoutDraft.columns_per_row"
                    :min="1"
                    :max="20"
                    class="w-full"
                  />
                </UFormField>
                <UFormField
                  v-if="!layoutDraft.is_continuous"
                  label="Etiquetas por folha"
                  name="labels_per_sheet"
                  :error="fieldError('labels_per_sheet', 'layout')"
                >
                  <UInputNumber
                    v-model="layoutDraft.labels_per_sheet"
                    :min="1"
                    :max="250"
                    class="w-full"
                  />
                </UFormField>
                <UCheckbox v-model="layoutDraft.is_continuous" label="Papel contínuo" class="self-center" />
              </fieldset>
              <p class="text-muted text-xs">
                Dimensões em milímetros, com até quatro casas decimais e ponto como separador. O papel deve comportar as etiquetas, margens e espaçamentos.
              </p>
              <UButton
                type="submit"
                label="Salvar layout"
                :loading="savingLayout"
                :disabled="controlsDisabled"
              />
            </form>
          </template>
        </section>
      </template>
    </UCollapsible>

    <UCollapsible v-if="canReadProfiles" :unmount-on-hide="false" class="space-y-4 border-t border-default pt-6">
      <UButton
        label="Gerenciar perfis de impressora"
        icon="i-lucide-printer"
        trailing-icon="i-lucide-chevron-down"
        color="neutral"
        variant="outline"
      />
      <template #content>
        <section aria-label="Perfis de impressora" class="space-y-4 pt-4">
          <p class="text-muted text-sm">
            Registre o nome e o perfil de compatibilidade da impressora. A saída desta tela usa a impressão do navegador.
          </p>
          <UAlert v-if="profileError" color="error" :title="profileError" />
          <p v-if="!printerProfiles.length" class="text-muted text-sm">
            Nenhum perfil de impressora salvo.
          </p>
          <ul class="divide-y divide-default">
            <li v-for="profile in printerProfiles" :key="profile.id" class="flex flex-wrap items-center justify-between gap-3 py-3">
              <p class="break-words">
                {{ profile.name }} · {{ profile.capability_profile }}{{ profile.status === 'archived' ? ' · Arquivado' : '' }}
              </p>
              <div v-if="profile.status === 'active'" class="flex gap-1">
                <UButton
                  v-if="canUpdateProfile"
                  label="Editar perfil"
                  color="neutral"
                  variant="ghost"
                  :disabled="controlsDisabled"
                  @click="labels.editProfile(profile)"
                />
                <UButton
                  v-if="canArchiveProfiles"
                  label="Arquivar perfil"
                  color="error"
                  variant="ghost"
                  :disabled="controlsDisabled"
                  @click="labels.archiveProfile(profile.id)"
                />
              </div>
            </li>
          </ul>
          <form v-if="canCreateProfile || (profileEditingId !== null && canUpdateProfile)" class="grid items-end gap-4 sm:grid-cols-2 lg:grid-cols-4" @submit.prevent="labels.saveProfile()">
            <UFormField label="Nome da impressora" name="name" :error="fieldError('name', 'profile')">
              <UInput
                v-model="profileDraft.name"
                required
                maxlength="100"
                class="w-full"
                :disabled="controlsDisabled"
              />
            </UFormField>
            <UFormField label="Perfil de compatibilidade" name="capability_profile" :error="fieldError('capability_profile', 'profile')">
              <USelect
                v-model="profileDraft.capability_profile"
                :items="capabilities"
                class="w-full"
                :disabled="controlsDisabled"
              />
            </UFormField>
            <UButton
              type="submit"
              :label="profileEditingId === null ? 'Salvar perfil' : 'Salvar alterações do perfil'"
              class="justify-self-start"
              :loading="savingProfile"
              :disabled="controlsDisabled"
            />
            <UButton
              v-if="profileEditingId !== null && canCreateProfile"
              label="Novo perfil"
              color="neutral"
              variant="outline"
              class="justify-self-start"
              :disabled="controlsDisabled"
              @click="labels.editProfile(null)"
            />
          </form>
        </section>
      </template>
    </UCollapsible>
  </ErpCatalogPageShell>
</template>
