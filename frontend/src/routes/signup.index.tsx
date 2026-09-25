import { createFileRoute } from '@tanstack/react-router'
import { AuthLayout } from '@/components/layout/auth-layout'
import { copy } from '@/copy/en'
import { SignupForm } from '@/features/signup'

/** Self sign-up on the app host (ADR-0025 §8). */
export const Route = createFileRoute('/signup/')({
  component: () => (
    <AuthLayout title={copy.signup.title} description={copy.signup.description}>
      <SignupForm />
    </AuthLayout>
  ),
})
