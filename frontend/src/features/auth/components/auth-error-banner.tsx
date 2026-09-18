import { OctagonAlertIcon } from 'lucide-react'
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert'

/**
 * A failed sign-in, invitation or reset. `Alert` already carries `role="alert"`, so the message is
 * announced the moment it appears (docs/06-design-system/accessibility.md).
 */
export function AuthErrorBanner({ title, message }: { title: string; message: string }) {
  return (
    <Alert variant="destructive">
      <OctagonAlertIcon aria-hidden="true" />
      <AlertTitle>{title}</AlertTitle>
      <AlertDescription>{message}</AlertDescription>
    </Alert>
  )
}
