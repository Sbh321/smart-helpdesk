import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { toast } from '@/components/ui/sonner'
import { Switch } from '@/components/ui/switch'
import { copy } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { type SettingsSectionKey, saveSettings } from '../api/settings-queries'

/**
 * One boolean setting that saves as soon as it is switched. The switch shows the new position while the
 * PATCH runs and falls back to the stored value, with a message, when it fails.
 */
export function SettingSwitch({
  id,
  label,
  description,
  tenantId,
  section,
  checked,
  toInput,
}: {
  id: string
  label: string
  description: string
  tenantId: string
  section: SettingsSectionKey
  /** The stored value, from the section query. */
  checked: boolean
  toInput: (next: boolean) => object
}) {
  const client = useQueryClient()
  const [pending, setPending] = useState<boolean | null>(null)
  const [error, setError] = useState<string | null>(null)

  const change = async (next: boolean) => {
    setPending(next)
    setError(null)
    try {
      await saveSettings(client, tenantId, section, toInput(next))
      toast.success(copy.workspaceSettings.saved)
    } catch (cause) {
      const detail = isApiError(cause) ? (cause.detail ?? cause.title) : null
      setError(
        detail ? `${copy.workspaceSettings.switchFailed}: ${detail}` : copy.workspaceSettings.switchFailed,
      )
    } finally {
      setPending(null)
    }
  }

  return (
    <div className="flex max-w-2xl flex-col gap-2 rounded-lg border border-border p-4">
      <div className="flex items-start justify-between gap-4">
        <div className="space-y-1">
          <p id={`${id}-label`} className="text-sm font-medium">
            {label}
          </p>
          <p id={`${id}-description`} className="text-sm text-muted-foreground">
            {description}
          </p>
        </div>
        <Switch
          id={id}
          aria-labelledby={`${id}-label`}
          aria-describedby={`${id}-description`}
          checked={pending ?? checked}
          disabled={pending !== null}
          onCheckedChange={(next) => void change(next)}
        />
      </div>
      {error ? (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  )
}
