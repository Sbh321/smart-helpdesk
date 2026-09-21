import { api, unwrap } from '@/lib/api/client'
import type { components } from '@/lib/api/schema'

export type PriorityPreviewInput = components['schemas']['PreviewPriorityRequest']

export const previewPriority = (input: PriorityPreviewInput) =>
  unwrap(api().POST('/settings/automation/priority/preview', { body: input }))
