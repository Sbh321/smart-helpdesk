import type { LucideIcon } from 'lucide-react'

/**
 * The "check your inbox" state of the reset and finder forms (M5-03). A polite status, so moving from
 * the form to this panel is announced; its own `h2` under the page's `h1`.
 */
export function SentPanel({ icon: Icon, title, body }: { icon: LucideIcon; title: string; body: string }) {
  return (
    <div
      role="status"
      className="flex flex-col items-start gap-3 rounded-card border border-border bg-surface p-5 shadow-1"
    >
      <span className="grid size-10 place-items-center rounded-full bg-success/10 text-success">
        <Icon aria-hidden="true" className="size-5" />
      </span>
      <h2 className="font-semibold text-lg">{title}</h2>
      <p className="text-pretty text-muted-foreground text-sm">{body}</p>
    </div>
  )
}
