import { Link, useParams } from '@tanstack/react-router'
import { copy } from '@/copy/en'
import { duplicatesFormSchema } from '../schemas'
import { type NumberSetting, NumberSettingsForm } from './number-settings-form'
import { SettingSwitch } from './setting-switch'
import { SettingsSectionScreen } from './settings-section-screen'

const text = copy.workspaceSettings
const labels = text.automationForm

const DUPLICATE_SETTINGS = [
  {
    name: 'threshold',
    label: labels.threshold,
    description: labels.thresholdHelp,
    errorKey: 'baseline.threshold',
    min: 0,
    max: 1,
    step: 0.01,
  },
  {
    name: 'candidate_limit',
    label: labels.candidateLimit,
    description: labels.candidateLimitHelp,
    errorKey: 'baseline.candidate_limit',
    min: 1,
    max: 200,
    step: 1,
  },
  {
    name: 'window_days',
    label: labels.windowDays,
    description: labels.windowDaysHelp,
    errorKey: 'baseline.window_days',
    min: 1,
    max: 365,
    step: 1,
  },
  {
    name: 'max_suggestions',
    label: labels.maxSuggestions,
    description: labels.maxSuggestionsHelp,
    errorKey: 'baseline.max_suggestions',
    min: 1,
    max: 20,
    step: 1,
  },
] as const satisfies readonly NumberSetting<string>[]

/** Settings → Automation: automatic assignment and duplicate detection; priority has a page of its own. */
export function AutomationSettings() {
  const { workspace } = useParams({ strict: false })
  return (
    <div className="space-y-8">
      <SettingsSectionScreen section="automation.assignment" title={text.automation} intro={labels.intro}>
        {({ tenantId, values }) => (
          <>
            <SettingSwitch
              id="settings-assignment-enabled"
              label={labels.assignment}
              description={labels.assignmentHelp}
              tenantId={tenantId}
              section="automation.assignment"
              checked={values.enabled}
              toInput={(enabled) => ({ enabled })}
            />
            {workspace ? (
              <p className="text-sm">
                <Link
                  to="/$workspace/settings/priority"
                  params={{ workspace }}
                  className="text-primary underline underline-offset-2"
                >
                  {labels.priorityLink}
                </Link>
              </p>
            ) : null}
          </>
        )}
      </SettingsSectionScreen>
      <SettingsSectionScreen section="automation.duplicates" title={labels.duplicates} hideWhenForbidden>
        {({ tenantId, values, defaults }) => (
          <NumberSettingsForm
            label={labels.duplicates}
            idPrefix="settings-duplicates"
            tenantId={tenantId}
            section="automation.duplicates"
            settings={DUPLICATE_SETTINGS}
            schema={duplicatesFormSchema}
            values={values.baseline}
            defaults={defaults.baseline}
            toInput={(baseline) => ({ baseline })}
          />
        )}
      </SettingsSectionScreen>
    </div>
  )
}
