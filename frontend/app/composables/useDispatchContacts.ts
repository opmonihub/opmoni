import type { ClientDispatchContact } from '~/types/whatsapp'

/**
 * O CRUD dos Contatos de envio de um cliente (`/clients/{id}/dispatch-contacts`).
 * Qualquer membro lê; gravar é papel `admin|operador` — o backend responde 403
 * e quem chama decide esconder o botão. O `value` trafega normalizado:
 * telefone em dígitos, e-mail em minúsculas (a normalização fina é do backend,
 * que também devolve o 422 com o motivo legível).
 */
export function useDispatchContacts() {
  const { $api } = useNuxtApp()

  async function list(clientId: number, options: { signal?: AbortSignal } = {}) {
    const res = await $api<{ data: ClientDispatchContact[] }>(`/clients/${clientId}/dispatch-contacts`, { signal: options.signal })
    return res.data
  }

  async function create(clientId: number, payload: { label?: string | null, channel: 'whatsapp' | 'email', value: string }) {
    const res = await $api<{ data: ClientDispatchContact }>(
      `/clients/${clientId}/dispatch-contacts`,
      { method: 'POST', body: payload }
    )
    return res.data
  }

  /** Na edição o canal não troca — mudar de canal é um contato novo. */
  async function update(clientId: number, contactId: number, payload: { label?: string | null, value?: string }) {
    const res = await $api<{ data: ClientDispatchContact }>(
      `/clients/${clientId}/dispatch-contacts/${contactId}`,
      { method: 'PUT', body: payload }
    )
    return res.data
  }

  async function remove(clientId: number, contactId: number) {
    await $api(`/clients/${clientId}/dispatch-contacts/${contactId}`, { method: 'DELETE' })
  }

  return { list, create, update, remove }
}
