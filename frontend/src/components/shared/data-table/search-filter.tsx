import { SearchIcon } from 'lucide-react'
import { type KeyboardEvent, useEffect, useRef, useState } from 'react'
import { Input } from '@/components/ui/input'
import { SEARCH_MAX_LENGTH } from '@/lib/list-params'
import { cn } from '@/lib/utils'

export interface SearchFilterProps {
  /** The committed search (from the URL). */
  value: string | undefined
  /** Called once typing pauses, with the trimmed text or `undefined` when empty. */
  onChange: (value: string | undefined) => void
  /** Accessible name of the input. */
  label: string
  placeholder?: string
  /** Default 300 ms (docs/06-design-system/components.md §FilterBar). */
  debounceMs?: number
  /** `/` anywhere outside a text field focuses this input. Default true. */
  shortcut?: boolean
  className?: string
}

function isEditable(target: EventTarget | null): boolean {
  if (!(target instanceof HTMLElement)) {
    return false
  }
  return target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)
}

/**
 * Free-text search for a list. Typing is local; the URL changes once typing pauses (or on Enter), so the
 * list is not refetched on every key. A change of `value` from outside — back button, "Clear filters" —
 * replaces the text and cancels a pending write.
 */
export function SearchFilter({
  value,
  onChange,
  label,
  placeholder,
  debounceMs = 300,
  shortcut = true,
  className,
}: SearchFilterProps) {
  const [text, setText] = useState(value ?? '')
  const input = useRef<HTMLInputElement>(null)
  const timer = useRef<ReturnType<typeof setTimeout> | undefined>(undefined)
  const lastSent = useRef(value)

  useEffect(() => {
    if (value === lastSent.current) {
      return
    }
    lastSent.current = value
    clearTimeout(timer.current)
    setText(value ?? '')
  }, [value])

  useEffect(() => () => clearTimeout(timer.current), [])

  useEffect(() => {
    if (!shortcut) {
      return
    }
    const onKeyDown = (event: globalThis.KeyboardEvent) => {
      if (
        event.key === '/' &&
        !event.ctrlKey &&
        !event.metaKey &&
        !event.altKey &&
        !isEditable(event.target)
      ) {
        event.preventDefault()
        input.current?.focus()
      }
    }
    document.addEventListener('keydown', onKeyDown)
    return () => document.removeEventListener('keydown', onKeyDown)
  }, [shortcut])

  const commit = (next: string) => {
    clearTimeout(timer.current)
    const trimmed = next.trim() || undefined
    if (trimmed === lastSent.current) {
      return
    }
    lastSent.current = trimmed
    onChange(trimmed)
  }

  const handleChange = (next: string) => {
    setText(next)
    clearTimeout(timer.current)
    timer.current = setTimeout(() => commit(next), debounceMs)
  }

  const handleKeyDown = (event: KeyboardEvent<HTMLInputElement>) => {
    if (event.key === 'Enter') {
      event.preventDefault()
      commit(text)
    }
  }

  return (
    <div className={cn('relative w-full sm:w-64', className)}>
      <SearchIcon
        aria-hidden="true"
        className="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
      />
      <Input
        ref={input}
        type="search"
        aria-label={label}
        placeholder={placeholder}
        maxLength={SEARCH_MAX_LENGTH}
        value={text}
        onChange={(event) => handleChange(event.target.value)}
        onKeyDown={handleKeyDown}
        className="pl-8"
      />
    </div>
  )
}
