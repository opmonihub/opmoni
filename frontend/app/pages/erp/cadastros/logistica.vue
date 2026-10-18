<script setup lang="ts">
import { erpCatalogRoutes } from '#shared/utils/erpCatalogs'

definePageMeta({ layout: 'erp', middleware: 'auth' })
type Carrier = { id: number, legal_name: string, tax_id: string | null, status: string }
type Vehicle = { id: number, plate: string, model: string | null, brand: string | null, status: string }
type Driver = { id: number, name: string, tax_id: string | null, status: string }
const carriers = useErpCatalogList<Carrier>(erpCatalogRoutes.carriers)
const vehicles = useErpCatalogList<Vehicle>(erpCatalogRoutes.vehicles)
const drivers = useErpCatalogList<Driver>(erpCatalogRoutes.drivers)
</script>

<template>
  <ErpCatalogPageShell title="Logística" description="Transportadoras, veículos e motoristas para CT-e/MDF-e.">
    <UCard>
      <template #header>
        Transportadoras
      </template>
      <UTable :loading="carriers.pending.value" :data="carriers.state.value?.data ?? []" :columns="[{ accessorKey: 'legal_name', header: 'Razão social' }, { accessorKey: 'tax_id', header: 'CNPJ/CPF' }]" />
      <ErpCatalogPagination
        v-model:page="carriers.page.value"
        :meta="carriers.state.value?.meta"
        :pending="carriers.pending.value"
        :error="carriers.error.value"
        @retry="carriers.refresh"
      />
    </UCard>
    <UCard>
      <template #header>
        Veículos
      </template>
      <UTable :loading="vehicles.pending.value" :data="vehicles.state.value?.data ?? []" :columns="[{ accessorKey: 'plate', header: 'Placa' }, { accessorKey: 'model', header: 'Modelo' }]" />
      <ErpCatalogPagination
        v-model:page="vehicles.page.value"
        :meta="vehicles.state.value?.meta"
        :pending="vehicles.pending.value"
        :error="vehicles.error.value"
        @retry="vehicles.refresh"
      />
    </UCard>
    <UCard>
      <template #header>
        Motoristas
      </template>
      <UTable :loading="drivers.pending.value" :data="drivers.state.value?.data ?? []" :columns="[{ accessorKey: 'name', header: 'Nome' }, { accessorKey: 'tax_id', header: 'CPF' }]" />
      <ErpCatalogPagination
        v-model:page="drivers.page.value"
        :meta="drivers.state.value?.meta"
        :pending="drivers.pending.value"
        :error="drivers.error.value"
        @retry="drivers.refresh"
      />
    </UCard>
  </ErpCatalogPageShell>
</template>
