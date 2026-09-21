import { createFileRoute } from '@tanstack/react-router'
import { BrandingSettings } from '@/features/settings'

export const Route = createFileRoute('/$workspace/_app/settings/branding')({ component: BrandingSettings })
