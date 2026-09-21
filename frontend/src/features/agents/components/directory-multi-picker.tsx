import { EntityCombobox, type EntityOption } from '@/components/shared/entity-combobox'
import { Button } from '@/components/ui/button'
import { copy } from '@/copy/en'

export function DirectoryMultiPicker({
  id,
  label,
  selected,
  options,
  onQueryChange,
  onChange,
  isLoading,
  errors,
}: {
  id: string
  label: string
  selected: EntityOption[]
  options: EntityOption[]
  onQueryChange: (query: string) => void
  onChange: (items: EntityOption[]) => void
  isLoading: boolean
  /** Client and server messages of the field, already merged. */
  errors?: readonly string[]
}) {
  return (
    <div className="space-y-2">
      <EntityCombobox
        id={id}
        label={label}
        value={null}
        options={options.filter((item) => !selected.some((picked) => picked.value === item.value))}
        onQueryChange={onQueryChange}
        isLoading={isLoading}
        {...(errors ? { errors } : {})}
        onChange={(item) => {
          if (item && !selected.some((picked) => picked.value === item.value)) onChange([...selected, item])
        }}
      />
      {selected.length ? (
        <ul className="flex flex-wrap gap-2" aria-label={label}>
          {selected.map((item) => (
            <li key={item.value} className="flex items-center gap-2 rounded-lg border px-2 py-1 text-sm">
              {item.label}
              <Button
                type="button"
                variant="ghost"
                size="sm"
                aria-label={copy.settings.removeNamed.replace('{name}', item.label)}
                onClick={() => onChange(selected.filter((picked) => picked.value !== item.value))}
              >
                ×
              </Button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}
