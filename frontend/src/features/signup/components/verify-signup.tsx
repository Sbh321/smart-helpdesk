import { useMutation } from '@tanstack/react-query'
import { Link } from '@tanstack/react-router'
import { CircleCheckIcon } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { CopyableValue } from '@/components/shared/copyable-value'
import { ErrorState } from '@/components/shared/error-state'
import { buttonVariants } from '@/components/ui/button'
import { Skeleton } from '@/components/ui/skeleton'
import { copy, fill } from '@/copy/en'
import { SentPanel } from '@/features/auth'
import { signupApi } from '../api'

const text = copy.signup

/** The emailed link: creates the workspace once, then sends the person to sign in (ADR-0025 §8). */
export function VerifySignup({ token }: { token: string }) {
  const verify = useMutation({ mutationFn: () => signupApi.verify(token) })
  const started = useRef(false)

  useEffect(() => {
    // Once, also under React's double effects in development: the link is single use.
    if (token === '' || started.current) return
    started.current = true
    verify.mutate()
  }, [token, verify])

  if (token === '') return <p className="text-destructive text-sm">{text.missingToken}</p>
  if (verify.isError) {
    return (
      <div className="flex flex-col gap-4">
        <ErrorState error={verify.error} title={text.expiredTitle} />
        <Link to="/signup" className={buttonVariants({ variant: 'outline' })}>
          {text.startAgain}
        </Link>
      </div>
    )
  }
  if (!verify.isSuccess) {
    return (
      <div aria-busy="true" className="flex flex-col gap-3">
        <p role="status">{text.verifying}</p>
        <Skeleton className="h-24 w-full" />
      </div>
    )
  }
  const workspace = verify.data
  return (
    <div className="flex flex-col gap-4">
      <SentPanel
        icon={CircleCheckIcon}
        title={text.readyTitle}
        body={fill(text.readyBody, { name: workspace.name })}
      />
      <div className="flex flex-col gap-2">
        <CopyableValue id="signup-support-email" label={text.supportEmail} value={workspace.support_email} />
        <p className="text-sm text-muted-foreground">{text.supportEmailHelp}</p>
      </div>
      <Link
        to="/$workspace/login"
        params={{ workspace: workspace.slug }}
        className={buttonVariants({ size: 'lg' })}
      >
        {fill(text.openWorkspace, { name: workspace.name })}
      </Link>
    </div>
  )
}
