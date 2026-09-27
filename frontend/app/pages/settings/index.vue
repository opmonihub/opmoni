<script setup lang="ts">
import * as z from 'zod'
import type { FormSubmitEvent } from '@nuxt/ui'

const fileRef = ref<HTMLInputElement>()
const { user } = useAuth()

const profileSchema = z.object({
  name: z.string().min(2, 'Mínimo de 2 caracteres'),
  email: z.email('E-mail inválido'),
  username: z.string().min(2, 'Mínimo de 2 caracteres'),
  avatar: z.string().optional(),
  bio: z.string().optional()
})

type ProfileSchema = z.output<typeof profileSchema>

const profile = reactive<Partial<ProfileSchema>>({
  name: user.value?.name ?? '',
  email: user.value?.email ?? '',
  username: '',
  avatar: undefined,
  bio: undefined
})

watch(user, (value) => {
  if (!value) return
  if (!profile.name) profile.name = value.name
  if (!profile.email) profile.email = value.email
}, { immediate: true })

const toast = useToast()
async function onSubmit(_event: FormSubmitEvent<ProfileSchema>) {
  toast.add({
    title: 'Alterações salvas',
    description: 'Seu perfil foi atualizado.',
    icon: 'i-lucide-check',
    color: 'success'
  })
}

function onFileChange(e: Event) {
  const input = e.target as HTMLInputElement

  if (!input.files?.length) {
    return
  }

  profile.avatar = URL.createObjectURL(input.files[0]!)
}

function onFileClick() {
  fileRef.value?.click()
}
</script>

<template>
  <UForm
    id="settings"
    :schema="profileSchema"
    :state="profile"
    @submit="onSubmit"
  >
    <UPageCard
      title="Perfil"
      description="Informações exibidas na sua conta."
      variant="naked"
      orientation="horizontal"
      class="mb-4"
    >
      <UButton
        form="settings"
        label="Salvar alterações"
        color="neutral"
        type="submit"
        class="w-fit lg:ms-auto"
      />
    </UPageCard>

    <UPageCard variant="subtle">
      <UFormField
        name="name"
        label="Nome"
        description="Aparece em comunicados e no menu da conta."
        required
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput
          v-model="profile.name"
          autocomplete="off"
        />
      </UFormField>
      <USeparator />
      <UFormField
        name="email"
        label="E-mail"
        description="Usado para entrar e receber avisos da conta."
        required
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput
          v-model="profile.email"
          type="email"
          autocomplete="off"
        />
      </UFormField>
      <USeparator />
      <UFormField
        name="username"
        label="Nome de usuário"
        description="Identificador curto da sua conta."
        required
        class="flex max-sm:flex-col justify-between items-start gap-4"
      >
        <UInput
          v-model="profile.username"
          type="username"
          autocomplete="off"
        />
      </UFormField>
      <USeparator />
      <UFormField
        name="avatar"
        label="Avatar"
        description="JPG, GIF ou PNG. Máximo 1 MB."
        class="flex max-sm:flex-col justify-between sm:items-center gap-4"
      >
        <div class="flex flex-wrap items-center gap-3">
          <UAvatar
            :src="profile.avatar"
            :alt="profile.name"
            size="lg"
          />
          <UButton
            label="Escolher"
            color="neutral"
            @click="onFileClick"
          />
          <input
            ref="fileRef"
            type="file"
            class="hidden"
            accept=".jpg, .jpeg, .png, .gif"
            @change="onFileChange"
          >
        </div>
      </UFormField>
      <USeparator />
      <UFormField
        name="bio"
        label="Bio"
        description="Breve descrição do seu perfil."
        class="flex max-sm:flex-col justify-between items-start gap-4"
        :ui="{ container: 'w-full' }"
      >
        <UTextarea
          v-model="profile.bio"
          :rows="5"
          autoresize
          class="w-full"
        />
      </UFormField>
    </UPageCard>
  </UForm>
</template>
