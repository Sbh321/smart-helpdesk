import { format } from 'date-fns'
import { CalendarIcon, XIcon } from 'lucide-react'
import { useState } from 'react'
import type { DateRange } from 'react-day-picker'
import { Button } from '@/components/ui/button'
import { Calendar } from '@/components/ui/calendar'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { copy, fill } from '@/copy/en'
import type { DateRangeValue } from '@/lib/list-params'

export interface DateRangeFilterProps {
  label: string
  /** Inclusive `YYYY-MM-DD` dates (from the URL). */
  value: DateRangeValue | undefined
  onChange: (value: DateRangeValue | undefined) => void
}

/** `YYYY-MM-DD` → a local calendar date, without the UTC shift `new Date(string)` would apply. */
export function fromIsoDate(value: string): Date {
  const [year = 1970, month = 1, day = 1] = value.split('-').map(Number)
  return new Date(year, month - 1, day)
}

export function toIsoDate(date: Date): string {
  return format(date, 'yyyy-MM-dd')
}

const DISPLAY = 'd MMM yyyy'

/**
 * A calendar range in a popover (react-day-picker through shadcn `calendar`). The first click picks one
 * end, the second the other (either order); the filter is written once both ends exist. The dates are calendar days
 * in the tenant's time zone — the API converts them (docs/07-api/pagination-filtering.md).
 */
export function DateRangeFilter({ label, value, onChange }: DateRangeFilterProps) {
  const [open, setOpen] = useState(false)
  const committed: DateRange | undefined = value
    ? { from: fromIsoDate(value.from), to: fromIsoDate(value.to) }
    : undefined
  const [draft, setDraft] = useState<DateRange | undefined>(committed)
  // The first click of a pick; react-day-picker's own range rules would extend the old range instead.
  const [anchor, setAnchor] = useState<Date | undefined>(undefined)

  const summary = value
    ? fill(copy.filters.dateRange.range, {
        from: format(fromIsoDate(value.from), DISPLAY),
        to: format(fromIsoDate(value.to), DISPLAY),
      })
    : undefined

  return (
    <Popover
      open={open}
      onOpenChange={(next) => {
        setOpen(next)
        if (next) {
          setDraft(committed)
          setAnchor(undefined)
        }
      }}
    >
      <PopoverTrigger render={<Button type="button" variant="outline" size="sm" className="border-dashed" />}>
        <CalendarIcon aria-hidden="true" />
        {label}
        {summary ? <span className="font-normal text-muted-foreground">{summary}</span> : null}
      </PopoverTrigger>
      <PopoverContent className="w-auto p-0" align="start" aria-label={label}>
        <Calendar
          mode="range"
          numberOfMonths={1}
          selected={draft}
          defaultMonth={draft?.from}
          onSelect={(_range, day) => {
            if (!anchor) {
              setAnchor(day)
              setDraft({ from: day, to: undefined })
              return
            }
            const [from, to] = anchor <= day ? [anchor, day] : [day, anchor]
            setAnchor(undefined)
            setDraft({ from, to })
            onChange({ from: toIsoDate(from), to: toIsoDate(to) })
            setOpen(false)
          }}
        />
        <div className="flex items-center justify-between gap-2 border-t border-border p-2">
          <p className="text-xs text-muted-foreground">{copy.filters.dateRange.hint}</p>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={!value}
            onClick={() => {
              onChange(undefined)
              setDraft(undefined)
              setOpen(false)
            }}
          >
            <XIcon aria-hidden="true" />
            {copy.filters.dateRange.clear}
          </Button>
        </div>
      </PopoverContent>
    </Popover>
  )
}
