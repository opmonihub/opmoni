export default defineEventHandler((event) => {
  const config = useRuntimeConfig(event)
  const clientIp = event.context.opmoniIngress?.ip || getRequestIP(event, { xForwardedFor: false }) || 'unknown'
  // Never trust browser-provided ingress or forwarding metadata.
  for (const header of Object.keys(event.node.req.headers)) {
    if (header.startsWith('x-opmoni-') || header.startsWith('x-forwarded-') || header === 'forwarded' || header === 'x-real-ip')
      Reflect.deleteProperty(event.node.req.headers, header)
  }
  const requestUrl = getRequestURL(event)
  const target = `${config.backendUrl.replace(/\/$/, '')}${requestUrl.pathname}${requestUrl.search}`
  setResponseHeader(event, 'cache-control', 'no-store')
  return proxyRequest(event, target, {
    headers: {
      'accept': getRequestHeader(event, 'accept') || 'application/json',
      'x-opmoni-ingress-key': config.ingressKey,
      'x-opmoni-client-ip': clientIp,
      'x-opmoni-client-proto': event.context.opmoniIngress?.proto || requestUrl.protocol.replace(':', '')
    },
    fetchOptions: { redirect: 'manual', signal: AbortSignal.timeout(120000) }
  }).catch(() => {
    throw createError({ statusCode: 503, statusMessage: 'Access service unavailable', data: { code: 'service_unavailable', message: 'Serviço temporariamente indisponível. Tente novamente.' } })
  })
})
