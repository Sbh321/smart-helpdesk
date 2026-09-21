import { copy } from '@/copy/en'
import { ticketsFormSchema } from '../schemas'
import { type NumberSetting, NumberSettingsForm } from './number-settings-form'
import { SettingsSectionScreen } from './settings-section-screen'

const text = copy.workspaceSettings
const labels = text.ticketsForm

const TICKET_SETTINGS = [
  {
    name: 'auto_close_days',
    label: labels.autoCloseDays,
    description: labels.autoCloseDaysHelp,
    errorKey: 'auto_close_days',
    min: 1,
    max: 90,
    step: 1,
  },
  {
    name: 'reopen_window_days',
    label: labels.reopenWindowDays,
    description: labels.reopenWindowDaysHelp,
    errorKey: 'reopen_window_days',
    min: 0,
    max: 365,
    step: 1,
  },
] as const satisfies readonly NumberSetting<string>[]

/** Settings → Tickets: auto-close and the reopen window. */
export function TicketSettings() {
  return (
    <SettingsSectionScreen section="tickets" title={text.tickets} intro={labels.intro}>
      {({ tenantId, values, defaults }) => (
        <NumberSettingsForm
          label={text.tickets}
          idPrefix="settings-tickets"
          tenantId={tenantId}
          section="tickets"
          settings={TICKET_SETTINGS}
          schema={ticketsFormSchema}
          values={values}
          defaults={defaults}
          toInput={(numbers) => numbers}
        />
      )}
    </SettingsSectionScreen>
  )
}
