<script setup lang="ts">
import { clientsListDocument } from '~/utils/clientsNav'
import { customerListPath } from '~/utils/customerRoutes'

definePageMeta({ middleware: 'auth' })

const route = useRoute()
const { canManageClients } = useAuth()
const createRequest = useState('customers-create', () => 0)

const customersListPath = customerListPath('certificate')
const customersListLabel = 'Meus clientes'

const onDetail = computed(() => route.path.startsWith('/customers/empresa/'))
const listDocument = computed(() => clientsListDocument(route.path))
const onList = computed(() => listDocument.value !== null)

const navbarTitle = computed(() => {
  if (onDetail.value || onList.value) return customersListLabel
  return 'Clientes'
})

const documents = [
  { label: 'Certificados', document: 'certificate' as const },
  { label: 'Procurações', document: 'poa' as const }
]

async function requestCreate() {
  if (!onList.value) await navigateTo(customersListPath)
  createRequest.value++
}
</script>

<template>
  <UDashboardPanel id="customers" :ui="{ body: 'min-h-0 flex-1 gap-0 overflow-hidden p-0 sm:p-0' }">
    <template #header>
      <UDashboardNavbar
        :title="navbarTitle"
        :ui="{
          root: 'h-auto min-h-(--ui-header-height) flex-wrap gap-y-2 py-2',
          right: 'min-w-0 max-w-full shrink flex-wrap gap-2'
        }"
      >
        <template #leading>
          <UDashboardSidebarCollapse />
        </template>
        <template v-if="onDetail" #title>
          <UButton
            icon="i-lucide-arrow-left"
            :label="customersListLabel"
            color="neutral"
            variant="ghost"
            :to="customersListPath"
            :aria-label="`Voltar para ${customersListLabel}`"
            class="min-w-0 max-w-full px-2 font-semibold text-highlighted"
          />
        </template>

        <template #right>
          <nav
            v-if="onList"
            aria-label="Tipo de documento"
            class="inline-flex max-w-full items-center gap-1 rounded-lg bg-elevated p-1"
          >
            <UButton
              v-for="item in documents"
              :key="item.document"
              :label="item.label"
              :to="customerListPath(item.document)"
              :active="listDocument === item.document"
              :aria-current="listDocument === item.document ? 'page' : undefined"
              color="neutral"
              variant="ghost"
              active-variant="solid"
              size="sm"
              class="justify-center whitespace-nowrap"
            />
          </nav>
          <UButton
            v-if="canManageClients && !onDetail"
            icon="i-lucide-plus"
            color="primary"
            aria-label="Novo cliente"
            class="sm:hidden"
            @click="requestCreate"
          />
          <UButton
            v-if="canManageClients && !onDetail"
            label="Novo cliente"
            icon="i-lucide-plus"
            color="primary"
            class="hidden sm:inline-flex"
            @click="requestCreate"
          />
        </template>
      </UDashboardNavbar>
    </template>

    <template #body>
      <NuxtPage />
    </template>
  </UDashboardPanel>
</template>
