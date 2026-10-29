export default defineEventHandler((event) => {
  if (!getRequestURL(event).pathname.startsWith('/_nuxt/'))
    setResponseHeader(event, 'cache-control', 'no-store')
  if (['/primeiro-acesso', '/erp/convite', '/redefinir-senha'].includes(getRequestURL(event).pathname))
    setResponseHeader(event, 'referrer-policy', 'no-referrer')
})
