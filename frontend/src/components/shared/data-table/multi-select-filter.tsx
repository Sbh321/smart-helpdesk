import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Combobox,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
  ComboboxTrigger,
} from '@/components/ui/combobox'
import { copy, fill } from '@/copy/en'

export interface FilterOption {
  value: string
  label: string
}

export interface MultiSelectFilterProps {
  /** Names the trigger and the search field inside the popup. */
  label: string
  options: FilterOption[]
  /** Selected option values (from the URL). */
  value: string[]
  onChange: (value: string[]) => void
  /** While options are loading the list says so instead of "No matches". */
  isLoading?: boolean
}

/**
 * A multi-select filter: a trigger button with the count of selected values, and a searchable list in
 * the popup (Base UI `Combobox` with the input inside the popup; docs/06-design-system/components.md
 * says every searchable picker is a `combobox`). Selected values that are not among the options — an
 * id from a shared URL beyond the first page of options — stay listed so they can be removed.
 */
export function MultiSelectFilter({
  label,
  options,
  value,
  onChange,
  isLoading = false,
}: MultiSelectFilterProps) {
  const known = new Set(options.map((option) => option.value))
  const items = [
    ...options,
    ...value
      .filter((selected) => !known.has(selected))
      .map((selected) => ({ value: selected, label: selected })),
  ]
  const selected = items.filter((item) => value.includes(item.value))

  return (
    <Combobox
      multiple
      items={items}
      value={selected}
      onValueChange={(next) => onChange((next as FilterOption[]).map((item) => item.value))}
      isItemEqualToValue={(item: FilterOption, current: FilterOption) => item.value === current.value}
      itemToStringLabel={(item: FilterOption) => item.label}
      itemToStringValue={(item: FilterOption) => item.value}
      onInputValueChange={(_text, details) => {
        // Keep the typed query after picking an item, so several matches can be picked in a row.
        if (details.isItemPress) details.cancel()
      }}
    >
      <ComboboxTrigger
        render={<Button type="button" variant="outline" size="sm" className="border-dashed" />}
        aria-label={
          value.length > 0 ? `${label}, ${fill(copy.filters.selectedCount, { count: value.length })}` : label
        }
      >
        {label}
        {value.length > 0 ? (
          <Badge variant="secondary" className="rounded-sm px-1 tabular-nums">
            {value.length}
          </Badge>
        ) : null}
      </ComboboxTrigger>
      <ComboboxContent className="w-64" aria-label={label}>
        <ComboboxInput
          showTrigger={false}
          placeholder={fill(copy.filters.searchOptions, { label: label.toLowerCase() })}
          aria-label={fill(copy.filters.searchOptions, { label: label.toLowerCase() })}
        />
        <ComboboxEmpty>{isLoading ? copy.filters.loadingOptions : copy.filters.noOptions}</ComboboxEmpty>
        <ComboboxList>
          {(item: FilterOption) => (
            <ComboboxItem key={item.value} value={item}>
              {item.label}
            </ComboboxItem>
          )}
        </ComboboxList>
      </ComboboxContent>
    </Combobox>
  )
}
