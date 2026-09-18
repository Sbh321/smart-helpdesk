import { LifeBuoyIcon } from 'lucide-react'
import type { ReactNode } from 'react'
import { MAIN_CONTENT_ID, SkipLink } from '@/components/shared/skip-link'
import { ThemeToggle } from '@/components/shared/theme-toggle'
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card'
import { copy } from '@/copy/en'

/** The frame of every pre-authentication page: one card, one `h1`, no navigation to guess at. */
export function AuthLayout({
  title,
  description,
  children,
}: {
  title: string
  description?: string
  children: ReactNode
}) {
  return (
    <div className="flex min-h-dvh flex-col bg-background text-foreground">
      <SkipLink />
      <header className="flex items-center justify-between px-4 py-3">
        <span className="flex items-center gap-2 text-sm font-semibold">
          <LifeBuoyIcon aria-hidden="true" className="size-5 text-primary" />
          {copy.app.name}
        </span>
        <ThemeToggle />
      </header>
      <main
        id={MAIN_CONTENT_ID}
        tabIndex={-1}
        className="flex flex-1 items-center justify-center p-4 outline-none"
      >
        <Card className="w-full max-w-sm">
          <CardHeader>
            <h1 className="text-lg font-semibold tracking-tight">{title}</h1>
            {description ? <CardDescription>{description}</CardDescription> : null}
          </CardHeader>
          <CardContent>{children}</CardContent>
        </Card>
      </main>
    </div>
  )
}
