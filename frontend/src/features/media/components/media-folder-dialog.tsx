import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { toast } from 'sonner'
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
import { copy, fill } from '@/copy/en'
import { queryKeys } from '@/lib/api/query-keys'
import { useSession } from '@/lib/auth'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { createFolder, type MediaFolder, updateFolder } from '../api/media-queries'
import { type FolderFormValues, folderFormSchema } from '../schemas'

/** What the dialog does: rename `folder`, or create a folder inside `parent` (the root when `null`). */
export type FolderDialogTarget =
  | { mode: 'create'; parent: MediaFolder | null }
  | { mode: 'rename'; folder: MediaFolder }

function FolderForm({ target, onDone }: { target: FolderDialogTarget; onDone: () => void }) {
  const tenantId = useSession().session?.tenant.id ?? ''
  const client = useQueryClient()
  const server = useServerErrors()
  const defaultValues: FolderFormValues = { name: target.mode === 'rename' ? target.folder.name : '' }
  const form = useForm({
    defaultValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: folderFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      try {
        const name = value.name.trim()
        await (target.mode === 'rename'
          ? updateFolder(target.folder.id, { name })
          : createFolder(name, target.parent?.id ?? null))
        await client.invalidateQueries({ queryKey: queryKeys.media.all(tenantId) })
        toast.success(target.mode === 'rename' ? copy.media.folderRenamed : copy.media.folderCreated)
        onDone()
      } catch (error) {
        server.capture(error)
      }
    },
  })

  return (
    <form
      noValidate
      className="flex flex-col gap-4"
      onSubmit={(event) => {
        event.preventDefault()
        event.stopPropagation()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={copy.media.failed} error={server.failure} /> : null}
      <form.Field name="name">
        {(field) => (
          <TextField
            id="media-folder-name"
            label={copy.media.newFolder}
            autoComplete="off"
            value={field.state.value}
            onValueChange={field.handleChange}
            onBlur={field.handleBlur}
            errors={mergeMessages(field.state.meta.errors, [
              ...(server.fields.name ?? []),
              ...(server.fields.parent_id ?? []),
            ])}
          />
        )}
      </form.Field>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <DialogFooter>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={onDone}>
              {copy.media.cancel}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting
                ? copy.media.saving
                : target.mode === 'rename'
                  ? copy.media.save
                  : copy.media.createFolder}
            </Button>
          </DialogFooter>
        )}
      </form.Subscribe>
    </form>
  )
}

export function MediaFolderDialog({
  target,
  onClose,
}: {
  target: FolderDialogTarget | null
  onClose: () => void
}) {
  return (
    <Dialog
      open={target !== null}
      onOpenChange={(open) => {
        if (!open) onClose()
      }}
    >
      <DialogContent>
        <DialogHeader>
          <DialogTitle>
            {target?.mode === 'rename' ? copy.media.renameFolderTitle : copy.media.createFolder}
          </DialogTitle>
          <DialogDescription>
            {target?.mode === 'rename'
              ? fill(copy.media.renameFolderBody, { name: target.folder.name })
              : target?.parent
                ? fill(copy.media.createFolderIn, { name: target.parent.name })
                : copy.media.createFolderAtRoot}
          </DialogDescription>
        </DialogHeader>
        {target ? <FolderForm target={target} onDone={onClose} /> : null}
      </DialogContent>
    </Dialog>
  )
}
