import { useState } from 'react'
import {
  Combobox,
  ComboboxChip,
  ComboboxChips,
  ComboboxChipsInput,
  ComboboxContent,
  ComboboxEmpty,
  ComboboxItem,
  ComboboxList,
  ComboboxValue,
  useComboboxAnchor,
} from '@/components/ui/combobox'
import { copy, fill } from '@/copy/en'
import { FormField } from './form-field'

interface TagItem {
  value: string
  label: string
}

export interface TagInputProps {
  id: string
  label: string
  /** Tag names. */
  value: readonly string[]
  onChange: (value: string[]) => void
  /** Existing tag names matching the current query (from `GET /v1/tags?search=`). */
  suggestions: readonly string[]
  onQueryChange: (query: string) => void
  description?: string
  errors?: readonly string[]
  placeholder?: string
}

const same = (a: string, b: string) => a.localeCompare(b, undefined, { sensitivity: 'accent' }) === 0

/**
 * Tags as chips in a combobox (docs/06-design-system/components.md §TagInput): existing tags are
 * suggested as you type, and a name that matches none is offered as "Add …". The API creates unknown
 * names when the record is saved, so nothing is created while typing.
 */
export function TagInput({
  id,
  label,
  value,
  onChange,
  suggestions,
  onQueryChange,
  description,
  errors,
  placeholder,
}: TagInputProps) {
  const anchor = useComboboxAnchor()
  const [query, setQuery] = useState('')
  const typed = query.trim()
  const known = [...value, ...suggestions]
  const items: TagItem[] = [
    ...suggestions.filter((name) => !value.some((chosen) => same(chosen, name))),
    ...value,
  ].map((name) => ({ value: name, label: name }))
  if (typed !== '' && !known.some((name) => same(name, typed))) {
    items.unshift({ value: typed, label: fill(copy.combobox.addTag, { name: typed }) })
  }
  const selected = value.map((name) => ({ value: name, label: name }))

  return (
    <FormField
      id={id}
      label={label}
      {...(description ? { description } : {})}
      {...(errors ? { errors } : {})}
    >
      {(control) => (
        <Combobox
          multiple
          items={items}
          value={selected}
          onValueChange={(next) => {
            const names: string[] = []
            for (const item of next as TagItem[]) {
              if (!names.some((name) => same(name, item.value))) names.push(item.value)
            }
            onChange(names)
          }}
          filter={null}
          autoHighlight
          inputValue={query}
          onInputValueChange={(text) => {
            setQuery(text)
            onQueryChange(text)
          }}
          isItemEqualToValue={(item: TagItem, current: TagItem) => same(item.value, current.value)}
          itemToStringLabel={(item: TagItem) => item.value}
          itemToStringValue={(item: TagItem) => item.value}
        >
          <ComboboxChips ref={anchor} className="w-full">
            <ComboboxValue>
              {(chosen: TagItem[]) => (
                <>
                  {chosen.map((item) => (
                    <ComboboxChip
                      key={item.value}
                      removeLabel={fill(copy.combobox.removeTag, { name: item.value })}
                    >
                      {item.value}
                    </ComboboxChip>
                  ))}
                  <ComboboxChipsInput
                    {...control}
                    placeholder={chosen.length === 0 ? placeholder : undefined}
                  />
                </>
              )}
            </ComboboxValue>
          </ComboboxChips>
          <ComboboxContent anchor={anchor}>
            <ComboboxEmpty>{copy.combobox.noResults}</ComboboxEmpty>
            <ComboboxList>
              {(item: TagItem) => (
                <ComboboxItem key={`${item.label}:${item.value}`} value={item}>
                  {item.label}
                </ComboboxItem>
              )}
            </ComboboxList>
          </ComboboxContent>
        </Combobox>
      )}
    </FormField>
  )
}
