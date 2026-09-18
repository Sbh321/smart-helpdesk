import { copy } from '@/copy/en'

/** The id of the `<main>` element every shell renders. */
export const MAIN_CONTENT_ID = 'main'

/**
 * First focusable element on the page: visually hidden until focused, then a normal button-sized link
 * (docs/06-design-system/accessibility.md §Added by us).
 */
export function SkipLink() {
  return (
    <a
      href={`#${MAIN_CONTENT_ID}`}
      className="sr-only rounded-lg bg-primary px-3 py-2 text-sm font-medium text-primary-foreground focus-visible:not-sr-only focus-visible:absolute focus-visible:top-2 focus-visible:left-2 focus-visible:z-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
    >
      {copy.app.skipToContent}
    </a>
  )
}
