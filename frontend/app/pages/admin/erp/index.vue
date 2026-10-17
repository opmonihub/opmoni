<script setup lang="ts">
definePageMeta({ middleware: ['auth', 'super-admin'] })
await navigateTo('/admin/erp/assinaturas', { replace: true })
</script>
