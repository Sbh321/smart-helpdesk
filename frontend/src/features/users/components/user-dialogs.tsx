import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { FieldGroup } from '@/components/ui/field'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useCan, useSession } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import {
  DEFAULT_ROLE_NAMES,
  inviteUser,
  roleQueries,
  updateUser,
  type WorkspaceUser,
} from '../api/user-queries'
import {
  beyondReachMessage,
  type EditUserFormValues,
  editUserFormSchema,
  type InviteUserFormValues,
  inviteUserFormSchema,
  roleLabel,
} from '../schemas'
import { CheckboxGroup, type CheckboxOption } from './checkbox-group'

const text = copy.users

/**
 * The roles a user can be given: the workspace's roles when the signed-in user may read them
 * (`roles.manage`), otherwise the five defaults. Roles a user holds that are not listed stay selectable.
 */
export function useRoleOptions(extra: readonly string[] = []): CheckboxOption[] {
  const tenantId = useSession().session?.tenant.id ?? ''
  const canReadRoles = useCan('roles.manage')
  const roles = useQuery({ ...roleQueries.list(tenantId), enabled: canReadRoles && tenantId !== '' })
  const names = roles.data?.map((role) => role.name) ?? [...DEFAULT_ROLE_NAMES]
  return [...new Set([...names, ...extra])].map((name) => ({ value: name, label: roleLabel(name) }))
}

/** Invalidates the user list, and `/me` when the signed-in user changed their own account. */
function useRefreshUsers() {
  const client = useQueryClient()
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  return async (userId?: string) => {
    await client.invalidateQueries({ queryKey: queryKeys.users.all(tenantId) })
    if (userId !== undefined && userId === session?.user.id) {
      await client.invalidateQueries({ queryKey: queryKeys.session.me() })
    }
  }
}

/**
 * The server half of the role checkboxes: 422 messages on `roles`/`roles.N`, and the 403 `forbidden`
 * with `meta.roles` (a role beyond the actor's reach), shown beside the roles rather than as a banner.
 */
function useRoleErrors() {
  const server = useServerErrors()
  const [beyondReach, setBeyondReach] = useState<string | undefined>()
  return {
    server,
    rolesMessages: [...(server.fields.roles ?? []), ...(beyondReach ? [beyondReach] : [])],
    reset: () => {
      server.reset()
      setBeyondReach(undefined)
    },
    capture: (error: unknown) => {
      const message = beyondReachMessage(error, roleLabel)
      if (message) {
        server.reset()
        setBeyondReach(message)
      } else {
        setBeyondReach(undefined)
        server.capture(error)
      }
    },
  }
}

function FormFooter({
  isSubmitting,
  submitLabel,
  busyLabel,
  onCancel,
}: {
  isSubmitting: boolean
  submitLabel: string
  busyLabel: string
  onCancel: () => void
}) {
  return (
    <DialogFooter>
      <Button type="button" variant="outline" disabled={isSubmitting} onClick={onCancel}>
        {text.cancel}
      </Button>
      <Button type="submit" disabled={isSubmitting}>
        {isSubmitting ? busyLabel : submitLabel}
      </Button>
    </DialogFooter>
  )
}

function InviteForm({ onDone }: { onDone: () => void }) {
  const refresh = useRefreshUsers()
  const errors = useRoleErrors()
  const roleOptions = useRoleOptions()
  const defaultValues: InviteUserFormValues = { name: '', email: '', roles: [] }
  const form = useForm({
    defaultValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: inviteUserFormSchema },
    onSubmit: async ({ value }) => {
      errors.reset()
      try {
        const input = inviteUserFormSchema.parse(value)
        await inviteUser(input)
        await refresh()
        toast.success(fill(text.invited, { email: input.email }))
        onDone()
      } catch (error) {
        errors.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={text.inviteTitle}
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        event.stopPropagation()
        void form.handleSubmit()
      }}
    >
      {errors.server.failure ? <FormErrorBanner title={text.failed} error={errors.server.failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="invite-user-name"
              label={text.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, errors.server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="email">
          {(field) => (
            <TextField
              id="invite-user-email"
              label={text.email}
              type="email"
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, errors.server.fields.email)}
            />
          )}
        </form.Field>
        <form.Field name="roles">
          {(field) => (
            <CheckboxGroup
              id="invite-user-roles"
              legend={text.roles}
              description={text.rolesHint}
              options={roleOptions}
              value={field.state.value}
              onChange={field.handleChange}
              errors={mergeMessages(field.state.meta.errors, errors.rolesMessages)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <FormFooter
            isSubmitting={isSubmitting}
            submitLabel={text.sendInvitation}
            busyLabel={text.sending}
            onCancel={onDone}
          />
        )}
      </form.Subscribe>
    </form>
  )
}

export function InviteUserDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
    >
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{text.inviteTitle}</DialogTitle>
          <DialogDescription>{text.inviteDescription}</DialogDescription>
        </DialogHeader>
        {open ? <InviteForm onDone={onClose} /> : null}
      </DialogContent>
    </Dialog>
  )
}

function EditForm({ user, onDone }: { user: WorkspaceUser; onDone: () => void }) {
  const refresh = useRefreshUsers()
  const errors = useRoleErrors()
  const roleOptions = useRoleOptions(user.roles)
  const defaultValues: EditUserFormValues = { name: user.name, roles: [...user.roles] }
  const form = useForm({
    defaultValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: editUserFormSchema },
    onSubmit: async ({ value }) => {
      errors.reset()
      try {
        await updateUser(user.id, editUserFormSchema.parse(value))
        await refresh(user.id)
        toast.success(text.saved)
        onDone()
      } catch (error) {
        errors.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={fill(text.editTitle, { name: user.name })}
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        event.stopPropagation()
        void form.handleSubmit()
      }}
    >
      {errors.server.failure ? <FormErrorBanner title={text.failed} error={errors.server.failure} /> : null}
      <p className="text-sm text-muted-foreground">{user.email}</p>
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="edit-user-name"
              label={text.name}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, errors.server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="roles">
          {(field) => (
            <CheckboxGroup
              id="edit-user-roles"
              legend={text.roles}
              description={text.rolesHint}
              options={roleOptions}
              value={field.state.value}
              onChange={field.handleChange}
              errors={mergeMessages(field.state.meta.errors, errors.rolesMessages)}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <FormFooter
            isSubmitting={isSubmitting}
            submitLabel={text.save}
            busyLabel={text.saving}
            onCancel={onDone}
          />
        )}
      </form.Subscribe>
    </form>
  )
}

export function EditUserDialog({ user, onClose }: { user: WorkspaceUser | null; onClose: () => void }) {
  return (
    <Dialog
      open={user !== null}
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
    >
      <DialogContent className="sm:max-w-lg">
        <DialogHeader>
          <DialogTitle>{fill(text.editTitle, { name: user?.name ?? '' })}</DialogTitle>
          <DialogDescription>{text.editDescription}</DialogDescription>
        </DialogHeader>
        {user ? <EditForm key={user.id} user={user} onDone={onClose} /> : null}
      </DialogContent>
    </Dialog>
  )
}
