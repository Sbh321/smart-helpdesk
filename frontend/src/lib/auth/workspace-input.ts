/**
 * What a visitor types into "Workspace" (M5-03): usually the slug, sometimes the whole address they
 * were sent (`https://app.shp…/acme/login`) or a name with capitals and spaces. This turns any of those
 * into the slug to try, without pretending to know whether the workspace exists.
 */
export function normaliseWorkspaceInput(raw: string, platformDomain: string): string {
  let value = raw.trim().toLowerCase()
  if (value.length === 0) return ''

  value = value.replace(/^[a-z][a-z0-9+.-]*:\/\//, '')
  const hosts = [`app.${platformDomain.toLowerCase()}`, platformDomain.toLowerCase()]
  for (const host of hosts) {
    if (value === host) return ''
    if (value.startsWith(`${host}/`)) {
      value = value.slice(host.length + 1)
      break
    }
  }

  const [first = ''] = value.split(/[/?#]/)
  return first.replace(/\s+/g, '-')
}
