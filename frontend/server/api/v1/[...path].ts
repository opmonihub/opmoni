import { Buffer } from 'node:buffer'
import { certificateWritePath, ingressTimeout } from '#shared/utils/ingress'

export default defineEventHandler(async (event) => {
  const config = useRuntimeConfig(event)
  const clientIp = event.context.opmoniIngress?.ip || getRequestIP(event, { xForwardedFor: false }) || 'unknown'
  // Never trust browser-provided ingress or forwarding metadata.
  for (const header of Object.keys(event.node.req.headers)) {
    if (header.startsWith('x-opmoni-') || header.startsWith('x-forwarded-') || header === 'forwarded' || header === 'x-real-ip')
      Reflect.deleteProperty(event.node.req.headers, header)
  }
  const requestUrl = getRequestURL(event)
  let body: Buffer | undefined
  if (certificateWritePath(requestUrl.pathname) && !['GET', 'HEAD', 'OPTIONS'].includes(event.method)) {
    const limit = 4 * 1024 * 1024
    const tooLarge = () => {
      setResponseStatus(event, 413)
      setResponseHeader(event, 'cache-control', 'no-store')
      return { code: 'payload_too_large', message: 'A solicitação deve ter até 4 MiB.' }
    }
    if (Number(getRequestHeader(event, 'content-length')) > limit) return tooLarge()
    const reader = getRequestWebStream(event)?.getReader()
    const chunks: Buffer[] = []
    let length = 0
    if (reader) {
      while (true) {
        const { done, value } = await reader.read()
        if (done) break
        length += value.byteLength
        if (length > limit) {
          // H3 1's Node bridge continues enqueueing after cancel(); drain
          // without retaining bytes so oversized chunked uploads stay bounded.
          void (async () => {
            while (!(await reader.read()).done) { /* drain */ }
          })().catch(() => {})
          return tooLarge()
        }
        chunks.push(Buffer.from(value))
      }
    }
    body = Buffer.concat(chunks)
  }
  const target = `${config.backendUrl.replace(/\/$/, '')}${requestUrl.pathname}${requestUrl.search}`
  setResponseHeader(event, 'cache-control', 'no-store')
  const proxy = body === undefined ? proxyRequest : sendProxy
  return proxy(event, target, {
    headers: {
      ...getProxyRequestHeaders(event),
      'accept': getRequestHeader(event, 'accept') || 'application/json',
      'x-opmoni-ingress-key': config.ingressKey,
      'x-opmoni-client-ip': clientIp,
      'x-opmoni-client-proto': event.context.opmoniIngress?.proto || requestUrl.protocol.replace(':', '')
    },
    fetchOptions: { ...(body === undefined ? {} : { body: new Uint8Array(body).buffer, method: event.method }), redirect: 'manual', signal: AbortSignal.timeout(ingressTimeout(requestUrl.pathname, event.method)) }
  }).catch(() => {
    throw createError({ statusCode: 503, statusMessage: 'Access service unavailable', data: { code: 'service_unavailable', message: 'Serviço temporariamente indisponível. Tente novamente.' } })
  })
})
