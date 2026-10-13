export default defineEventHandler((event) => {
  // Nitro's request-aware local fetch carries this server-created context.
  // Browser headers can never populate it. Preserve the physical request's IP
  // when SSR invokes the proxy without a second TCP connection.
  if (!event.context.opmoniIngress) {
    let ip = getRequestIP(event, { xForwardedFor: false })
    let proto = getRequestURL(event, { xForwardedHost: false, xForwardedProto: false }).protocol.replace(':', '')
    // The physical dev listener strips and replaces these before its private
    // Unix socket worker hop. Never accept them on a physical browser socket.
    if (import.meta.dev && !ip && getRequestHeader(event, 'x-opmoni-dev-key') === useRuntimeConfig(event).ingressKey) {
      ip = getRequestHeader(event, 'x-opmoni-dev-ip')
      proto = getRequestHeader(event, 'x-opmoni-dev-proto') || proto
    }
    if (ip) {
      event.context.opmoniIngress = { ip, proto }
      // Nitro 2 promotes only _platform from local fetch context.
      event.context._platform = { ...event.context._platform, opmoniIngress: event.context.opmoniIngress }
    }
  }
})
