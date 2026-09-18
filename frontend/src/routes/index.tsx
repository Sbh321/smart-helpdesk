import { useForm } from '@tanstack/react-form'
import { createFileRoute, redirect, useNavigate } from '@tanstack/react-router'
import { AuthLayout } from '@/components/layout/auth-layout'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'
import { ensureSession, workspaceSchema } from '@/features/auth'
import { workspaceHref } from '@/lib/auth'
import { messagesOf } from '@/lib/forms/messages'

/**
 * `/` has no workspace, so it asks for one ([ADR-0021](docs/adr/0021-host-layout-and-tenant-resolution.md):
 * the workspace is a path segment on `app.<domain>`). A signed-in visitor goes straight to their own.
 */
export const Route = createFileRoute('/')({
  beforeLoad: async ({ context }) => {
    const session = await ensureSession(context.queryClient)
    if (session) {
      throw redirect({ href: workspaceHref(session), replace: true })
    }
  },
  component: WorkspaceEntryPage,
})

function WorkspaceEntryPage() {
  const navigate = useNavigate()
  const form = useForm({
    defaultValues: { workspace: '' },
    validators: { onSubmit: workspaceSchema },
    onSubmit: async ({ value }) => {
      await navigate({
        to: '/$workspace/login',
        params: { workspace: value.workspace.trim() },
        search: {},
      })
    },
  })

  return (
    <AuthLayout title={copy.workspaceEntry.heading} description={copy.workspaceEntry.body}>
      <form
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          void form.handleSubmit()
        }}
        className="flex flex-col gap-5"
      >
        <form.Field name="workspace">
          {(field) => (
            <TextField
              id="workspace"
              label={copy.workspaceEntry.label}
              description={copy.workspaceEntry.description}
              autoComplete="organization"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={messagesOf(field.state.meta.errors)}
            />
          )}
        </form.Field>
        <Button type="submit" size="lg">
          {copy.workspaceEntry.submit}
        </Button>
      </form>
    </AuthLayout>
  )
}
