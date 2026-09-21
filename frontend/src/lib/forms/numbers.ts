import { z } from 'zod'

/**
 * Number inputs keep their text in the form (an emptied input is `''`, not `0`), and are converted in
 * the form's `toInput()`. These schemas check that text.
 */
export function integerText(min: number, max: number, message: string) {
  return z
    .string()
    .trim()
    .refine((value) => /^-?\d+$/.test(value) && Number(value) >= min && Number(value) <= max, message)
}

export function decimalText(min: number, max: number, message: string) {
  return z
    .string()
    .trim()
    .refine((value) => /^-?\d+(\.\d+)?$/.test(value) && Number(value) >= min && Number(value) <= max, message)
}
