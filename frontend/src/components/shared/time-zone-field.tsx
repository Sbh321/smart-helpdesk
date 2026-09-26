import {
  Combobox,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxInput,
  ComboboxItem,
  ComboboxList,
} from '@/components/ui/combobox'
import { copy } from '@/copy/en'
import { timeZoneNames, timeZoneOffset } from '@/lib/datetime/time-zones'
import { FormField } from './form-field'

export interface TimeZoneFieldProps {
  id: string
  label: string
  /** An IANA zone name from `timeZoneNames()`, or an empty string for none yet. */
  value: string
  onValueChange: (value: string) => void
  onBlur?: () => void
  description?: string
  errors?: readonly string[]
}

/** `Asia/Ho_Chi_Minh` reads as `Asia/Ho Chi Minh`; the value keeps the IANA spelling. */
const readable = (zone: string): string => zone.replaceAll('_', ' ')

const offsets = new Map<string, string>()
function offsetOf(zone: string): string {
  let offset = offsets.get(zone)
  if (offset === undefined) {
    offset = timeZoneOffset(zone)
    offsets.set(zone, offset)
  }
  return offset
}

/** Matches words in any order against the name and the offset: "kath", "new york", "+05:45". */
function matches(zone: string, query: string): boolean {
  const haystack = `${readable(zone)} ${offsetOf(zone)}`.toLowerCase()
  return query
    .toLowerCase()
    .replaceAll('_', ' ')
    .split(/\s+/)
    .filter(Boolean)
    .every((word) => haystack.includes(word))
}

/**
 * A searchable list of the time zones the API accepts, each with its current UTC offset. Only a zone
 * from the list can be chosen, so the value is always one the server takes (Base UI `Combobox`,
 * docs/06-design-system/components.md §Primitive inventory).
 */
export function TimeZoneField({
  id,
  label,
  value,
  onValueChange,
  onBlur,
  description,
  errors,
}: TimeZoneFieldProps) {
  return (
    <FormField
      id={id}
      label={label}
      {...(description ? { description } : {})}
      {...(errors ? { errors } : {})}
    >
      {(control) => (
        <Combobox
          items={timeZoneNames() as string[]}
          value={value === '' ? null : value}
          onValueChange={(next) => {
            if (typeof next === 'string') onValueChange(next)
          }}
          autoHighlight
          itemToStringLabel={(zone: string) => readable(zone)}
          filter={(zone: string, query: string) => matches(zone, query)}
        >
          <ComboboxInput
            {...control}
            {...(onBlur ? { onBlur } : {})}
            placeholder={copy.timeZoneField.placeholder}
            triggerLabel={label}
            className="w-full"
            autoComplete="off"
            spellCheck={false}
          />
          <ComboboxContent>
            <ComboboxEmpty>{copy.timeZoneField.noResults}</ComboboxEmpty>
            <ComboboxList>
              {(zone: string) => (
                <ComboboxItem key={zone} value={zone}>
                  <span className="flex w-full min-w-0 items-baseline justify-between gap-3">
                    <span className="truncate">{readable(zone)}</span>
                    <span className="shrink-0 text-xs text-muted-foreground tabular-nums">
                      {offsetOf(zone)}
                    </span>
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
