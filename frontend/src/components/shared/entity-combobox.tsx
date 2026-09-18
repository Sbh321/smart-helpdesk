import {
  Combobox,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from '@/components/ui/combobox'
import { copy } from '@/copy/en'
import { FormField } from './form-field'

export interface EntityOption {
  value: string
  label: string
  /** A second line, for example the contact's email. */
  detail?: string
}

export interface EntityComboboxProps {
  id: string
  label: string
  value: EntityOption | null
  onChange: (value: EntityOption | null) => void
  /** Matches for the current query, as the server returned them (no client-side filtering). */
  options: readonly EntityOption[]
  /** Called with the typed text; the caller debounces it into a search request. */
  onQueryChange: (query: string) => void
  isLoading?: boolean
  placeholder?: string
  description?: string
  errors?: readonly string[]
}

/**
 * A searchable single picker for a record — contact, organisation — whose matches come from the API
 * (`?search=`, `/typeahead?q=`). Base UI `Combobox` with the input as the form control, so the label,
 * description and errors attach to the input (docs/06-design-system/components.md §Primitive inventory).
 */
export function EntityCombobox({
  id,
  label,
  value,
  onChange,
  options,
  onQueryChange,
  isLoading = false,
  placeholder,
  description,
  errors,
}: EntityComboboxProps) {
  const items =
    value && !options.some((option) => option.value === value.value) ? [value, ...options] : options

  return (
    <FormField
      id={id}
      label={label}
      {...(description ? { description } : {})}
      {...(errors ? { errors } : {})}
    >
      {(control) => (
        <Combobox
          items={items as EntityOption[]}
          value={value}
          onValueChange={(next) => onChange((next as EntityOption | null) ?? null)}
          filter={null}
          autoHighlight
          isItemEqualToValue={(item: EntityOption, current: EntityOption) => item.value === current.value}
          itemToStringLabel={(item: EntityOption) => item.label}
          itemToStringValue={(item: EntityOption) => item.value}
          onInputValueChange={(text) => onQueryChange(text)}
        >
          <ComboboxInput
            {...control}
            placeholder={placeholder}
            showClear={value !== null}
            triggerLabel={label}
            clearLabel={`${copy.combobox.clear} ${label.toLowerCase()}`}
            className="w-full"
          />
          <ComboboxContent>
            <ComboboxEmpty>{isLoading ? copy.combobox.loading : copy.combobox.noResults}</ComboboxEmpty>
            <ComboboxList>
              {(item: EntityOption) => (
                <ComboboxItem key={item.value} value={item}>
                  <span className="flex min-w-0 flex-col">
                    <span className="truncate">{item.label}</span>
                    {item.detail ? (
                      <span className="truncate text-xs text-muted-foreground">{item.detail}</span>
                    ) : null}
                  </span>
                </ComboboxItem>
              )}
            </ComboboxList>
          </ComboboxContent>
        </Combobox>
      )}
    </FormField>
  )
}
