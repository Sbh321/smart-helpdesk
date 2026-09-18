import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { FormField } from './form-field'

export interface SelectOption<TValue extends string | number> {
  value: TValue
  label: string
}

export interface SelectFieldProps<TValue extends string | number> {
  id: string
  label: string
  value: TValue | null
  onValueChange: (value: TValue) => void
  options: readonly SelectOption<TValue>[]
  placeholder?: string
  description?: string
  errors?: readonly string[]
  disabled?: boolean
}

/** A labelled `Select` for short enumerations (tier, impact, category). */
export function SelectField<TValue extends string | number>({
  id,
  label,
  value,
  onValueChange,
  options,
  placeholder,
  description,
  errors,
  disabled,
}: SelectFieldProps<TValue>) {
  return (
    <FormField
      id={id}
      label={label}
      {...(description ? { description } : {})}
      {...(errors ? { errors } : {})}
    >
      {(control) => (
        <Select
          items={options as SelectOption<TValue>[]}
          value={value}
          onValueChange={(next) => {
            if (next !== null) onValueChange(next as TValue)
          }}
          {...(disabled ? { disabled } : {})}
        >
          <SelectTrigger {...control} className="w-full">
            <SelectValue placeholder={placeholder} />
          </SelectTrigger>
          <SelectContent>
            {options.map((option) => (
              <SelectItem key={option.value} value={option.value}>
                {option.label}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      )}
    </FormField>
  )
}
