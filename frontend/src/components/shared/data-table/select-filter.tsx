import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import type { FilterOption } from './multi-select-filter'

export interface SelectFilterProps {
  /** Accessible name of the trigger; also shown before the value. */
  label: string
  options: readonly FilterOption[]
  /** The selected option value; `undefined` shows the default option. */
  value: string | undefined
  /** The option in effect when the filter is absent from the URL. */
  defaultValue: string
  onChange: (value: string | undefined) => void
}

/**
 * One-of-several filter (for example "Hide / Only / Include archived"). Choosing the default option
 * removes the filter from the URL, so the default view keeps a clean address.
 */
export function SelectFilter({ label, options, value, defaultValue, onChange }: SelectFilterProps) {
  const current = value ?? defaultValue
  return (
    <Select
      items={options as FilterOption[]}
      value={current}
      onValueChange={(next) => {
        if (typeof next !== 'string' || next === current) return
        onChange(next === defaultValue ? undefined : next)
      }}
    >
      <SelectTrigger size="sm" aria-label={label} className="border-dashed">
        <span className="text-muted-foreground">{label}:</span>
        <SelectValue />
      </SelectTrigger>
      <SelectContent>
        {options.map((option) => (
          <SelectItem key={option.value} value={option.value}>
            {option.label}
          </SelectItem>
        ))}
      </SelectContent>
    </Select>
  )
}
