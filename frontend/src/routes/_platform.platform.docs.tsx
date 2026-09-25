import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { PlatformHandoff } from '@/features/platform'

/** The platform documentation (M5-01, ADR-0024): hands the signed-in admin over to its host. */
export const Route = createFileRoute('/_platform/platform/docs')({
  validateSearch: z.object({ next: z.string().optional() }),
  component: function PlatformDocs() {
    const { next } = Route.useSearch()
    return <PlatformHandoff target="docs" next={next} />
  },
})
