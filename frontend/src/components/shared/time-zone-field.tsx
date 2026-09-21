import { timeZoneNames } from '@/lib/datetime/time-zones'
import { TextField, type TextFieldProps } from './text-field'

/**
 * A `TextField` for an IANA time zone with the browser's zone list as suggestions (`<datalist>`), so a
 * zone can be picked or typed. The form schema validates the value with `isTimeZone`.
 */
export function TimeZoneField(props: Omit<TextFieldProps, 'list' | 'autoComplete'>) {
  const listId = `${props.id}-zones`
  return (
    <>
      <TextField {...props} list={listId} autoComplete="off" spellCheck={false} />
      <datalist id={listId}>
        {timeZoneNames().map((zone) => (
          <option key={zone} value={zone} />
        ))}
      </datalist>
    </>
  )
}
