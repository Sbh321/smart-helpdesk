import { Columns3Icon } from 'lucide-react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuCheckboxItem,
  DropdownMenuContent,
  DropdownMenuGroup,
  DropdownMenuLabel,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { copy } from '@/copy/en'

export interface ColumnToggleItem {
  id: string
  label: string
  visible: boolean
}

export interface DataTableColumnToggleProps {
  columns: ColumnToggleItem[]
  onToggle: (id: string, visible: boolean) => void
}

/** "Columns" menu: one checkbox item per column that may be hidden. */
export function DataTableColumnToggle({ columns, onToggle }: DataTableColumnToggleProps) {
  if (columns.length === 0) {
    return null
  }
  return (
    <DropdownMenu>
      <DropdownMenuTrigger render={<Button type="button" variant="outline" size="sm" />}>
        <Columns3Icon aria-hidden="true" />
        {copy.dataTable.columns}
      </DropdownMenuTrigger>
      <DropdownMenuContent align="end" className="w-48">
        {/* Base UI throws when a GroupLabel is not inside a Group (components.md). */}
        <DropdownMenuGroup>
          <DropdownMenuLabel>{copy.dataTable.columnsMenu}</DropdownMenuLabel>
          {columns.map((column) => (
            <DropdownMenuCheckboxItem
              key={column.id}
              checked={column.visible}
              onCheckedChange={(checked) => onToggle(column.id, checked)}
            >
              {column.label}
            </DropdownMenuCheckboxItem>
          ))}
        </DropdownMenuGroup>
      </DropdownMenuContent>
    </DropdownMenu>
  )
}
