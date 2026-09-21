import { createFileRoute } from '@tanstack/react-router'
import { MediaLibraryScreen, mediaListSchema } from '@/features/media'

export const Route = createFileRoute('/$workspace/_app/settings/media')({
  validateSearch: mediaListSchema.searchSchema,
  component: MediaLibraryScreen,
})
