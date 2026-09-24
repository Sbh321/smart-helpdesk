import { CheckIcon, CopyIcon } from 'lucide-react'
import { useId, useState } from 'react'
import { Button } from '@/components/ui/button'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'

/**
 * Machine text for a person to read and copy (roadmap M4-11): a JSON payload, a response excerpt. A
 * `pre` with a label, wrapped lines and its own scroll, and a copy button that says it worked. Objects
 * are printed as indented JSON; strings are shown as received.
 */
export function CodeBlock({
  label,
  value,
  maxHeight = 'max-h-80',
}: {
  label: string
  value: unknown
  maxHeight?: string
}) {
  const id = useId()
  const [copied, setCopied] = useState(false)
  const text = typeof value === 'string' ? value : JSON.stringify(value, null, 2)

  async function copyText() {
    try {
      await navigator.clipboard.writeText(text)
      setCopied(true)
      window.setTimeout(() => setCopied(false), 2000)
    } catch {
      toast.error(copy.codeBlock.copyFailed)
    }
  }

  return (
    <figure className="flex min-w-0 flex-col gap-1">
      <div className="flex items-center justify-between gap-2">
        <figcaption id={id} className="text-sm font-medium">
          {label}
        </figcaption>
        <Button
          type="button"
          size="sm"
          variant="ghost"
          aria-label={fill(copy.codeBlock.copyNamed, { name: label })}
          onClick={() => void copyText()}
        >
          {copied ? <CheckIcon aria-hidden="true" /> : <CopyIcon aria-hidden="true" />}
          {copied ? copy.codeBlock.copied : copy.codeBlock.copy}
        </Button>
      </div>
      {/* A scroll area must be reachable by keyboard (axe `scrollable-region-focusable`): a named,
          focusable region around the text, so arrow keys scroll it. */}
      <section
        aria-labelledby={id}
        // biome-ignore lint/a11y/noNoninteractiveTabindex: WCAG 2.1.1 needs keyboard access to scrollable content (axe scrollable-region-focusable)
        tabIndex={0}
        className={`${maxHeight} overflow-auto rounded-control border border-border bg-muted focus-visible:outline-2 focus-visible:outline-ring`}
      >
        <pre className="p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap break-words">{text}</pre>
      </section>
    </figure>
  )
}
