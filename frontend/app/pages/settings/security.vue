<script setup lang="ts">
import * as z from 'zod'
import type { FormError } from '@nuxt/ui'

const passwordSchema = z.object({
  current: z.string().min(8, 'Mínimo de 8 caracteres'),
  new: z.string().min(8, 'Mínimo de 8 caracteres')
})

type PasswordSchema = z.output<typeof passwordSchema>

const password = reactive<Partial<PasswordSchema>>({
  current: '',
  new: ''
})

const validate = (state: Partial<PasswordSchema>): FormError[] => {
  const errors: FormError[] = []
  if (state.current && state.new && state.current === state.new) {
    errors.push({ name: 'new', message: 'A nova senha deve ser diferente da atual' })
  }
  return errors
}
</script>

<template>
  <UPageCard
    title="Senha"
    description="Confirme a senha atual antes de definir uma nova."
    variant="subtle"
  >
    <UForm
      :schema="passwordSchema"
      :state="password"
      :validate="validate"
      class="flex flex-col gap-4 max-w-xs"
    >
      <UFormField name="current" label="Senha atual">
        <UInput
          v-model="password.current"
          type="password"
          placeholder="Senha atual"
          class="w-full"
        />
      </UFormField>

      <UFormField name="new" label="Nova senha">
        <UInput
          v-model="password.new"
          type="password"
          placeholder="Nova senha"
          class="w-full"
        />
      </UFormField>

      <UButton label="Atualizar" class="w-fit" type="submit" />
    </UForm>
  </UPageCard>

  <UPageCard
    title="Conta"
    description="Excluir a conta remove permanentemente os dados associados. Esta ação não pode ser desfeita."
    class="bg-linear-to-tl from-error/10 from-5% to-default"
  >
    <template #footer>
      <UButton label="Excluir conta" color="error" />
    </template>
  </UPageCard>
</template>
