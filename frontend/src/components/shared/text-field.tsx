import type { ComponentProps } from 'react'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { Input } from '@/components/ui/input'

export interface TextFieldProps
  extends Omit<ComponentProps<typeof Input>, 'id' | 'value' | 'onChange' | 'aria-describedby'> {
  /** Used for the input id and derived ids of the description and the error. */
  id: string
  label: string
  value: string
  onValueChange: (value: string) => void
  description?: string
  /** Messages for this field, from client validation or a 422 problem detail. */
  errors?: readonly string[]
}

/**
 * A labelled text input whose description and error messages are tied to it with `aria-describedby`
 * and whose invalid state is announced with `aria-invalid` (docs/06-design-system/accessibility.md).
 * `FieldError` carries `role="alert"`, so a message appearing after a failed submit is read out.
 */
export function TextField({
  id,
  label,
  value,
  onValueChange,
  description,
  errors,
  ...inputProps
}: TextFieldProps) {
  const messages = errors?.filter((message) => message.length > 0) ?? []
  const invalid = messages.length > 0
  const descriptionId = description ? `${id}-description` : undefined
  const errorId = invalid ? `${id}-error` : undefined
  const describedBy = [descriptionId, errorId].filter(Boolean).join(' ') || undefined

  return (
    <Field data-invalid={invalid || undefined}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <Input
        id={id}
        value={value}
        aria-invalid={invalid || undefined}
        aria-describedby={describedBy}
        onChange={(event) => onValueChange(event.target.value)}
        {...inputProps}
      />
      {description ? <FieldDescription id={descriptionId}>{description}</FieldDescription> : null}
      {invalid ? (
        <FieldError id={errorId}>
          {messages.length === 1 ? (
            messages[0]
          ) : (
            <ul className="ml-4 flex list-disc flex-col gap-1">
              {messages.map((message) => (
                <li key={message}>{message}</li>
              ))}
            </ul>
          )}
        </FieldError>
      ) : null}
    </Field>
  )
}
