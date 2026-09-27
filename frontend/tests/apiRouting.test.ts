import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { it } from 'node:test'

const laravelApiLocation = 'location ~ ^/(?:api/|sanctum/|up$)'

for (const [environment, configPath] of [
  ['development', '../../docker/nginx/dev.conf'],
  ['production', '../../docker/nginx/prod.conf']
] as const) {
  it(`routes Nuxt's icon API to Nuxt in ${environment}`, async () => {
    const config = await readFile(new URL(configPath, import.meta.url), 'utf8')
    const iconLocationIndex = config.indexOf('location ^~ /api/_nuxt_icon/')
    const laravelLocationIndex = config.indexOf(laravelApiLocation)

    assert.notEqual(iconLocationIndex, -1)
    assert.notEqual(laravelLocationIndex, -1)
    assert.ok(iconLocationIndex < laravelLocationIndex)

    const locationEnd = config.indexOf('\n    location', iconLocationIndex + 1)
    const iconLocation = config.slice(iconLocationIndex, locationEnd === -1 ? undefined : locationEnd)
    assert.match(iconLocation, /proxy_pass \$nuxt;/)
  })
}

it('does not fetch from the unimplemented notifications endpoint', async () => {
  const component = await readFile(new URL('../app/components/NotificationsSlideover.vue', import.meta.url), 'utf8')

  assert.doesNotMatch(component, /\$api<Notification\[\]>\('\/notifications'\)/)
})
