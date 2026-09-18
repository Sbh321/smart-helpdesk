import { createFileRoute, redirect } from '@tanstack/react-router'

/** `/platform` has nothing of its own yet; tenants are the only page. */
export const Route = createFileRoute('/_platform/platform/')({
  beforeLoad: () => {
    throw redirect({ to: '/platform/tenants', replace: true })
  },
})
