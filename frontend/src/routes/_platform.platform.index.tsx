import { createFileRoute } from '@tanstack/react-router'
import { DashboardScreen } from '@/features/platform'

/** The console's start page (ADR-0025 §9). */
export const Route = createFileRoute('/_platform/platform/')({
  component: DashboardScreen,
})
