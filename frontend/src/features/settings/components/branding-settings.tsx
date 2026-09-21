import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { toast } from 'sonner'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldLegend, FieldSet } from '@/components/ui/field'
import { copy, fill } from '@/copy/en'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { brandContrast, HEX_COLOR_PATTERN, useTheme } from '@/lib/theme'
import { cn } from '@/lib/utils'
import { saveSettings } from '../api/settings-queries'
import { type BrandingFormValues, brandingFormSchema } from '../schemas'
import { LogoField } from './logo-field'
import { type LoadedSection, SettingsSectionScreen } from './settings-section-screen'

const text = copy.workspaceSettings
const labels = text.brandingForm

/** What the native colour input shows while the workspace has no colour: close to `--blue-600`. */
const DEFAULT_SWATCH = '#2563eb'

function ContrastFeedback({ value }: { value: string }) {
  const contrast = brandContrast(value.trim())
  if (!contrast) {
    return <p role="status" className="text-sm text-muted-foreground" />
  }
  return (
    <div role="status" className="space-y-1 text-sm">
      <p className={cn(contrast.passes ? 'text-muted-foreground' : 'font-medium text-destructive')}>
        {contrast.passes
          ? fill(labels.contrastPass, {
              ratio: contrast.ratio.toFixed(1),
              foreground: labels.foregrounds[contrast.foreground],
              dark: contrast.darkRatio.toFixed(1),
            })
          : fill(labels.contrastFail, { ratio: contrast.ratio.toFixed(2) })}
      </p>
      {contrast.passes && contrast.onWhite < 3 ? (
        <p className="text-muted-foreground">
          {fill(labels.lowOnWhite, { ratio: contrast.onWhite.toFixed(1) })}
        </p>
      ) : null}
    </div>
  )
}

function BrandingForm({ tenantId, values }: LoadedSection<'branding'>) {
  const client = useQueryClient()
  const server = useServerErrors()
  const { previewTenantPrimary } = useTheme()
  const [saved, setSaved] = useState(values.primary ?? '')
  const [uploads, setUploads] = useState(0)

  const form = useForm({
    defaultValues: {
      primary: values.primary ?? '',
      logo_media_id: values.logo_media_id,
      logo_dark_media_id: values.logo_dark_media_id,
    } satisfies BrandingFormValues as BrandingFormValues,
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: brandingFormSchema },
    onSubmit: async ({ value }) => {
      server.reset()
      const primary = value.primary.trim().toLowerCase()
      try {
        await saveSettings(client, tenantId, 'branding', {
          primary: primary === '' ? null : primary,
          logo_media_id: value.logo_media_id,
          logo_dark_media_id: value.logo_dark_media_id,
        })
        setSaved(primary)
        toast.success(text.saved)
      } catch (error) {
        server.capture(error)
      }
    },
  })

  // Live preview: the colour reaches the tokens at once; leaving the page ends the preview, so an unsaved
  // colour is reverted to the workspace's saved one.
  useEffect(() => () => previewTenantPrimary(undefined), [previewTenantPrimary])
  const preview = (raw: string) => {
    const value = raw.trim()
    if (value === '') previewTenantPrimary(null)
    else if (HEX_COLOR_PATTERN.test(value)) previewTenantPrimary(value)
  }

  return (
    <form
      noValidate
      aria-label={text.branding}
      className="flex max-w-2xl flex-col gap-6"
      onSubmit={(event) => {
        event.preventDefault()
        void form.handleSubmit()
      }}
    >
      {server.failure ? <FormErrorBanner title={text.failed} error={server.failure} /> : null}
      <FieldSet>
        <FieldLegend>{labels.colourLegend}</FieldLegend>
        <form.Field name="primary">
          {(field) => {
            const value = field.state.value
            const change = (next: string) => {
              field.handleChange(next)
              preview(next)
            }
            return (
              <div className="flex flex-col gap-3">
                <div className="flex flex-wrap items-start gap-3">
                  <input
                    type="color"
                    aria-label={labels.picker}
                    className="mt-6 h-9 w-12 cursor-pointer rounded-md border border-input bg-transparent p-1"
                    value={HEX_COLOR_PATTERN.test(value.trim()) ? value.trim().toLowerCase() : DEFAULT_SWATCH}
                    onChange={(event) => change(event.target.value)}
                  />
                  <div className="min-w-48 flex-1">
                    <TextField
                      id="settings-branding-primary"
                      label={labels.hex}
                      description={labels.hexHelp}
                      autoComplete="off"
                      spellCheck={false}
                      placeholder="#1d4ed8"
                      value={value}
                      onValueChange={change}
                      onBlur={field.handleBlur}
                      errors={mergeMessages(field.state.meta.errors, server.exact.primary)}
                    />
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    className="mt-6"
                    disabled={value === ''}
                    onClick={() => change('')}
                  >
                    {labels.resetColour}
                  </Button>
                </div>
                <ContrastFeedback value={value} />
                {value.trim().toLowerCase() !== saved ? (
                  <p className="text-sm text-muted-foreground">{labels.unsaved}</p>
                ) : null}
              </div>
            )
          }}
        </form.Field>
      </FieldSet>
      <FieldSet>
        <FieldLegend>{labels.previewLegend}</FieldLegend>
        <div className="flex flex-wrap items-center gap-4 rounded-lg border border-border p-4">
          <span className="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground">
            {labels.previewButton}
          </span>
          <span className="text-sm text-primary underline underline-offset-2">{labels.previewLink}</span>
          <span className="size-6 rounded-full border-2 border-ring" aria-hidden="true" />
        </div>
      </FieldSet>
      <form.Field name="logo_media_id">
        {(field) => (
          <LogoField
            id="settings-branding-logo"
            label={labels.logoLight}
            description={labels.logoLightHelp}
            theme="light"
            mediaId={field.state.value}
            onChange={field.handleChange}
            onBusyChange={(busy) => setUploads((count) => count + (busy ? 1 : -1))}
            errors={server.exact.logo_media_id ?? []}
          />
        )}
      </form.Field>
      <form.Field name="logo_dark_media_id">
        {(field) => (
          <LogoField
            id="settings-branding-logo-dark"
            label={labels.logoDark}
            description={labels.logoDarkHelp}
            theme="dark"
            mediaId={field.state.value}
            onChange={field.handleChange}
            onBusyChange={(busy) => setUploads((count) => count + (busy ? 1 : -1))}
            errors={server.exact.logo_dark_media_id ?? []}
          />
        )}
      </form.Field>
      <form.Subscribe selector={(state) => state.isSubmitting}>
        {(isSubmitting) => (
          <div>
            <Button type="submit" disabled={isSubmitting || uploads > 0}>
              {isSubmitting ? text.saving : text.save}
            </Button>
          </div>
        )}
      </form.Subscribe>
    </form>
  )
}

export function BrandingSettings() {
  return (
    <SettingsSectionScreen section="branding" title={text.branding} intro={labels.intro}>
      {(loaded) => <BrandingForm {...loaded} />}
    </SettingsSectionScreen>
  )
}
