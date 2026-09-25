import { createRoot } from 'react-dom/client'
import { LandingPage } from './landing-page'

/** `vite --config vite.landing.config.ts` only: the built site is prerendered by `render.tsx`. */
export function renderInto(element: HTMLElement): void {
  createRoot(element).render(<LandingPage />)
}
