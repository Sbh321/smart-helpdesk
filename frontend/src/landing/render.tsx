import { renderToStaticMarkup } from 'react-dom/server'
import { landing } from '@/copy/landing'
import { LandingPage } from './landing-page'

/** Build-time entry (`scripts/prerender-landing.mjs`): the page as HTML, plus its title and description. */
export function render(): { html: string; title: string; description: string } {
  return {
    html: renderToStaticMarkup(<LandingPage />),
    title: landing.meta.title,
    description: landing.meta.description,
  }
}
