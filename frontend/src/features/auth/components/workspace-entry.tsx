import { revalidateLogic, useForm } from '@tanstack/react-form'
import { Link, useNavigate } from '@tanstack/react-router'
import { ArrowRightIcon, XIcon } from 'lucide-react'
import { useState } from 'react'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'
import {
  forgetWorkspace,
  normaliseWorkspaceInput,
  type RecentWorkspace,
  readRecentWorkspaces,
} from '@/lib/auth'
import { useRuntimeConfig } from '@/lib/config'
import { initials } from '@/lib/format/initials'
import { messagesOf } from '@/lib/forms/messages'
import { workspaceEntrySchema } from '../schemas'

/**
 * The workspace step before sign-in (M5-03). Workspaces signed in to on this device come first, one
 * click each; below them the field accepts a slug, a name or a pasted address and previews where the
 * visitor will land; and anyone who does not know their workspace can have it emailed (M5-02).
 */
export function WorkspaceEntry() {
  const navigate = useNavigate()
  const { platformDomain } = useRuntimeConfig()
  const [recent, setRecent] = useState<RecentWorkspace[]>(readRecentWorkspaces)

  const form = useForm({
    defaultValues: { workspace: '' },
    // Checked on submit, then again as the field changes (the app-wide form timing, M4-13).
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: workspaceEntrySchema(platformDomain) },
    onSubmit: async ({ value }) => {
      await navigate({
        to: '/$workspace/login',
        params: { workspace: normaliseWorkspaceInput(value.workspace, platformDomain) },
        search: {},
      })
    },
  })

  const forget = (item: RecentWorkspace) => {
    setRecent(forgetWorkspace(item.slug))
    toast.success(fill(copy.workspaceEntry.forgotten, { name: item.name }))
  }

  return (
    <div className="flex flex-col gap-6">
      {recent.length > 0 ? (
        <section aria-labelledby="recent-workspaces-heading" className="flex flex-col gap-3">
          <h2
            id="recent-workspaces-heading"
            className="font-medium text-muted-foreground text-xs uppercase tracking-wide"
          >
            {copy.workspaceEntry.recentHeading}
          </h2>
          <ul className="flex flex-col gap-2">
            {recent.map((item) => (
              <li key={item.slug} className="group relative">
                <Link
                  to="/$workspace/login"
                  params={{ workspace: item.slug }}
                  search={{}}
                  aria-label={fill(copy.workspaceEntry.recentOpen, { name: item.name })}
                  className="flex items-center gap-3 rounded-card border border-border bg-surface py-2.5 ps-3 pe-12 shadow-1 transition-colors hover:border-primary/40 hover:bg-muted focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-offset-2"
                >
                  <span
                    aria-hidden="true"
                    className="grid size-9 shrink-0 place-items-center rounded-control bg-primary/15 font-semibold text-foreground text-sm"
                  >
                    {initials(item.name)}
                  </span>
                  <span className="flex min-w-0 flex-1 flex-col">
                    <span className="truncate font-medium text-sm">{item.name}</span>
                    <span className="truncate text-muted-foreground text-xs">
                      app.{platformDomain}/{item.slug}
                    </span>
                  </span>
                  <ArrowRightIcon aria-hidden="true" className="size-4 text-muted-foreground" />
                </Link>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-xs"
                  aria-label={fill(copy.workspaceEntry.forget, { name: item.name })}
                  onClick={() => forget(item)}
                  className="absolute end-2 top-1/2 -translate-y-1/2 text-muted-foreground"
                >
                  <XIcon aria-hidden="true" />
                </Button>
              </li>
            ))}
          </ul>
          <div className="flex items-center gap-3 text-muted-foreground text-xs" aria-hidden="true">
            <span className="h-px flex-1 bg-border" />
            {copy.workspaceEntry.or}
            <span className="h-px flex-1 bg-border" />
          </div>
        </section>
      ) : null}

      <form
        noValidate
        onSubmit={(event) => {
          event.preventDefault()
          void form.handleSubmit()
        }}
        className="flex flex-col gap-5"
      >
        <form.Field name="workspace">
          {(field) => {
            const slug = normaliseWorkspaceInput(field.state.value, platformDomain)
            return (
              <TextField
                id="workspace"
                label={recent.length > 0 ? copy.workspaceEntry.otherWorkspace : copy.workspaceEntry.label}
                description={
                  slug.length > 0
                    ? fill(copy.workspaceEntry.preview, { address: `app.${platformDomain}/${slug}` })
                    : copy.workspaceEntry.description
                }
                autoComplete="organization"
                autoCapitalize="none"
                spellCheck={false}
                // With no remembered workspace the field is the page's only task, so the cursor starts here.
                autoFocus={recent.length === 0}
                value={field.state.value}
                onValueChange={field.handleChange}
                onBlur={field.handleBlur}
                errors={messagesOf(field.state.meta.errors)}
              />
            )
          }}
        </form.Field>
        <Button type="submit" size="lg">
          {copy.workspaceEntry.submit}
          <ArrowRightIcon aria-hidden="true" />
        </Button>
      </form>

      <p className="text-center text-muted-foreground text-sm">
        {copy.workspaceEntry.findPrompt}{' '}
        <Link
          to="/"
          search={{ find: true }}
          className="font-medium text-primary underline-offset-4 hover:underline"
        >
          {copy.workspaceEntry.findLink}
        </Link>
      </p>
    </div>
  )
}
