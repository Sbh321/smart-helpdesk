import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { ErrorState } from '@/components/shared/error-state'
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
import { FieldError, FieldGroup, FieldLegend, FieldSet } from '@/components/ui/field'
import { Skeleton } from '@/components/ui/skeleton'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import {
  createRole,
  type PermissionCatalogue,
  type PermissionName,
  type Role,
  roleQueries,
  updateRole,
} from '../api/user-queries'
import {
  beyondReachMessage,
  groupLabel,
  groupPermissions,
  groupState,
  permissionLabel,
  type RoleFormValues,
  roleFormSchema,
  toggleGroup,
} from '../schemas'
import { CheckboxItem } from './checkbox-group'

const text = copy.roles

/** `undefined`: closed; `null`: create; a role: edit it. */
export type RoleEditorTarget = Role | null | undefined

/** Permission checkboxes grouped by resource, each group with a "select all" checkbox. */
function PermissionPicker({
  catalogue,
  value,
  onChange,
  errors,
}: {
  catalogue: PermissionCatalogue
  value: readonly string[]
  onChange: (value: string[]) => void
  errors: readonly string[]
}) {
  const invalid = errors.length > 0
  return (
    <FieldSet
      aria-describedby={invalid ? 'role-permissions-error' : undefined}
      aria-invalid={invalid || undefined}
      className="gap-3"
    >
      <FieldLegend variant="label">{text.permissions}</FieldLegend>
      {invalid ? (
        <FieldError id="role-permissions-error">
          {errors.length === 1 ? (
            errors[0]
          ) : (
            <ul className="ml-4 flex list-disc flex-col gap-1">
              {errors.map((message) => (
                <li key={message}>{message}</li>
              ))}
            </ul>
          )}
        </FieldError>
      ) : null}
      <div className="grid gap-3 sm:grid-cols-2">
        {Object.entries(catalogue).map(([resource, actions]) => {
          const names = groupPermissions(resource, actions)
          const state = groupState(value, names)
          const group = groupLabel(resource)
          return (
            <fieldset key={resource} className="space-y-2 rounded-lg border border-border p-3 bg-surface">
              <legend className="px-1 text-sm font-medium">{group}</legend>
              <CheckboxItem
                id={`role-group-${resource}`}
                label={fill(text.selectAll, { group })}
                checked={state === 'all'}
                indeterminate={state === 'some'}
                onCheckedChange={(checked) => onChange(toggleGroup(value, names, checked))}
                className="border-b border-border pb-2"
              />
              {names.map((name) => (
                <CheckboxItem
                  key={name}
                  id={`role-permission-${name.replace('.', '-')}`}
                  label={permissionLabel(name)}
                  checked={value.includes(name)}
                  onCheckedChange={(checked) =>
                    onChange(checked ? [...value, name] : value.filter((item) => item !== name))
                  }
                />
              ))}
            </fieldset>
          )
        })}
      </div>
    </FieldSet>
  )
}

function RoleForm({
  role,
  catalogue,
  onDone,
}: {
  role: Role | null
  catalogue: PermissionCatalogue
  onDone: () => void
}) {
  const { session } = useSession()
  const tenantId = session?.tenant.id ?? ''
  const client = useQueryClient()
  const server = useServerErrors()
  const [beyondReach, setBeyondReach] = useState<string | undefined>()
  const defaultValues: RoleFormValues = {
    name: role?.name ?? '',
    permissions: [...(role?.permissions ?? [])],
  }
  const form = useForm({
    defaultValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: roleFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      setBeyondReach(undefined)
      try {
        const parsed = roleFormSchema.parse(value)
        const input = { name: parsed.name, permissions: parsed.permissions as PermissionName[] }
        await (role ? updateRole(role.id, input) : createRole(input))
        await client.invalidateQueries({ queryKey: queryKeys.roles.all(tenantId) })
        // Holders of an edited role (the signed-in user among them) may have new permissions.
        if (role) await client.invalidateQueries({ queryKey: queryKeys.session.me() })
        toast.success(text.saved)
        onDone()
      } catch (error) {
        const message = beyondReachMessage(error, permissionLabel)
        if (message) setBeyondReach(message)
        else server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      aria-label={role ? fill(text.editTitle, { name: role.name }) : text.createTitle}
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        event.stopPropagation()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <FieldGroup>
        <form.Field name="name">
          {(field) => (
            <TextField
              id="role-name"
              label={text.name}
              description={text.nameHint}
              autoComplete="off"
              value={field.state.value}
              onValueChange={field.handleChange}
              onBlur={field.handleBlur}
              errors={mergeMessages(field.state.meta.errors, server.fields.name)}
            />
          )}
        </form.Field>
        <form.Field name="permissions">
          {(field) => (
            <PermissionPicker
              catalogue={catalogue}
              value={field.state.value}
              onChange={field.handleChange}
              errors={mergeMessages(field.state.meta.errors, [
                ...(server.fields.permissions ?? []),
                ...(beyondReach ? [beyondReach] : []),
              ])}
            />
          )}
        </form.Field>
      </FieldGroup>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <DialogFooter>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={onDone}>
              {text.cancel}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? text.saving : text.save}
            </Button>
          </DialogFooter>
        )}
      </form.Subscribe>
    </form>
  )
}

export function RoleEditorDialog({ target, onClose }: { target: RoleEditorTarget; onClose: () => void }) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const open = target !== undefined
  const catalogue = useQuery({ ...roleQueries.permissions(tenantId), enabled: open && tenantId !== '' })
  return (
    <Dialog
      open={open}
      onOpenChange={(next) => {
        if (!next) onClose()
      }}
    >
      <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
        <DialogHeader>
          <DialogTitle>{target ? fill(text.editTitle, { name: target.name }) : text.createTitle}</DialogTitle>
          <DialogDescription>{text.editorDescription}</DialogDescription>
        </DialogHeader>
        {!open ? null : catalogue.isPending ? (
          <Skeleton className="h-64 w-full" />
        ) : catalogue.isError ? (
          <ErrorState error={catalogue.error} onRetry={() => void catalogue.refetch()} />
        ) : (
          <RoleForm
            key={target?.id ?? 'new'}
            role={target ?? null}
            catalogue={catalogue.data}
            onDone={onClose}
          />
        )}
      </DialogContent>
    </Dialog>
  )
}
