import { format } from 'date-fns'
import { useId } from 'react'
import {
  DateRangeFilter,
  FilterBar,
  type FilterChip,
  type FilterOption,
  MultiSelectFilter,
  SelectFilter,
} from '@/components/shared/data-table'
import { Switch } from '@/components/ui/switch'
import { copy } from '@/copy/en'
import type { ReportDefinition } from '../api/report-queries'
import { DEFAULT_PERIOD, PERIODS, type ReportParams } from '../report-params'
import type { FilterChoices } from './use-filter-options'

const text = copy.reports
const CUSTOM = 'custom'

export interface ParameterBarProps {
  definition: ReportDefinition
  params: ReportParams
  filterChoices: Record<string, FilterChoices>
  /** False for the "now" reports (ageing, at-risk): no period, no comparison. */
  periodApplies: boolean
  onChange: (patch: Partial<ReportParams>) => void
}

/** "1 Sep – 15 Sep 2026" for a custom range of calendar dates. */
function rangeWords(range: { from: string; to: string }): string {
  const day = (value: string) => {
    const [year, month, date] = value.split('-').map(Number)
    return new Date(year ?? 1970, (month ?? 1) - 1, date ?? 1)
  }
  return `${format(day(range.from), 'd MMM')} – ${format(day(range.to), 'd MMM yyyy')}`
}

/**
 * The parameters in force as removable chips (roadmap M4-09), so every report says the same way what
 * it is showing, and one parameter can be dropped without finding the control it came from. The
 * default period and grouping are not chips: they are what "no parameter" means.
 */
export function parameterChips(
  definition: ReportDefinition,
  params: ReportParams,
  filterChoices: Record<string, FilterChoices>,
  periodApplies: boolean,
  onChange: (patch: Partial<ReportParams>) => void,
): FilterChip[] {
  const chips: FilterChip[] = []
  if (periodApplies && params.range) {
    chips.push({
      key: 'period',
      label: text.period,
      value: rangeWords(params.range),
      onRemove: () => onChange({ range: undefined, period: DEFAULT_PERIOD }),
    })
  } else if (periodApplies && params.period !== DEFAULT_PERIOD) {
    chips.push({
      key: 'period',
      label: text.period,
      value: text.periods[params.period] ?? params.period,
      onRemove: () => onChange({ period: DEFAULT_PERIOD }),
    })
  }
  if (periodApplies && params.compare) {
    chips.push({
      key: 'compare',
      label: text.compareChip,
      value: text.compareChipValue,
      onRemove: () => onChange({ compare: false }),
    })
  }
  if (params.group !== undefined && params.group !== definition.default_dimension) {
    const dimension = definition.dimensions.find((candidate) => candidate.key === params.group)
    chips.push({
      key: 'group',
      label: text.groupBy,
      value: dimension?.label ?? params.group,
      onRemove: () => onChange({ group: undefined }),
    })
  }
  for (const filter of definition.filters) {
    const values = params.filters[filter.key] ?? []
    if (values.length === 0) continue
    const options = filterChoices[filter.key]?.options ?? []
    const words =
      filter.type === 'boolean'
        ? values.map((value) => (value === 'true' ? text.boolean.true : text.boolean.false))
        : values.map((value) => options.find((option) => option.value === value)?.label ?? value)
    chips.push({
      key: `filter-${filter.key}`,
      label: filter.label,
      value: words.join(', '),
      onRemove: () => onChange({ filters: { ...params.filters, [filter.key]: [] } }),
    })
  }
  return chips
}

/**
 * Period (presets or a custom range), comparison, group-by and the report's own filters. Every change is
 * a navigation, so the URL is the state (docs/04-domain/reporting.md §Report page behaviour).
 */
export function ParameterBar({
  definition,
  params,
  filterChoices,
  periodApplies,
  onChange,
}: ParameterBarProps) {
  const compareId = useId()
  const periodOptions: FilterOption[] = [
    ...PERIODS.map((period) => ({ value: period, label: text.periods[period] ?? period })),
    ...(params.range ? [{ value: CUSTOM, label: text.periods.custom ?? CUSTOM }] : []),
  ]
  const groupOptions: FilterOption[] = definition.dimensions.map((dimension) => ({
    value: dimension.key,
    label: dimension.label,
  }))
  const chips = parameterChips(definition, params, filterChoices, periodApplies, onChange)
  const activeFilters =
    Object.values(params.filters).filter((values) => values.length > 0).length +
    (params.range || params.period !== DEFAULT_PERIOD ? 1 : 0) +
    (params.compare ? 1 : 0) +
    (params.group !== undefined ? 1 : 0)

  return (
    <section aria-label={text.parameters} className="print:hidden">
      <FilterBar
        chips={chips}
        activeCount={activeFilters}
        onClear={() =>
          onChange({
            period: DEFAULT_PERIOD,
            range: undefined,
            compare: false,
            group: undefined,
            filters: {},
          })
        }
      >
        {periodApplies ? (
          <>
            <SelectFilter
              label={text.period}
              options={periodOptions}
              value={params.range ? CUSTOM : params.period}
              defaultValue={DEFAULT_PERIOD}
              onChange={(value) => {
                if (value === CUSTOM) return
                onChange({
                  period: (value as ReportParams['period'] | undefined) ?? DEFAULT_PERIOD,
                  range: undefined,
                })
              }}
            />
            <DateRangeFilter
              label={text.customRange}
              value={params.range}
              onChange={(range) => onChange({ range })}
            />
            <div className="flex h-8 items-center gap-2 rounded-md border border-dashed border-border px-2.5 bg-surface">
              <Switch
                id={compareId}
                checked={params.compare}
                onCheckedChange={(checked) => onChange({ compare: checked })}
              />
              <label htmlFor={compareId} className="text-sm">
                {text.compare}
              </label>
            </div>
          </>
        ) : null}
        {groupOptions.length > 1 ? (
          <SelectFilter
            label={text.groupBy}
            options={groupOptions}
            value={params.group}
            defaultValue={definition.default_dimension}
            onChange={(group) => onChange({ group })}
          />
        ) : null}
        {definition.filters.map((filter) => {
          const choices = filterChoices[filter.key]
          if (filter.type === 'boolean') {
            return (
              <SelectFilter
                key={filter.key}
                label={filter.label}
                options={[
                  { value: 'any', label: text.boolean.any },
                  { value: 'true', label: text.boolean.true },
                  { value: 'false', label: text.boolean.false },
                ]}
                value={params.filters[filter.key]?.[0]}
                defaultValue="any"
                onChange={(value) =>
                  onChange({ filters: { ...params.filters, [filter.key]: value ? [value] : [] } })
                }
              />
            )
          }
          if (!choices || (choices.options.length === 0 && !choices.isLoading)) return null
          return (
            <MultiSelectFilter
              key={filter.key}
              label={filter.label}
              options={choices.options}
              value={params.filters[filter.key] ?? []}
              isLoading={choices.isLoading}
              onChange={(value) => onChange({ filters: { ...params.filters, [filter.key]: value } })}
            />
          )
        })}
      </FilterBar>
    </section>
  )
}
