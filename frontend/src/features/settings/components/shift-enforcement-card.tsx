import { copy } from '@/copy/en'
import { SettingSwitch } from './setting-switch'
import { SettingsSectionScreen } from './settings-section-screen'

const labels = copy.workspaceSettings.shiftsForm

/** The `shifts.enforce` switch, shown on Settings → Shifts to whoever has `settings.manage`. */
export function ShiftEnforcementCard() {
  return (
    <SettingsSectionScreen section="shifts" title={labels.title} hideWhenForbidden>
      {({ tenantId, values }) => (
        <SettingSwitch
          id="settings-shifts-enforce"
          label={labels.enforce}
          description={labels.enforceHelp}
          tenantId={tenantId}
          section="shifts"
          checked={values.enforce}
          toInput={(enforce) => ({ enforce })}
        />
      )}
    </SettingsSectionScreen>
  )
}
