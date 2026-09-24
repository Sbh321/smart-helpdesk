import { useSyncExternalStore } from 'react'

/** The layout breakpoint at which the full desktop layout starts (docs/06-design-system/responsive.md). */
export const WIDE_QUERY = '(min-width: 80rem)'

/**
 * Whether a CSS media query matches, kept current as the window resizes. Server or test environments
 * without `matchMedia` count as matching, which keeps the full desktop layout (the default).
 */
export function useMediaQuery(query: string): boolean {
  return useSyncExternalStore(
    (onChange) => {
      if (typeof window === 'undefined' || !window.matchMedia) return () => undefined
      const list = window.matchMedia(query)
      list.addEventListener('change', onChange)
      return () => list.removeEventListener('change', onChange)
    },
    () => (typeof window === 'undefined' || !window.matchMedia ? true : window.matchMedia(query).matches),
    () => true,
  )
}
