// Run only against the explicitly enabled isolated Laravel fixture + actual Nuxt listener.
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createHash } from 'node:crypto'

assert.equal(process.env.OPMONI_INGRESS_FIXTURE, '1')
const base = 'http://127.0.0.1:3316'
const directory = process.argv[2]
const fixture = JSON.parse(await readFile(`${directory}/fixture.json`, 'utf8'))
const bytes = await readFile(`${directory}/certificate.p12`)
const path = `/api/v1/admin/accounts/${fixture.account_id}/contracting-certificate`
function session() {
  const cookies = new Map()
  let csrf = ''
  async function send(path, options = {}) {
    const headers = new Headers(options.headers)
    headers.set('Accept', 'application/json')
    headers.set('Cookie', [...cookies].map(([key, value]) => `${key}=${value}`).join('; '))
    if (!headers.has('Origin')) headers.set('Origin', base)
    if (!headers.has('X-CSRF-Token') && csrf) headers.set('X-CSRF-Token', csrf)
    const response = await fetch(`${base}${path}`, { ...options, headers, redirect: 'manual' })
    for (const line of response.headers.getSetCookie()) {
      const pair = line.split(';')[0]
      const index = pair.indexOf('=')
      cookies.set(pair.slice(0, index), pair.slice(index + 1))
    }
    return response
  }
  async function refresh() {
    const response = await send('/api/v1/csrf')
    assert.equal(response.status, 200)
    csrf = (await response.json()).csrf_token
  }
  async function login(email) {
    await refresh()
    const response = await send('/api/v1/login', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ email, password: 'fixture-only-password' }) })
    assert.equal(response.status, 200, await response.text())
    await refresh()
  }
  return { send, refresh, login }
}
function upload(buffer = bytes) {
  const form = new FormData()
  form.set('_method', 'PUT')
  form.set('certificate', new File([buffer], 'generated.p12'))
  form.set('password', '')
  return form
}
const admin = session()
assert.equal((await admin.send(path)).status, 401)
await admin.login('ingress-admin@example.test')
assert.equal((await admin.send(path, { method: 'POST', body: upload(), headers: { Origin: 'https://wrong.invalid' } })).status, 403)
assert.equal((await admin.send(path, { method: 'POST', body: upload(), headers: { 'X-CSRF-Token': 'wrong' } })).status, 403)
const response = await admin.send(path, { method: 'POST', body: upload(), headers: {
  'X-Opmoni-Ingress-Key': 'forged', 'X-Opmoni-Client-IP': '192.0.2.99', 'X-Opmoni-Client-Proto': 'https',
  'X-Forwarded-For': '192.0.2.99', 'X-Real-IP': '192.0.2.99', 'Forwarded': 'for=192.0.2.99',
  'X-Opmoni-Dev-Key': 'forged', 'X-Opmoni-Dev-IP': '192.0.2.99'
} })
assert.equal(response.status, 200, await response.clone().text())
assert.equal(response.headers.get('x-test-effective-method'), 'PUT')
assert.equal(response.headers.get('x-test-file-sha256'), createHash('sha256').update(bytes).digest('hex'))
assert.equal(response.headers.get('x-test-file-sha256'), fixture.sha256)
assert.notEqual(response.headers.get('x-test-trusted-ip'), '192.0.2.99')
assert.match(response.headers.get('x-test-trusted-ip'), /127\.0\.0\.1/)
assert.equal(response.headers.get('x-test-forwarded-for'), '')
assert.match(response.headers.get('cache-control'), /no-store/)
assert.equal((await response.json()).data.configured, true)
// Laravel routes accept literal trailing slashes and a single percent decode.
// Prove those spellings still reach the real certificate handler for valid data.
for (const url of [`${path}/`, path.replace('contracting-certificate', '%63ontracting%2Dcertificate/')]) {
  const accepted = await admin.send(url, { method: 'POST', body: upload() })
  assert.equal(accepted.status, 200, await accepted.clone().text())
  assert.equal(accepted.headers.get('x-test-effective-method'), 'PUT')
  assert.equal(accepted.headers.get('x-test-file-sha256'), fixture.sha256)
  assert.match(accepted.headers.get('cache-control'), /no-store/)
  console.log(`PASS noncanonical upload ${url}: 200, effective PUT, complete file SHA256`)
}
assert.equal((await admin.send(path, { method: 'POST', body: upload(Buffer.alloc(2 * 1024 * 1024 + 1)) })).status, 413)
const oversized = new FormData()
oversized.set('_method', 'PUT')
oversized.set('padding', 'x'.repeat(4 * 1024 * 1024))
const large = await admin.send('/api/v1/serpro/connection', { method: 'POST', body: oversized })
assert.equal(large.status, 413)
assert.equal((await large.json()).code, 'payload_too_large')
const config = await admin.send('/api/v1/serpro/connection', { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ consumer_key: 'dummy-ingress-key', consumer_secret: 'dummy-ingress-secret', certificate_source: 'account', contracting_account_id: fixture.account_id }) })
assert.equal(config.status, 200, await config.text())
assert.equal((await admin.send('/api/v1/admin/accounts/999999999/contracting-certificate')).status, 404)
const member = session()
await member.login('ingress-member@example.test')
assert.equal((await member.send(path)).status, 403)
assert.equal((await member.send(path, { method: 'POST', body: upload() })).status, 403)
const metadata = await member.send('/api/v1/serpro/connection', { headers: { 'X-Account-Id': String(fixture.account_id) } })
assert.equal(metadata.status, 200, await metadata.clone().text())
assert.match(metadata.headers.get('cache-control'), /no-store/)
assert.equal((await metadata.json()).data.configured, true)
// Exercise both Content-Length and chunked bodies; the missing fixture header
// proves Nuxt rejected the body before forwarding it to Laravel's own limit.
for (const url of [
  '/api/v1/serpro/connection', '/api/v1/serpro/connection/', '/api/v1/serpro/%63onnection/',
  `${path}/`, path.replace('contracting-certificate', '%63ontracting%2Dcertificate/')
]) {
  for (const transport of ['content-length', 'chunked']) {
    const body = transport === 'content-length'
      ? Buffer.alloc(4 * 1024 * 1024 + 1)
      : new ReadableStream({ start(controller) {
          for (let i = 0; i < 65; i++) controller.enqueue(new Uint8Array(65536))
          controller.close()
        } })
    const rejected = await admin.send(url, { method: 'POST', duplex: 'half', headers: { 'Content-Type': 'application/octet-stream' }, body })
    assert.equal(rejected.status, 413, `${transport} ${url}`)
    assert.equal((await rejected.json()).code, 'payload_too_large')
    assert.match(rejected.headers.get('cache-control'), /no-store/)
    assert.equal(rejected.headers.get('x-test-effective-method'), null, `must reject before Laravel: ${transport} ${url}`)
    console.log(`PASS bounded ingress ${transport} ${url}: 413, no-store, no backend dispatch`)
  }
}
// PHP post_max_size may erase fields/files before Laravel runs; the global
// scoped guard must still classify the original Content-Length as 413.
if (process.env.OPMONI_DIRECT_BACKEND) {
  const direct = await fetch(`${process.env.OPMONI_DIRECT_BACKEND}/api/v1/serpro/connection`, {
    method: 'POST', headers: { 'Content-Type': 'application/octet-stream', 'Accept': 'application/json' }, body: Buffer.alloc(4 * 1024 * 1024 + 1)
  })
  assert.equal(direct.status, 413)
  assert.equal((await direct.json()).code, 'payload_too_large')
}
console.log('PASS actual Nuxt -> Laravel: auth/session/origin/CSRF, POST->PUT, full binary SHA256, empty password, trusted headers, no-store, member metadata boundary, 2MiB/4MiB overflow413')
