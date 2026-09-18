import type { ReactNode } from 'react'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'

/** What a control needs to be tied to its label, description and errors. */
export interface FormControlProps {
  id: string
  'aria-invalid': true | undefined
  'aria-describedby': string | undefined
}

export interface FormFieldProps {
  /** Id of the control; the description and error ids derive from it. */
  id: string
  label: string
  description?: string
  /** Client (Zod) and server (422) messages for this field, already merged. */
  errors?: readonly string[]
  children: (control: FormControlProps) => ReactNode
}

/**
 * The frame every non-text form control shares with `TextField`: a label bound to the control, an
 * optional description, and error messages referenced from `aria-describedby` with `role="alert"`
 * (docs/06-design-system/components.md §TextField).
 */
export function FormField({ id, label, description, errors, children }: FormFieldProps) {
  const messages = errors?.filter((message) => message.length > 0) ?? []
  const invalid = messages.length > 0
  const descriptionId = description ? `${id}-description` : undefined
  const errorId = invalid ? `${id}-error` : undefined
  const describedBy = [descriptionId, errorId].filter(Boolean).join(' ') || undefined

  return (
    <Field data-invalid={invalid || undefined}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      {children({ id, 'aria-invalid': invalid || undefined, 'aria-describedby': describedBy })}
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
