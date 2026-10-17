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
    </UCard>
    <UCard>
      <template #header>
        Veículos
      </template>
      <UTable :loading="vehicles.pending.value" :data="vehicles.state.value?.data ?? []" :columns="[{ accessorKey: 'plate', header: 'Placa' }, { accessorKey: 'model', header: 'Modelo' }]" />
    </UCard>
    <UCard>
      <template #header>
        Motoristas
      </template>
      <UTable :loading="drivers.pending.value" :data="drivers.state.value?.data ?? []" :columns="[{ accessorKey: 'name', header: 'Nome' }, { accessorKey: 'tax_id', header: 'CPF' }]" />
    </UCard>
  </ErpCatalogPageShell>
</template>
