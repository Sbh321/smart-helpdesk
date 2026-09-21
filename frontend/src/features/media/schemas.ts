import { z } from 'zod'
import { copy } from '@/copy/en'

const rules = copy.media.validation

/** `Select` needs a value for "no folder"; `toInput()` turns it into `null`. */
export const NO_FOLDER = 'none'

/** Mirrors the folder requests; a name is unique among its siblings, which the API checks (422). */
export const folderFormSchema = z.object({
  name: z.string().trim().min(1, rules.nameRequired).max(120, rules.folderNameTooLong),
})
export type FolderFormValues = z.input<typeof folderFormSchema>

/** Mirrors `PATCH /media/{media}`: name, folder and tag names. */
export const mediaItemFormSchema = z.object({
  name: z.string().trim().min(1, rules.nameRequired).max(255, rules.itemNameTooLong),
  folder: z.string().min(1),
  tags: z.array(z.string()).max(20, rules.tooManyTags),
})
export type MediaItemFormValues = z.input<typeof mediaItemFormSchema>
