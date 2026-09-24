import { CopyIcon } from 'lucide-react'
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group'
import { Label } from '@/components/ui/label'
import { toast } from '@/components/ui/sonner'
import { copy, fill } from '@/copy/en'

const text = copy.apiClients

/** A read-only credential with a copy button; the input stays selectable when the clipboard is blocked. */
export function CopyableValue({ id, label, value }: { id: string; label: string; value: string }) {
  async function copyValue() {
    try {
      await navigator.clipboard.writeText(value)
      toast.success(fill(text.copied, { name: label }))
    } catch {
      toast.error(text.copyFailed)
    }
  }

  return (
    <div className="flex flex-col gap-2">
      <Label htmlFor={id}>{label}</Label>
      <InputGroup>
        <InputGroupInput
          id={id}
          readOnly
          value={value}
          className="font-mono text-xs"
          onFocus={(event) => event.currentTarget.select()}
        />
        <InputGroupAddon align="inline-end">
          <InputGroupButton
            aria-label={fill(text.copyNamed, { name: label })}
            onClick={() => void copyValue()}
          >
            <CopyIcon aria-hidden="true" />
            {text.copy}
          </InputGroupButton>
        </InputGroupAddon>
      </InputGroup>
    </div>
  )
}
