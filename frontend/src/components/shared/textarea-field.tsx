import type { ComponentProps } from 'react'
import { Textarea } from '@/components/ui/textarea'
import { FormField } from './form-field'

export interface TextareaFieldProps
  extends Omit<ComponentProps<typeof Textarea>, 'id' | 'value' | 'onChange' | 'aria-describedby'> {
  id: string
  label: string
  value: string
  onValueChange: (value: string) => void
  description?: string
  errors?: readonly string[]
}

/** A labelled multi-line text input, wired like `TextField`. */
export function TextareaField({
  id,
  label,
  value,
  onValueChange,
  description,
  errors,
  ...textareaProps
}: TextareaFieldProps) {
  return (
    <FormField
      id={id}
      label={label}
      {...(description ? { description } : {})}
      {...(errors ? { errors } : {})}
    >
      {(control) => (
        <Textarea
          {...control}
          value={value}
          onChange={(event) => onValueChange(event.target.value)}
          {...textareaProps}
        />
      )}
    </FormField>
  )
}
