import type { ReactNode } from 'react'
import { Checkbox } from '@/components/ui/checkbox'
import { FieldDescription, FieldError, FieldLegend, FieldSet } from '@/components/ui/field'
import { Label } from '@/components/ui/label'
import { cn } from '@/lib/utils'

export interface CheckboxOption {
  value: string
  label: string
}

/** One labelled checkbox; the label is a real `<label for>`, so it names the checkbox and toggles it. */
export function CheckboxItem({
  id,
  label,
  checked,
  indeterminate,
  disabled,
  onCheckedChange,
  className,
}: {
  id: string
  label: ReactNode
  checked: boolean
  indeterminate?: boolean
  disabled?: boolean
  onCheckedChange: (checked: boolean) => void
  className?: string
}) {
  return (
    <div className={cn('flex items-center gap-2', className)}>
      <Checkbox
        id={id}
        checked={checked}
        indeterminate={indeterminate ?? false}
        disabled={disabled ?? false}
        onCheckedChange={(next) => onCheckedChange(next === true)}
      />
      <Label htmlFor={id} className="font-normal">
        {label}
      </Label>
    </div>
  )
}

/**
 * A `fieldset` of checkboxes for a list of values (roles of a user). The legend names the group; the
 * description and the messages are tied to it with `aria-describedby`, and a message is announced
 * through the `role="alert"` of `FieldError`.
 */
export function CheckboxGroup({
  id,
  legend,
  description,
  options,
  value,
  onChange,
  errors,
}: {
  id: string
  legend: string
  description?: string
  options: readonly CheckboxOption[]
  value: readonly string[]
  onChange: (value: string[]) => void
  errors?: readonly string[]
}) {
  const messages = errors?.filter((message) => message.length > 0) ?? []
  const invalid = messages.length > 0
  const descriptionId = description ? `${id}-description` : undefined
  const errorId = invalid ? `${id}-error` : undefined
  const describedBy = [descriptionId, errorId].filter(Boolean).join(' ') || undefined

  return (
    <FieldSet id={id} aria-describedby={describedBy} aria-invalid={invalid || undefined} className="gap-2">
      <FieldLegend variant="label">{legend}</FieldLegend>
      {description ? <FieldDescription id={descriptionId}>{description}</FieldDescription> : null}
      <div className="grid gap-2 sm:grid-cols-2">
        {options.map((option) => (
          <CheckboxItem
            key={option.value}
            id={`${id}-${option.value}`}
            label={option.label}
            checked={value.includes(option.value)}
            onCheckedChange={(checked) =>
              onChange(
                checked
                  ? [...value.filter((item) => item !== option.value), option.value]
                  : value.filter((item) => item !== option.value),
              )
            }
          />
        ))}
      </div>
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
    </FieldSet>
  )
}
