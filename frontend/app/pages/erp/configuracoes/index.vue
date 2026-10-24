<script setup lang="ts">
import { erpWorkspaceSettingsGroups } from '~/utils/erpWorkspaceNav'

const { currentCompany } = useAuth()
const firstDestination = erpWorkspaceSettingsGroups('/erp/configuracoes', currentCompany.value)
  .flatMap(group => group[0]?.children ?? [])
  .find(child => typeof child.to === 'string')
await navigateTo(typeof firstDestination?.to === 'string' ? firstDestination.to : '/erp', { replace: true })
</script>

<template>
  <div />
</template>
