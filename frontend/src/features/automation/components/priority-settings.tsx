import { revalidateLogic, useForm } from '@tanstack/react-form'
import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from 'sonner'
import { FormErrorBanner } from '@/components/shared/form-error-banner'
import { TextField } from '@/components/shared/text-field'
import { Button } from '@/components/ui/button'
import { FieldLegend, FieldSet } from '@/components/ui/field'
import { TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { copy, fill } from '@/copy/en'
import { type LoadedSection, SettingsSectionScreen, saveSettings } from '@/features/settings'
import { mergeMessages } from '@/lib/forms/messages'
import { useServerErrors } from '@/lib/forms/use-server-errors'
import { type PriorityPreviewInput, previewPriority } from '../api/priority-preview'
import {
  PRIORITY_THRESHOLDS,
  PRIORITY_WEIGHTS,
  type PrioritySettingsFormValues,
  prioritySettingsFormSchema,
} from '../schemas'

/** The worked examples of docs/05-algorithms/priority-scoring.md. */
const EXAMPLES: PriorityPreviewInput['samples'] = [
  { impact: 4, urgency: 4, tier: 'enterprise', hours_waited: 0 },
  { impact: 3, urgency: 2, tier: 'standard', hours_waited: 0 },
  { impact: 2, urgency: 3, tier: 'premium', hours_waited: 24 },
  { impact: 2, urgency: 3, tier: 'premium', hours_waited: 72 },
  { impact: 1, urgency: 1, tier: 'standard', hours_waited: 0 },
]

type Baseline = LoadedSection<'automation.priority'>['values']['baseline']
type Preview = Awaited<ReturnType<typeof previewPriority>>
type SubmitMeta = { action: 'preview' | 'save' }

function toFormValues(baseline: Baseline): PrioritySettingsFormValues {
  return {
    weights: {
      impact: String(baseline.weights.impact),
      urgency: String(baseline.weights.urgency),
      tier: String(baseline.weights.tier),
      age: String(baseline.weights.age),
    },
    thresholds: {
      P1: String(baseline.thresholds.P1),
      P2: String(baseline.thresholds.P2),
      P3: String(baseline.thresholds.P3),
    },
    age_full_hours: String(baseline.age_full_hours),
  }
}

function toBaseline(values: PrioritySettingsFormValues): Baseline {
  return {
    weights: {
      impact: Number(values.weights.impact),
      urgency: Number(values.weights.urgency),
      tier: Number(values.weights.tier),
      age: Number(values.weights.age),
    },
    thresholds: {
      P1: Number(values.thresholds.P1),
      P2: Number(values.thresholds.P2),
      P3: Number(values.thresholds.P3),
    },
    age_full_hours: Number(values.age_full_hours),
  }
}

function sampleKey(sample: (typeof EXAMPLES)[number]): string {
  return `${sample.impact}-${sample.urgency}-${sample.tier}-${sample.hours_waited}`
}

/** Settings → Priority automation: the `automation.priority` section, with a preview before saving. */
export function PrioritySettings() {
  return (
    <SettingsSectionScreen
      section="automation.priority"
      title={copy.priority.settingsTitle}
      intro={copy.priority.intro}
    >
      {(loaded) => <PriorityForm {...loaded} />}
    </SettingsSectionScreen>
  )
}

function PriorityForm({ tenantId, values, defaults }: LoadedSection<'automation.priority'>) {
  const client = useQueryClient()
  const server = useServerErrors()
  const [preview, setPreview] = useState<Preview | null>(null)
  const [failedAction, setFailedAction] = useState<SubmitMeta['action']>('preview')
  const form = useForm({
    defaultValues: toFormValues(values.baseline),
    validationLogic: revalidateLogic({ mode: 'submit', modeAfterSubmission: 'change' }),
    validators: { onDynamic: prioritySettingsFormSchema },
    onSubmitMeta: { action: 'preview' } as SubmitMeta,
    onSubmit: async ({ value, meta }) => {
      server.reset()
      try {
        if (meta.action === 'save') {
          await saveSettings(client, tenantId, 'automation.priority', { baseline: toBaseline(value) })
          toast.success(copy.priority.saved)
        } else {
          setPreview(await previewPriority({ ...toBaseline(value), samples: EXAMPLES }))
        }
      } catch (error) {
        if (meta.action === 'preview') setPreview(null)
        setFailedAction(meta.action)
        server.capture(error)
      }
    },
  })
  /** The preview endpoint names `weights`; the settings section names the same value `baseline.weights`. */
  const serverMessages = (name: string) => [
    ...(server.exact[name] ?? []),
    ...(server.exact[`baseline.${name}`] ?? []),
  ]

  return (
    <>
      <form
        noValidate
        aria-label={copy.priority.settingsTitle}
        className="flex max-w-2xl flex-col gap-5"
        onSubmit={(event) => {
          event.preventDefault()
          void form.handleSubmit({ action: 'preview' })
        }}
      >
        {server.failure ? (
          <FormErrorBanner
            title={failedAction === 'save' ? copy.priority.saveFailed : copy.priority.previewFailed}
            error={server.failure}
          />
        ) : null}
        {serverMessages('baseline').map((message) => (
          <p key={message} role="alert" className="text-sm text-destructive">
            {message}
          </p>
        ))}
        <FieldSet>
          <FieldLegend>{copy.priority.weights}</FieldLegend>
          <form.Field name="weights">
            {(field) =>
              mergeMessages(field.state.meta.errors, serverMessages('weights')).map((message) => (
                <p key={message} role="alert" className="text-sm text-destructive">
                  {message}
                </p>
              ))
            }
          </form.Field>
          <div className="grid gap-3 sm:grid-cols-2">
            {PRIORITY_WEIGHTS.map((name) => (
              <form.Field key={name} name={`weights.${name}`}>
                {(field) => (
                  <TextField
                    id={`priority-weight-${name}`}
                    label={copy.priority.weightNames[name]}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    max={1}
                    step={0.01}
                    value={field.state.value}
                    onValueChange={field.handleChange}
                    onBlur={field.handleBlur}
                    errors={mergeMessages(field.state.meta.errors, serverMessages(`weights.${name}`))}
                  />
                )}
              </form.Field>
            ))}
          </div>
        </FieldSet>
        <FieldSet>
          <FieldLegend>{copy.priority.thresholds}</FieldLegend>
          {serverMessages('thresholds').map((message) => (
            <p key={message} role="alert" className="text-sm text-destructive">
              {message}
            </p>
          ))}
          <div className="grid gap-3 sm:grid-cols-3">
            {PRIORITY_THRESHOLDS.map((name) => (
              <form.Field key={name} name={`thresholds.${name}`}>
                {(field) => (
                  <TextField
                    id={`priority-threshold-${name}`}
                    label={name}
                    type="number"
                    inputMode="decimal"
                    min={0}
                    max={100}
                    step={0.1}
                    value={field.state.value}
                    onValueChange={field.handleChange}
                    onBlur={field.handleBlur}
                    errors={mergeMessages(field.state.meta.errors, serverMessages(`thresholds.${name}`))}
                  />
                )}
              </form.Field>
            ))}
          </div>
        </FieldSet>
        <div className="max-w-xs">
          <form.Field name="age_full_hours">
            {(field) => (
              <TextField
                id="priority-age-hours"
                label={copy.priority.ageFullHours}
                type="number"
                inputMode="decimal"
                min={1}
                value={field.state.value}
                onValueChange={field.handleChange}
                onBlur={field.handleBlur}
                errors={mergeMessages(field.state.meta.errors, serverMessages('age_full_hours'))}
              />
            )}
          </form.Field>
        </div>
        <form.Subscribe selector={(state) => state.isSubmitting}>
          {(isSubmitting) => (
            <div className="flex flex-wrap gap-2">
              <Button type="submit" variant="outline" disabled={isSubmitting}>
                {isSubmitting ? copy.priority.previewing : copy.priority.preview}
              </Button>
              <Button
                type="button"
                disabled={isSubmitting}
                onClick={() => void form.handleSubmit({ action: 'save' })}
              >
                {copy.priority.saveSettings}
              </Button>
              <Button
                type="button"
                variant="ghost"
                disabled={isSubmitting}
                onClick={() => {
                  const next = toFormValues(defaults.baseline)
                  form.setFieldValue('weights', next.weights)
                  form.setFieldValue('thresholds', next.thresholds)
                  form.setFieldValue('age_full_hours', next.age_full_hours)
                }}
              >
                {copy.priority.resetDefaults}
              </Button>
            </div>
          )}
        </form.Subscribe>
      </form>
      {preview ? (
        // Three short columns that wrap: no scroll container, so nothing scrollable needs a tab stop.
        <table className="w-full max-w-2xl caption-bottom text-sm">
          <caption className="sr-only">{copy.priority.previewCaption}</caption>
          <TableHeader>
            <TableRow>
              <TableHead>{copy.priority.example}</TableHead>
              <TableHead>{copy.priority.score}</TableHead>
              <TableHead>{copy.priority.level}</TableHead>
            </TableRow>
          </TableHeader>
          <TableBody>
            {EXAMPLES.flatMap((sample, position) => {
              const row = preview[position]
              if (!row) return []
              return (
                <TableRow key={sampleKey(sample)}>
                  <TableCell className="whitespace-normal">
                    {fill(copy.priority.exampleLine, {
                      impact: sample.impact,
                      urgency: sample.urgency,
                      tier: copy.priority.tiers[sample.tier],
                      hours: sample.hours_waited,
                    })}
                  </TableCell>
                  <TableCell className="tabular-nums">{row.score.toFixed(1)}</TableCell>
                  <TableCell>{row.level}</TableCell>
                </TableRow>
              )
            })}
          </TableBody>
        </table>
      ) : null}
    </>
  )
}
