export default defineEventHandler((event) => {
  if (!getRequestURL(event).pathname.startsWith('/_nuxt/'))
    setResponseHeader(event, 'cache-control', 'no-store')
  if (getRequestURL(event).pathname === '/primeiro-acesso')
    setResponseHeader(event, 'referrer-policy', 'no-referrer')
})
