import { type KeyboardEvent, useRef, useState } from 'react'

export interface RowNavigationOptions {
  rowCount: number
  /** Enter on a focused row. */
  onOpen?: (index: number) => void
  /** `x` or Space on a focused row. */
  onToggle?: (index: number) => void
}

export interface RowNavigationRowProps {
  ref: (element: HTMLTableRowElement | null) => void
  tabIndex: number
  onFocus: () => void
  onKeyDown: (event: KeyboardEvent<HTMLTableRowElement>) => void
}

/**
 * Roving focus over table rows (docs/06-design-system/accessibility.md): exactly one row is in the tab
 * order, so Tab enters the table once and leaves it again; ↑/↓ (or k/j) move, Home/End jump, Enter opens,
 * `x`/Space toggle selection. Keys pressed inside a control in the row (the checkbox, a link) belong to
 * that control.
 */
export function useRowNavigation({ rowCount, onOpen, onToggle }: RowNavigationOptions) {
  const [active, setActive] = useState(0)
  const rows = useRef<Array<HTMLTableRowElement | null>>([])
  const activeIndex = rowCount === 0 ? -1 : Math.min(active, rowCount - 1)

  const focusRow = (index: number) => {
    const target = Math.max(0, Math.min(index, rowCount - 1))
    setActive(target)
    rows.current[target]?.focus()
  }

  const onKeyDown = (index: number, event: KeyboardEvent<HTMLTableRowElement>) => {
    if (event.target !== event.currentTarget || event.altKey || event.ctrlKey || event.metaKey) {
      return
    }
    switch (event.key) {
      case 'ArrowDown':
      case 'j':
        focusRow(index + 1)
        break
      case 'ArrowUp':
      case 'k':
        focusRow(index - 1)
        break
      case 'Home':
        focusRow(0)
        break
      case 'End':
        focusRow(rowCount - 1)
        break
      case 'Enter':
        if (!onOpen) return
        onOpen(index)
        break
      case ' ':
      case 'x':
        if (!onToggle) return
        onToggle(index)
        break
      default:
        return
    }
    event.preventDefault()
  }

  const getRowProps = (index: number): RowNavigationRowProps => ({
    ref: (element) => {
      rows.current[index] = element
    },
    tabIndex: index === activeIndex ? 0 : -1,
    onFocus: () => setActive(index),
    onKeyDown: (event) => onKeyDown(index, event),
  })

  return { activeIndex, getRowProps }
}
