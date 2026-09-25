import { EyeIcon, EyeOffIcon } from 'lucide-react'
import { type KeyboardEvent, type ReactNode, useState } from 'react'
import { Field, FieldDescription, FieldError, FieldLabel } from '@/components/ui/field'
import { InputGroup, InputGroupAddon, InputGroupButton, InputGroupInput } from '@/components/ui/input-group'
import { copy } from '@/copy/en'

/**
 * A password input with a show/hide toggle and a caps-lock hint (M5-03). Ids, `aria-describedby` and
 * `aria-invalid` follow `TextField`, so errors and descriptions are announced the same way. The toggle is
 * a real button with a pressed state and a name that says what it will do; the caps-lock hint is a polite
 * status, because it matters only while typing.
 */
export function PasswordField({
  id,
  label,
  value,
  onValueChange,
  onBlur,
  description,
  errors,
  autoComplete,
  autoFocus,
  action,
}: {
  id: string
  label: string
  value: string
  onValueChange: (value: string) => void
  onBlur?: () => void
  description?: string
  errors?: readonly string[]
  autoComplete: 'current-password' | 'new-password'
  autoFocus?: boolean
  /**
   * Shown under the input at the end of the row, e.g. "Forgot your password?" on the sign-in page. It
   * follows the input and its show/hide button in both reading and tab order, as it does on screen.
   */
  action?: ReactNode
}) {
  const [visible, setVisible] = useState(false)
  const [capsLock, setCapsLock] = useState(false)
  const messages = errors?.filter((message) => message.length > 0) ?? []
  const invalid = messages.length > 0
  const descriptionId = description ? `${id}-description` : undefined
  const errorId = invalid ? `${id}-error` : undefined
  const capsId = `${id}-caps`
  const describedBy =
    [descriptionId, capsLock ? capsId : undefined, errorId].filter(Boolean).join(' ') || undefined

  const trackCapsLock = (event: KeyboardEvent<HTMLInputElement>) => {
    setCapsLock(event.getModifierState?.('CapsLock') ?? false)
  }

  // Always in the page, so the hint is announced when it appears; hidden while empty so it takes no room.
  const capsHint = (
    <p id={capsId} role="status" className="text-warning text-xs empty:hidden">
      {capsLock ? copy.auth.password.capsLock : ''}
    </p>
  )

  return (
    <Field data-invalid={invalid || undefined}>
      <FieldLabel htmlFor={id}>{label}</FieldLabel>
      <InputGroup>
        <InputGroupInput
          id={id}
          type={visible ? 'text' : 'password'}
          value={value}
          autoComplete={autoComplete}
          autoFocus={autoFocus}
          // Keeps browsers from offering to correct or capitalise a password shown in plain text.
          autoCapitalize="none"
          autoCorrect="off"
          spellCheck={false}
          aria-invalid={invalid || undefined}
          aria-describedby={describedBy}
          onChange={(event) => onValueChange(event.target.value)}
          onKeyDown={trackCapsLock}
          onKeyUp={trackCapsLock}
          onBlur={() => {
            setCapsLock(false)
            onBlur?.()
          }}
        />
        <InputGroupAddon align="inline-end">
          <InputGroupButton
            size="icon-xs"
            aria-label={visible ? copy.auth.password.hide : copy.auth.password.show}
            aria-pressed={visible}
            aria-controls={id}
            onClick={() => setVisible((previous) => !previous)}
          >
            {visible ? <EyeOffIcon aria-hidden="true" /> : <EyeIcon aria-hidden="true" />}
          </InputGroupButton>
        </InputGroupAddon>
      </InputGroup>
      {action ? (
        <div className="flex items-start justify-between gap-3">
          {capsHint}
          <span className="ms-auto">{action}</span>
        </div>
      ) : (
        capsHint
      )}
      {description ? <FieldDescription id={descriptionId}>{description}</FieldDescription> : null}
      {invalid ? (
        <FieldError id={errorId}>
          {messages.length === 1 ? (
            messages[0]
          ) : (
            <ul className="ml-4 flex list-disc flex-col gap-1">
              {messages.map((message) => (
                <li key={message}>{message}</li>
              ))}
            </ul>
          )}
        </FieldError>
      ) : null}
    </Field>
  )
}
