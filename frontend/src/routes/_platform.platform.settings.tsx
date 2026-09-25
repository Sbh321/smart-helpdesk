import { createFileRoute } from '@tanstack/react-router'
import { PlatformSettingsScreen } from '@/features/platform'

export const Route = createFileRoute('/_platform/platform/settings')({
  component: PlatformSettingsScreen,
})
