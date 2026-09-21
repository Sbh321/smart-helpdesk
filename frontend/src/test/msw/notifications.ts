import { HttpResponse, http } from 'msw'
import { db } from './data'
import { apiUrl, problem } from './handlers'
import { paginate } from './list'

const NOW = '2026-09-21T09:00:00Z'

/** `/v1/notifications…` of the signed-in user (M2-09): unread first, then newest. */
export const notificationHandlers = [
  http.get(apiUrl('/notifications'), ({ request }) => {
    const url = new URL(request.url)
    const unreadOnly = url.searchParams.get('filter[unread]') === 'true'
    const rows = db.notifications
      .filter((item) => !unreadOnly || item.read_at === null)
      .toSorted(
        (a, b) =>
          Number(a.read_at !== null) - Number(b.read_at !== null) || b.created_at.localeCompare(a.created_at),
      )
    return HttpResponse.json(paginate(url, rows))
  }),
  http.post(apiUrl('/notifications/read-all'), () => {
    for (const item of db.notifications) item.read_at ??= NOW
    return new HttpResponse(null, { status: 204 })
  }),
  http.post(apiUrl('/notifications/{notification}/read'), ({ params }) => {
    const item = db.notifications.find((candidate) => candidate.id === params.notification)
    if (!item) return problem(404, 'not_found', { title: 'Not found' })
    item.read_at ??= NOW
    return HttpResponse.json({ data: item })
  }),
]
