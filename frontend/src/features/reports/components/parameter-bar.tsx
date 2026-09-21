import { useId } from 'react'
import {
  DateRangeFilter,
  FilterBar,
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
  const activeFilters =
    Object.values(params.filters).filter((values) => values.length > 0).length +
    (params.range || params.period !== DEFAULT_PERIOD ? 1 : 0) +
    (params.compare ? 1 : 0) +
    (params.group !== undefined ? 1 : 0)

  return (
    <section aria-label={text.parameters} className="print:hidden">
      <FilterBar
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
            <div className="flex h-8 items-center gap-2 rounded-md border border-dashed border-border px-2.5">
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
