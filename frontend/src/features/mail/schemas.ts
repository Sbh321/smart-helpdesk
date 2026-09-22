import { z } from 'zod'
import { copy } from '@/copy/en'

const rules = copy.mailSettings.validation

/** Mirrors `MailServiceProvider::senderNameRules()`: a display name, never an address or a header. */
export const senderFormSchema = z.object({
  sender_name: z
    .string()
    .trim()
    .max(80, rules.senderNameLength)
    .refine((value) => !/[\r\n<>"@\\]/.test(value), rules.senderNameCharacters),
})

export type SenderFormValues = z.infer<typeof senderFormSchema>

/** `Name <address>` as a mail client shows the sender. */
export function formatMailbox(mailbox: { name: string; address: string }): string {
  return `${mailbox.name} <${mailbox.address}>`
}
