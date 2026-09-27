const LOOPBACK_HOSTS = new Set(['localhost', '127.0.0.1', '::1', '[::1]'])

// No browser, quando a API configurada é loopback, usa o hostname da página:
// acesso via 127.0.0.1 (preview/proxy) segue same-site e os cookies Lax de
// sessão/CSRF funcionam. SSR e produção seguem a URL configurada.
export function apiOrigin(): string {
  const config = useRuntimeConfig()
  const url = new URL(import.meta.server ? config.apiUrl : config.public.apiUrl)

  if (import.meta.client && LOOPBACK_HOSTS.has(url.hostname) && LOOPBACK_HOSTS.has(window.location.hostname)) {
    url.hostname = window.location.hostname
  }

  return url.origin
}
