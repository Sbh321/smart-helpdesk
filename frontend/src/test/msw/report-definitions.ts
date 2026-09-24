import type { components } from '@/lib/api/schema'

type ReportDefinition = components['schemas']['ReportDefinitionResource']

const PERIODS = [
  'today',
  'yesterday',
  'last_7d',
  'last_30d',
  'last_90d',
  'this_month',
  'last_month',
  'this_year',
]

/**
 * Ten catalogue definitions exactly as `GET /v1/reports` describes them (exported from the backend's
 * `ReportDescription`): one or more of every chart kind the report page draws.
 */
export const REPORT_DEFINITIONS: ReportDefinition[] = [
  {
    key: 'rpt-t01',
    title: 'Ticket volume',
    description: 'Tickets created, resolved, closed and reopened, and the net change of the backlog.',
    group: 'tickets',
    chart: 'line',
    chart_measures: null,
    period_applies: true,
    default_dimension: 'day',
    drill_down_to: 'tickets',
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'day',
        label: 'Day',
        is_time: true,
      },
      {
        key: 'week',
        label: 'Week',
        is_time: true,
      },
      {
        key: 'month',
        label: 'Month',
        is_time: true,
      },
      {
        key: 'channel',
        label: 'Channel',
        is_time: false,
      },
      {
        key: 'category',
        label: 'Category',
        is_time: false,
      },
      {
        key: 'priority',
        label: 'Priority',
        is_time: false,
      },
      {
        key: 'organization',
        label: 'Organisation',
        is_time: false,
      },
      {
        key: 'team',
        label: 'Team',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'created',
        label: 'Created',
        unit: 'count',
      },
      {
        key: 'resolved',
        label: 'Resolved',
        unit: 'count',
      },
      {
        key: 'closed',
        label: 'Closed',
        unit: 'count',
      },
      {
        key: 'reopened',
        label: 'Reopened',
        unit: 'count',
      },
      {
        key: 'net_change',
        label: 'Net change of the backlog',
        unit: 'number',
      },
    ],
    filters: [
      {
        key: 'channel',
        label: 'Channel',
        type: 'values',
        labels: 'channel',
      },
      {
        key: 'category',
        label: 'Category',
        type: 'values',
        labels: 'categories',
      },
      {
        key: 'priority',
        label: 'Priority',
        type: 'values',
        labels: 'priority',
      },
      {
        key: 'team',
        label: 'Team',
        type: 'values',
        labels: 'teams',
      },
      {
        key: 'organization',
        label: 'Organisation',
        type: 'values',
        labels: 'organizations',
      },
    ],
  },
  {
    key: 'rpt-t02',
    title: 'Backlog over time',
    description: 'Tickets not yet resolved or closed at the end of each day.',
    group: 'tickets',
    chart: 'stacked_area',
    chart_measures: null,
    period_applies: true,
    default_dimension: 'day',
    drill_down_to: null,
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'day',
        label: 'Day',
        is_time: true,
      },
      {
        key: 'team',
        label: 'Team',
        is_time: false,
      },
      {
        key: 'agent',
        label: 'Agent',
        is_time: false,
      },
      {
        key: 'priority',
        label: 'Priority',
        is_time: false,
      },
      {
        key: 'status',
        label: 'Status',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'end_backlog',
        label: 'Backlog at the end of the period (or day)',
        unit: 'count',
      },
      {
        key: 'average_backlog',
        label: 'Average daily backlog',
        unit: 'number',
      },
      {
        key: 'peak_backlog',
        label: 'Highest daily backlog',
        unit: 'count',
      },
    ],
    filters: [],
  },
  {
    key: 'rpt-t03',
    title: 'Time in status',
    description: 'How long tickets stayed in each status, for the status periods that started in the period.',
    group: 'tickets',
    chart: 'bar',
    chart_measures: null,
    period_applies: true,
    default_dimension: 'status',
    drill_down_to: 'tickets',
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'status',
        label: 'Status',
        is_time: false,
      },
      {
        key: 'priority',
        label: 'Priority',
        is_time: false,
      },
      {
        key: 'team',
        label: 'Team',
        is_time: false,
      },
      {
        key: 'agent',
        label: 'Agent',
        is_time: false,
      },
      {
        key: 'category',
        label: 'Category',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'intervals',
        label: 'Status periods',
        unit: 'count',
      },
      {
        key: 'open',
        label: 'Still in the status',
        unit: 'count',
      },
      {
        key: 'average_wall',
        label: 'Average (wall-clock)',
        unit: 'seconds',
      },
      {
        key: 'median_wall',
        label: 'Median (wall-clock)',
        unit: 'seconds',
      },
      {
        key: 'p90_wall',
        label: '90th percentile (wall-clock)',
        unit: 'seconds',
      },
      {
        key: 'average_business',
        label: 'Average (business)',
        unit: 'seconds',
      },
      {
        key: 'median_business',
        label: 'Median (business)',
        unit: 'seconds',
      },
      {
        key: 'p90_business',
        label: '90th percentile (business)',
        unit: 'seconds',
      },
    ],
    filters: [
      {
        key: 'status',
        label: 'Status',
        type: 'values',
        labels: 'status',
      },
      {
        key: 'priority',
        label: 'Priority',
        type: 'values',
        labels: 'priority',
      },
      {
        key: 'team',
        label: 'Team',
        type: 'values',
        labels: 'teams',
      },
      {
        key: 'category',
        label: 'Category',
        type: 'values',
        labels: 'categories',
      },
    ],
  },
  {
    key: 'rpt-t05',
    title: 'Ageing',
    description: 'Open tickets now by age since creation. The period does not apply.',
    group: 'tickets',
    chart: 'bar',
    chart_measures: null,
    period_applies: false,
    default_dimension: 'age_bucket',
    drill_down_to: 'tickets',
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'age_bucket',
        label: 'Age',
        is_time: false,
      },
      {
        key: 'priority',
        label: 'Priority',
        is_time: false,
      },
      {
        key: 'team',
        label: 'Team',
        is_time: false,
      },
      {
        key: 'agent',
        label: 'Agent',
        is_time: false,
      },
      {
        key: 'category',
        label: 'Category',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'open',
        label: 'Open tickets',
        unit: 'count',
      },
      {
        key: 'oldest',
        label: 'Oldest ticket',
        unit: 'seconds',
      },
      {
        key: 'average_age',
        label: 'Average age',
        unit: 'seconds',
      },
    ],
    filters: [
      {
        key: 'priority',
        label: 'Priority',
        type: 'values',
        labels: 'priority',
      },
      {
        key: 'team',
        label: 'Team',
        type: 'values',
        labels: 'teams',
      },
      {
        key: 'age_bucket',
        label: 'Age',
        type: 'values',
        labels: 'age_bucket',
      },
    ],
  },
  {
    key: 'rpt-t06',
    title: 'Response and resolution times',
    description:
      'First response and resolution times of the tickets created in the period, in business and wall-clock time.',
    group: 'tickets',
    chart: 'bar',
    chart_measures: null,
    period_applies: true,
    default_dimension: 'priority',
    drill_down_to: 'tickets',
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'priority',
        label: 'Priority',
        is_time: false,
      },
      {
        key: 'week',
        label: 'Week',
        is_time: true,
      },
      {
        key: 'month',
        label: 'Month',
        is_time: true,
      },
      {
        key: 'team',
        label: 'Team',
        is_time: false,
      },
      {
        key: 'agent',
        label: 'Agent',
        is_time: false,
      },
      {
        key: 'category',
        label: 'Category',
        is_time: false,
      },
      {
        key: 'tier',
        label: 'Customer tier',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'tickets',
        label: 'Tickets',
        unit: 'count',
      },
      {
        key: 'responded',
        label: 'Responded',
        unit: 'count',
      },
      {
        key: 'first_response_median',
        label: 'First response, median (business)',
        unit: 'seconds',
      },
      {
        key: 'first_response_p90',
        label: 'First response, p90 (business)',
        unit: 'seconds',
      },
      {
        key: 'first_response_median_wall',
        label: 'First response, median (wall-clock)',
        unit: 'seconds',
      },
      {
        key: 'resolved',
        label: 'Resolved',
        unit: 'count',
      },
      {
        key: 'resolution_median',
        label: 'Resolution, median (business)',
        unit: 'seconds',
      },
      {
        key: 'resolution_p90',
        label: 'Resolution, p90 (business)',
        unit: 'seconds',
      },
      {
        key: 'resolution_average',
        label: 'Resolution, average (business)',
        unit: 'seconds',
      },
      {
        key: 'resolution_median_wall',
        label: 'Resolution, median (wall-clock)',
        unit: 'seconds',
      },
    ],
    filters: [
      {
        key: 'priority',
        label: 'Priority',
        type: 'values',
        labels: 'priority',
      },
      {
        key: 'team',
        label: 'Team',
        type: 'values',
        labels: 'teams',
      },
      {
        key: 'agent',
        label: 'Agent',
        type: 'values',
        labels: 'agents',
      },
      {
        key: 'category',
        label: 'Category',
        type: 'values',
        labels: 'categories',
      },
      {
        key: 'channel',
        label: 'Channel',
        type: 'values',
        labels: 'channel',
      },
    ],
  },
  {
    key: 'rpt-t07',
    title: 'Reopens and rework',
    description: 'Reopen rate of the tickets created in the period, and the tickets reopened more than once.',
    group: 'tickets',
    chart: 'bar',
    chart_measures: null,
    period_applies: true,
    default_dimension: 'category',
    drill_down_to: 'tickets',
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'category',
        label: 'Category',
        is_time: false,
      },
      {
        key: 'agent',
        label: 'Agent',
        is_time: false,
      },
      {
        key: 'team',
        label: 'Team',
        is_time: false,
      },
      {
        key: 'priority',
        label: 'Priority',
        is_time: false,
      },
      {
        key: 'week',
        label: 'Week',
        is_time: true,
      },
    ],
    measures: [
      {
        key: 'resolved',
        label: 'Resolved at least once',
        unit: 'count',
      },
      {
        key: 'reopened',
        label: 'Reopened',
        unit: 'count',
      },
      {
        key: 'reopen_rate',
        label: 'Reopen rate',
        unit: 'percent',
      },
      {
        key: 'reopened_more_than_once',
        label: 'Reopened more than once',
        unit: 'count',
      },
      {
        key: 'reopens',
        label: 'Reopens',
        unit: 'count',
      },
    ],
    filters: [
      {
        key: 'category',
        label: 'Category',
        type: 'values',
        labels: 'categories',
      },
      {
        key: 'team',
        label: 'Team',
        type: 'values',
        labels: 'teams',
      },
      {
        key: 'priority',
        label: 'Priority',
        type: 'values',
        labels: 'priority',
      },
    ],
  },
  {
    key: 'rpt-t11',
    title: 'Workload heatmap',
    description: 'Tickets created and replies sent by weekday and hour, in the workspace time zone.',
    group: 'tickets',
    chart: 'heatmap',
    chart_measures: null,
    period_applies: true,
    default_dimension: 'weekday_hour',
    drill_down_to: null,
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'weekday_hour',
        label: 'Weekday and hour',
        is_time: false,
      },
      {
        key: 'weekday',
        label: 'Weekday',
        is_time: false,
      },
      {
        key: 'hour',
        label: 'Hour',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'created',
        label: 'Tickets created',
        unit: 'count',
      },
      {
        key: 'replies',
        label: 'Replies sent',
        unit: 'count',
      },
      {
        key: 'total',
        label: 'Created and replies',
        unit: 'count',
      },
    ],
    filters: [],
  },
  {
    key: 'rpt-s01',
    title: 'SLA compliance',
    description:
      'SLA timers met and breached, and the compliance rate, for the timers started in the period.',
    group: 'sla',
    chart: 'stacked_bar',
    chart_measures: ['met', 'breached', 'running'],
    period_applies: true,
    default_dimension: 'week',
    drill_down_to: 'tickets',
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'day',
        label: 'Day',
        is_time: true,
      },
      {
        key: 'week',
        label: 'Week',
        is_time: true,
      },
      {
        key: 'policy',
        label: 'SLA policy',
        is_time: false,
      },
      {
        key: 'calendar',
        label: 'Business calendar',
        is_time: false,
      },
      {
        key: 'priority',
        label: 'Priority',
        is_time: false,
      },
      {
        key: 'team',
        label: 'Team',
        is_time: false,
      },
      {
        key: 'tier',
        label: 'Customer tier',
        is_time: false,
      },
      {
        key: 'kind',
        label: 'Timer',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'timers',
        label: 'Timers',
        unit: 'count',
      },
      {
        key: 'met',
        label: 'Met',
        unit: 'count',
      },
      {
        key: 'breached',
        label: 'Breached',
        unit: 'count',
      },
      {
        key: 'running',
        label: 'Still running',
        unit: 'count',
      },
      {
        key: 'compliance',
        label: 'Compliance',
        unit: 'percent',
      },
    ],
    filters: [
      {
        key: 'kind',
        label: 'Timer',
        type: 'values',
        labels: 'timer_kind',
      },
      {
        key: 'policy',
        label: 'SLA policy',
        type: 'values',
        labels: 'sla_policies',
      },
      {
        key: 'priority',
        label: 'Priority',
        type: 'values',
        labels: 'priority',
      },
      {
        key: 'team',
        label: 'Team',
        type: 'values',
        labels: 'teams',
      },
    ],
  },
  {
    key: 'rpt-a01',
    title: 'Agent workload over time',
    description: 'Open assigned tickets at the end of each day and capacity utilisation, per agent or team.',
    group: 'agents',
    chart: 'line',
    chart_measures: null,
    period_applies: true,
    default_dimension: 'day',
    drill_down_to: null,
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'day',
        label: 'Day',
        is_time: true,
      },
      {
        key: 'week',
        label: 'Week',
        is_time: true,
      },
      {
        key: 'agent',
        label: 'Agent',
        is_time: false,
      },
      {
        key: 'team',
        label: 'Team',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'backlog',
        label: 'Open assigned tickets (daily average)',
        unit: 'number',
      },
      {
        key: 'peak_backlog',
        label: 'Highest backlog of one agent or team',
        unit: 'count',
      },
      {
        key: 'utilisation',
        label: 'Capacity utilisation (agents)',
        unit: 'percent',
      },
    ],
    filters: [],
  },
  {
    key: 'rpt-c02',
    title: 'Top requesters',
    description: 'Contacts or organisations by tickets raised in the period, most first.',
    group: 'contacts',
    chart: 'table',
    chart_measures: null,
    period_applies: true,
    default_dimension: 'contact',
    drill_down_to: 'tickets',
    periods: [...PERIODS],
    dimensions: [
      {
        key: 'contact',
        label: 'Contact',
        is_time: false,
      },
      {
        key: 'organization',
        label: 'Organisation',
        is_time: false,
      },
    ],
    measures: [
      {
        key: 'tickets',
        label: 'Tickets',
        unit: 'count',
      },
      {
        key: 'open',
        label: 'Still open',
        unit: 'count',
      },
      {
        key: 'reopen_rate',
        label: 'Reopen rate',
        unit: 'percent',
      },
      {
        key: 'breaches',
        label: 'Tickets with an SLA breach',
        unit: 'count',
      },
    ],
    filters: [
      {
        key: 'organization',
        label: 'Organisation',
        type: 'values',
        labels: 'organizations',
      },
    ],
  },
]
