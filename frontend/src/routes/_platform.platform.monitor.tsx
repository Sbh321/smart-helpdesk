import { createFileRoute } from '@tanstack/react-router'
import { z } from 'zod'
import { PlatformHandoff } from '@/features/platform'

/** Monitoring, Horizon, Telescope, health and the storage console (ADR-0024): hands the admin over to its host. */
export const Route = createFileRoute('/_platform/platform/monitor')({
  validateSearch: z.object({ next: z.string().optional() }),
  component: function PlatformMonitor() {
    const { next } = Route.useSearch()
    return <PlatformHandoff target="monitor" next={next} />
  },
})
