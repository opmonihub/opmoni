import assert from 'node:assert/strict'
import test from 'node:test'
import { certificateWritePath, ingressTimeout, multipartTransport } from '../shared/utils/ingress.ts'

test('diagnostic and certificate budgets preserve operational paths', () => {
  assert.equal(ingressTimeout('/api/v1/serpro/connectivity', 'POST'), 15000)
  assert.equal(ingressTimeout('/api/v1/admin/accounts/42/contracting-certificate', 'POST'), 15000)
  assert.equal(ingressTimeout('/api/v1/serpro/connection', 'PUT'), 15000)
  for (const path of ['/api/v1/me', '/api/v1/account/switch', '/api/v1/admin/users', '/api/v1/login']) assert.equal(ingressTimeout(path, 'POST'), 10000)
  for (const path of ['/api/v1/serpro/sync-runs', '/api/v1/serpro/monitoring/export', '/api/v1/fiscal/documents']) assert.equal(ingressTimeout(path, 'POST'), 120000)
})

test('Laravel-equivalent certificate and diagnostic paths retain the ingress policy', () => {
  for (const path of [
    '/api/v1/serpro/connection/',
    '/api/v1/serpro/connection///',
    '/api/v1/serpro/%63onnection/',
    '/api/v1/serpro%2fconnection',
    '/api/v1/admin/accounts/42/contracting-certificate/',
    '/api/v1/admin/accounts/%34%32/contracting%2Dcertificate//'
  ]) {
    assert.equal(certificateWritePath(path), true, path)
    for (const method of ['POST', 'PUT', 'delete']) assert.equal(ingressTimeout(path, method), 15000, `${method} ${path}`)
  }
  for (const path of ['/api/v1/serpro/connectivity/', '/api/v1/serpro/%63onnectivity//']) {
    assert.equal(ingressTimeout(path, 'POST'), 15000, path)
    assert.equal(certificateWritePath(path), false, path)
  }
  assert.equal(ingressTimeout('/api/v1/%6de/', 'GET'), 10000)
  assert.equal(ingressTimeout('/api/v1/serpro/connection/', 'GET'), 120000)
  assert.equal(ingressTimeout('/api/v1/admin/accounts/42/contracting-certificate/', 'GET'), 10000)
  assert.equal(ingressTimeout('/api/v1/serpro/sync-runs/', 'POST'), 120000)
})

test('route normalization decodes once after trimming literal trailing slashes', () => {
  for (const path of [
    '/api/v1/serpro/%2563onnection/',
    '/api/v1/serpro/connection%2F',
    '/api/v1/serpro/connection+',
    '/api/v1/serpro/connection%ZZ',
    '/api/v1/serpro/connection%FF'
  ]) {
    assert.equal(certificateWritePath(path), false, path)
    assert.equal(ingressTimeout(path, 'POST'), 120000, path)
  }
})

test('multipart PUT is transported as POST override preserving binary and empty password', async () => {
  const input = new FormData()
  const bytes = new Uint8Array([0, 128, 255, 13, 10, 0])
  input.set('certificate', new File([bytes], 'fixture.p12'))
  input.set('password', '')
  const result = multipartTransport('PUT', input)
  assert.equal(result.method, 'POST')
  const form = result.body as FormData
  assert.equal(form.get('_method'), 'PUT')
  assert.equal(form.get('password'), '')
  assert.deepEqual(new Uint8Array(await (form.get('certificate') as File).arrayBuffer()), bytes)
  assert.equal(input.has('_method'), false)
})
