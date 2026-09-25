import { api, unwrap } from '@/lib/api/client'

export type SignupInput = {
  name: string
  email: string
  password: string
  password_confirmation: string
  workspace_name: string
  slug: string
  timezone: string
  website: string
}

/** Self sign-up (ADR-0025 §8): check an address, ask for the link, follow it. */
export const signupApi = {
  address: (slug: string) => unwrap(api().GET('/signup/address', { params: { query: { slug } } })),
  request: (input: SignupInput) => unwrap(api().POST('/signup', { body: input })),
  verify: (token: string) => unwrap(api().POST('/signup/verify', { body: { token } })),
}
