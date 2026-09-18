import type { MaybeSession } from './session'

/**
 * Permission checks. Code always names a permission, never a role
 * (docs/03-architecture/backend.md, CLAUDE.md non-negotiables).
 *
 * `/v1/me` returns a flat list of the permissions the signed-in user's roles grant (M1-09). The list
 * is used as-is: no wildcards, no prefix matching and no implicit grants.
 */
export function hasPermission(
  permissions: readonly string[] | null | undefined,
  permission: string,
): boolean {
  if (permission.length === 0 || !permissions) {
    return false
  }
  return permissions.includes(permission)
}

/** True when at least one of the named permissions is granted. An empty list is never granted. */
export function hasAnyPermission(
  permissions: readonly string[] | null | undefined,
  required: readonly string[],
): boolean {
  return required.some((permission) => hasPermission(permissions, permission))
}

/** True when every named permission is granted. An empty requirement list is granted. */
export function hasAllPermissions(
  permissions: readonly string[] | null | undefined,
  required: readonly string[],
): boolean {
  return required.every((permission) => hasPermission(permissions, permission))
}

/** Permissions of a session, or an empty list when nobody is signed in. */
export function permissionsOf(session: MaybeSession | undefined): readonly string[] {
  return session?.permissions ?? []
}
