import { createFileRoute } from '@tanstack/react-router'
import { UsersSettings, userListSchema } from '@/features/users'

export const Route = createFileRoute('/$workspace/_app/settings/users')({
  validateSearch: userListSchema.searchSchema,
  component: UsersSettings,
})
