import { useMutation, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { Button } from '@/components/ui/button'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { copy } from '@/copy/en'
import { isApiError } from '@/lib/api/errors'
import { queryKeys } from '@/lib/api/query-keys'
import { type MaybeSession, useSession } from '@/lib/auth'
import { cn } from '@/lib/utils'
import { type AgentAvailability, updateAvailability } from '../api/agent-queries'

const values = ['available', 'away', 'offline'] as const

/** The dot repeats the written state in colour; the text next to it is what carries the meaning. */
const dotClass: Record<AgentAvailability, string> = {
  available: 'bg-success',
  away: 'bg-warning',
  offline: 'bg-muted-foreground',
}

export function AvailabilityControl() {
  const { session } = useSession()
  const client = useQueryClient()
  const [error, setError] = useState<string | null>(null)
  const profile = session?.agent_profile
  const change = useMutation({
    mutationFn: ({ id, availability }: { id: string; availability: AgentAvailability }) =>
      updateAvailability(id, availability),
    onMutate: async ({ availability }) => {
      setError(null)
      await client.cancelQueries({ queryKey: queryKeys.session.me() })
      const previous = client.getQueryData<MaybeSession>(queryKeys.session.me())
      client.setQueryData<MaybeSession>(queryKeys.session.me(), (current) =>
        current?.agent_profile
          ? { ...current, agent_profile: { ...current.agent_profile, availability } }
          : current,
      )
      return { previous }
    },
    onError: (cause, _input, context) => {
      client.setQueryData(queryKeys.session.me(), context?.previous)
      setError(isApiError(cause) ? (cause.detail ?? cause.title) : copy.settings.failed)
    },
    onSuccess: (agent) => {
      client.setQueryData<MaybeSession>(queryKeys.session.me(), (current) =>
        current?.agent_profile
          ? { ...current, agent_profile: { ...current.agent_profile, availability: agent.availability } }
          : current,
      )
      if (session) void client.invalidateQueries({ queryKey: queryKeys.agents.all(session.tenant.id) })
    },
  })

  if (!profile) return null

  return (
    <div className="flex items-center gap-2">
      {error ? (
        <span role="alert" className="text-xs text-destructive">
          {error}
        </span>
      ) : null}
      <DropdownMenu>
        <DropdownMenuTrigger render={<Button variant="outline" size="sm" />}>
          <span
            className={cn('size-2 rounded-full', dotClass[profile.availability])}
            data-availability={profile.availability}
            aria-hidden="true"
          />
          {copy.settings.availabilityControl.label}: {copy.settings.availabilityControl[profile.availability]}
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {values.map((availability) => (
            <DropdownMenuItem
              key={availability}
              onClick={() => change.mutate({ id: profile.id, availability })}
            >
              {copy.settings.availabilityControl[availability]}
            </DropdownMenuItem>
          ))}
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  )
}
