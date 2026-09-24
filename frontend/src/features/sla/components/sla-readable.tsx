import { PriorityBadge } from '@/components/shared/priority-badge'
import { copy, fill } from '@/copy/en'
import type { SlaPolicy } from '../api/sla-queries'
import { dayLine, dayWindows, weeklyMinutes, workingTime } from '../readable'
import { SLA_PRIORITIES } from '../schemas'
import { DAYS } from '../weekly-hours'

const text = copy.sla.readable
const DAY_MINUTES = 24 * 60

/**
 * A policy's promises as a table (roadmap M4-12): one row per priority, first response and resolution
 * in working time. Before this, the list named a policy and hid what it promised until opened.
 */
export function PolicyTargets({ policy }: { policy: Pick<SlaPolicy, 'name' | 'targets'> }) {
  return (
    <table className="w-full max-w-md text-sm">
      <caption className="sr-only">{fill(text.targetsCaption, { name: policy.name })}</caption>
      <thead>
        <tr className="text-left text-muted-foreground">
          <th scope="col" className="py-1 pr-4 font-normal">
            {text.priority}
          </th>
          <th scope="col" className="py-1 pr-4 font-normal">
            {copy.sla.firstResponse}
          </th>
          <th scope="col" className="py-1 font-normal">
            {copy.sla.resolution}
          </th>
        </tr>
      </thead>
      <tbody>
        {SLA_PRIORITIES.map((priority) => {
          const target = policy.targets.find((row) => row.priority_level === priority)
          return (
            <tr key={priority} className="border-border border-t">
              <th scope="row" className="py-1.5 pr-4 text-left font-normal">
                <PriorityBadge level={priority} />
              </th>
              <td className="py-1.5 pr-4 tabular-nums">
                {target ? workingTime(target.first_response_minutes) : text.none}
              </td>
              <td className="py-1.5 tabular-nums">
                {target ? workingTime(target.resolution_minutes) : text.none}
              </td>
            </tr>
          )
        })}
      </tbody>
    </table>
  )
}

/**
 * A calendar's working week (roadmap M4-12): each day with its windows in words and a 24-hour bar that
 * shows them at a glance, and the week's total. The bar is decoration (`aria-hidden`); the words are
 * the information.
 */
export function WeekSchedule({
  name,
  hours,
}: {
  name: string
  hours: Record<string, readonly (readonly string[])[]>
}) {
  return (
    <div className="flex flex-col gap-2">
      <p className="text-sm text-muted-foreground">
        {fill(text.weekTotal, { total: workingTime(weeklyMinutes(hours)) })}
      </p>
      <table className="w-full max-w-2xl text-sm">
        <caption className="sr-only">{fill(text.weekCaption, { name })}</caption>
        <tbody>
          {DAYS.map((day) => {
            const windows = dayWindows(hours[day])
            return (
              <tr key={day}>
                <th scope="row" className="w-28 py-1 pr-3 text-left font-normal">
                  {copy.sla.days[day]}
                </th>
                <td
                  className={`w-44 py-1 pr-3 tabular-nums ${windows.length === 0 ? 'text-muted-foreground' : ''}`}
                >
                  {dayLine(hours[day])}
                </td>
                <td className="py-1">
                  <div aria-hidden="true" className="relative h-2 rounded-full bg-muted">
                    {windows.map((window) => (
                      <div
                        key={window.start}
                        className="absolute inset-y-0 rounded-full bg-primary"
                        style={{
                          left: `${(window.start / DAY_MINUTES) * 100}%`,
                          width: `${((window.end - window.start) / DAY_MINUTES) * 100}%`,
                        }}
                      />
                    ))}
                  </div>
                </td>
              </tr>
            )
          })}
        </tbody>
      </table>
    </div>
  )
}
