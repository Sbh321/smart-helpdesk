/**
 * The landing site (M5-04), rendered to static HTML at build time (`render.tsx`), so it needs no
 * JavaScript to read; `client.ts` adds the theme switch and the header's scrolled state.
 *
 * Section layouts are adapted from Tailark blocks, Mist kit (https://github.com/tailark/blocks, MIT,
 * Copyright (c) 2025 Irung; notice in `landing/public/third-party-licences.txt`): hero-section one
 * (header and hero), features two and four (the feature cards), faqs two, call-to-action one and
 * footer two. They are rewritten on the application's design tokens and components, with the
 * product's own copy and illustrations; nothing here uses Next.js.
 */
import {
  ArrowRightIcon,
  BarChart3Icon,
  BookOpenIcon,
  CheckIcon,
  ChevronDownIcon,
  CodeIcon,
  FolderLockIcon,
  GlobeIcon,
  ImageIcon,
  KeyboardIcon,
  MailIcon,
  MenuIcon,
  MoonIcon,
  ScrollTextIcon,
  ShieldCheckIcon,
  SunIcon,
  UsersIcon,
  WebhookIcon,
} from 'lucide-react'
import type { ReactNode } from 'react'
import { BrandMark } from '@/components/shared/brand-mark'
import { buttonVariants } from '@/components/ui/button'
import { landing } from '@/copy/landing'
import { cn } from '@/lib/utils'

/** Host-independent entry points: the apex proxy redirects them to the right host (frontend/docker/Caddyfile). */
export const LINKS = {
  app: '/go/app',
  find: '/go/find',
  docs: '/go/docs',
  licences: '/third-party-licences.txt',
} as const

const CONTAINER = 'mx-auto w-full max-w-6xl px-4 sm:px-6'

export function LandingPage() {
  return (
    <>
      <a
        href="#main"
        className="sr-only focus:not-sr-only focus:fixed focus:top-3 focus:left-3 focus:z-50 focus:rounded-control focus:bg-surface focus:px-3 focus:py-2 focus:shadow-2"
      >
        {landing.nav.skip}
      </a>
      <Header />
      <main id="main" tabIndex={-1} className="outline-none">
        <Hero />
        <Channels />
        <Features />
        <Showcase />
        <MoreFeatures />
        <HowItWorks />
        <Faq />
        <CallToAction />
      </main>
      <Footer />
    </>
  )
}

/* ---------- Header (Tailark Mist hero-section one, header) ---------- */

function Header() {
  const text = landing.nav
  const sections = [
    { href: '#features', label: text.features },
    { href: '#how-it-works', label: text.how },
    { href: '#faq', label: text.faq },
    { href: LINKS.docs, label: text.developers },
  ]

  return (
    <header
      data-landing-header
      className="sticky top-0 z-10 border-transparent border-b transition-colors data-[scrolled]:border-border data-[scrolled]:bg-background/80 data-[scrolled]:backdrop-blur-lg"
    >
      <nav aria-label={text.label} className={cn(CONTAINER, 'flex h-16 items-center justify-between gap-4')}>
        <a
          href="/"
          aria-label={text.home}
          className="rounded-control focus-visible:outline-2 focus-visible:outline-ring focus-visible:outline-offset-4"
        >
          <BrandMark className="whitespace-nowrap" />
        </a>

        <ul className="hidden items-center gap-1 lg:flex">
          {sections.map((item) => (
            <li key={item.href}>
              <a href={item.href} className={buttonVariants({ variant: 'ghost', size: 'sm' })}>
                {item.label}
              </a>
            </li>
          ))}
        </ul>

        <div className="flex items-center gap-2">
          <ThemeButton />
          <a
            href={LINKS.app}
            className={cn(buttonVariants({ variant: 'ghost', size: 'sm' }), 'hidden sm:inline-flex')}
          >
            {text.signIn}
          </a>
          <a href={LINKS.app} className={cn(buttonVariants({ size: 'sm' }), 'hidden sm:inline-flex')}>
            {text.openApp}
          </a>
          {/* Below lg the section links move into a disclosure that works without JavaScript; at 768 px the
              full row did not fit with some system fonts (CI). */}
          <details className="group relative lg:hidden">
            <summary
              className={cn(
                buttonVariants({ variant: 'outline', size: 'icon-sm' }),
                'list-none [&::-webkit-details-marker]:hidden',
              )}
            >
              <MenuIcon aria-hidden="true" />
              <span className="sr-only">{text.menu}</span>
            </summary>
            <div className="absolute end-0 top-full mt-2 w-60 rounded-modal border border-border bg-surface-elevated p-2 shadow-3">
              <ul className="flex flex-col">
                {sections.map((item) => (
                  <li key={item.href}>
                    <a href={item.href} className="block rounded-control px-3 py-2 text-sm hover:bg-muted">
                      {item.label}
                    </a>
                  </li>
                ))}
              </ul>
              <div className="mt-2 flex flex-col gap-2 border-border border-t pt-2">
                <a href={LINKS.app} className={buttonVariants({ variant: 'outline', size: 'sm' })}>
                  {text.signIn}
                </a>
                <a href={LINKS.app} className={buttonVariants({ size: 'sm' })}>
                  {text.openApp}
                </a>
              </div>
            </div>
          </details>
        </div>
      </nav>
    </header>
  )
}

/** Toggles light and dark; `client.ts` wires it up and hides it until then (it does nothing without JS). */
function ThemeButton() {
  return (
    <button
      type="button"
      data-theme-toggle
      hidden
      aria-label={landing.nav.theme}
      className={buttonVariants({ variant: 'ghost', size: 'icon-sm' })}
    >
      <SunIcon aria-hidden="true" className="hidden dark:block" />
      <MoonIcon aria-hidden="true" className="dark:hidden" />
    </button>
  )
}

/* ---------- Hero (Tailark Mist hero-section one) ---------- */

/** Intrinsic sizes of the images in `landing/public/images` (1280 px wide, plus a larger one for dense screens). */
const SHOTS = {
  queue: { height: 800, large: 2560 },
  ticket: { height: 689, large: 2434 },
} as const

function ProductShot({
  name,
  alt,
  eager = false,
  sizes = '(min-width: 1152px) 1104px, calc(100vw - 2rem)',
}: {
  name: keyof typeof SHOTS
  alt: string
  eager?: boolean
  sizes?: string
}) {
  const { height, large } = SHOTS[name]
  const srcSet = (theme: 'light' | 'dark') =>
    `/images/${name}-${theme}-1280.webp 1280w, /images/${name}-${theme}-2560.webp ${large}w`

  return (
    <div className="overflow-hidden rounded-modal border border-border bg-surface shadow-3 ring-1 ring-foreground/5">
      <div
        aria-hidden="true"
        className="flex items-center gap-1.5 border-border border-b bg-muted px-4 py-2.5"
      >
        <span className="size-2.5 rounded-full bg-border" />
        <span className="size-2.5 rounded-full bg-border" />
        <span className="size-2.5 rounded-full bg-border" />
      </div>
      {/* One picture per theme; the other stays unloaded until it is shown (loading="lazy" on a hidden image). */}
      <img
        src={`/images/${name}-light-1280.webp`}
        srcSet={srcSet('light')}
        sizes={sizes}
        width={1280}
        height={height}
        alt={alt}
        loading={eager ? 'eager' : 'lazy'}
        fetchPriority={eager ? 'high' : undefined}
        decoding="async"
        className="block h-auto w-full dark:hidden"
      />
      <img
        src={`/images/${name}-dark-1280.webp`}
        srcSet={srcSet('dark')}
        sizes={sizes}
        width={1280}
        height={height}
        alt={alt}
        loading="lazy"
        decoding="async"
        className="hidden h-auto w-full dark:block"
      />
    </div>
  )
}

function Hero() {
  const text = landing.hero

  return (
    <section aria-labelledby="hero-title" className="relative overflow-hidden">
      <div aria-hidden="true" className="absolute inset-0 bg-brand-glow" />
      <div aria-hidden="true" className="absolute inset-x-0 top-0 h-[36rem] bg-brand-grid" />
      <div className={cn(CONTAINER, 'relative pt-16 pb-20 sm:pt-24 md:pb-28')}>
        <div className="mx-auto max-w-3xl text-center motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-2 motion-safe:duration-700">
          <p className="inline-flex items-center gap-2 rounded-full border border-border bg-surface px-3 py-1 font-medium text-muted-foreground text-sm shadow-1">
            <span className="size-1.5 rounded-full bg-primary" aria-hidden="true" />
            {text.eyebrow}
          </p>
          <h1
            id="hero-title"
            className="mt-6 text-balance font-semibold text-4xl tracking-tight sm:text-5xl md:text-6xl md:leading-[1.05]"
          >
            {text.title}
          </h1>
          <p className="mx-auto mt-6 max-w-2xl text-pretty text-lg text-muted-foreground">{text.body}</p>
          <div className="mt-8 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
            <a href={LINKS.app} className={cn(buttonVariants({ size: 'lg' }), 'h-11 px-6 text-base')}>
              {text.primary}
              <ArrowRightIcon aria-hidden="true" />
            </a>
            <a
              href={LINKS.find}
              className={cn(buttonVariants({ variant: 'outline', size: 'lg' }), 'h-11 px-6 text-base')}
            >
              {text.secondary}
            </a>
          </div>
        </div>

        <div className="relative mx-auto mt-16 max-w-5xl motion-safe:animate-in motion-safe:fade-in motion-safe:slide-in-from-bottom-4 motion-safe:delay-150 motion-safe:duration-1000 motion-safe:fill-mode-both">
          <ProductShot name="queue" alt={text.imageAlt} eager />
        </div>
      </div>
    </section>
  )
}

/* ---------- Channels ---------- */

const CHANNEL_ICONS = [GlobeIcon, MailIcon, CodeIcon, WebhookIcon]

function Channels() {
  const text = landing.channels
  return (
    <section aria-labelledby="channels-title" className="border-border border-y bg-surface">
      <div className={cn(CONTAINER, 'py-12')}>
        <h2 id="channels-title" className="text-center font-medium text-muted-foreground text-sm">
          {text.heading}
        </h2>
        <ul className="mt-8 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          {text.items.map((item, index) => {
            const Icon = CHANNEL_ICONS[index] ?? GlobeIcon
            return (
              <li key={item.title} className="flex gap-3">
                <span className="grid size-9 shrink-0 place-items-center rounded-control bg-primary/10 text-primary">
                  <Icon aria-hidden="true" className="size-4" />
                </span>
                <span>
                  <span className="block font-medium text-base">{item.title}</span>
                  <span className="mt-1 block text-muted-foreground text-sm">{item.body}</span>
                </span>
              </li>
            )
          })}
        </ul>
      </div>
    </section>
  )
}

/* ---------- Features (Tailark Mist features four: cards with illustrations) ---------- */

function SectionIntro({
  id,
  eyebrow,
  title,
  body,
}: {
  id: string
  eyebrow?: string
  title: string
  body?: string
}) {
  return (
    <div className="max-w-2xl">
      {eyebrow ? <p className="font-medium text-primary text-sm">{eyebrow}</p> : null}
      <h2 id={id} className="mt-2 text-balance font-semibold text-3xl tracking-tight md:text-4xl">
        {title}
      </h2>
      {body ? <p className="mt-4 text-pretty text-lg text-muted-foreground">{body}</p> : null}
    </div>
  )
}

function FeatureCard({ title, body, children }: { title: string; body: string; children: ReactNode }) {
  return (
    <article className="flex flex-col overflow-hidden rounded-modal border border-border bg-surface shadow-1">
      {/* The illustration repeats the text below it with example values, so it is not read out twice. */}
      <div aria-hidden="true" className="relative border-border border-b bg-muted/60 p-6">
        {children}
      </div>
      <div className="flex flex-1 flex-col gap-2 p-6">
        <h3 className="font-semibold text-xl">{title}</h3>
        <p className="text-pretty text-base text-muted-foreground">{body}</p>
      </div>
    </article>
  )
}

function Features() {
  const text = landing.features
  return (
    <section id="features" aria-labelledby="features-title" className="scroll-mt-20 py-20 md:py-28">
      <div className={CONTAINER}>
        <SectionIntro id="features-title" eyebrow={text.eyebrow} title={text.heading} body={text.body} />
        <div className="mt-12 grid gap-6 md:grid-cols-2">
          <FeatureCard title={text.priority.title} body={text.priority.body}>
            <PriorityIllustration />
          </FeatureCard>
          <FeatureCard title={text.assignment.title} body={text.assignment.body}>
            <AssignmentIllustration />
          </FeatureCard>
          <FeatureCard title={text.duplicates.title} body={text.duplicates.body}>
            <DuplicateIllustration />
          </FeatureCard>
          <FeatureCard title={text.sla.title} body={text.sla.body}>
            <SlaIllustration />
          </FeatureCard>
        </div>
      </div>
    </section>
  )
}

function PriorityIllustration() {
  const text = landing.features.priority
  // Bars are drawn against the largest possible share of any one factor (impact, 40 points).
  return (
    <div className="rounded-card border border-border bg-surface p-4 shadow-2">
      <ul className="flex flex-col gap-3">
        {text.rows.map((row) => (
          <li key={row.label} className="grid grid-cols-[8.5rem_1fr_2.5rem] items-center gap-3 text-sm">
            <span className="truncate text-muted-foreground">{row.label}</span>
            <span className="h-2 overflow-hidden rounded-full bg-muted">
              <span
                className="block h-full rounded-full bg-primary"
                style={{ width: `${(row.value / 40) * 100}%` }}
              />
            </span>
            <span className="text-end font-mono tabular-nums">{row.value.toFixed(1)}</span>
          </li>
        ))}
      </ul>
      <div className="mt-4 flex items-center justify-between border-border border-t pt-3 text-sm">
        <span className="font-medium">{text.total}</span>
        <span className="rounded-badge bg-priority-p2 px-2 py-0.5 font-medium text-priority-p2-foreground text-xs">
          {text.level}
        </span>
      </div>
    </div>
  )
}

function AssignmentIllustration() {
  const text = landing.features.assignment
  return (
    <ul className="flex flex-col gap-2">
      {text.rows.map((row) => {
        const excluded = 'excluded' in row && row.excluded
        return (
          <li
            key={row.name}
            className={cn(
              'flex items-center gap-3 rounded-card border bg-surface px-3 py-2.5 text-sm shadow-1',
              row.chosen ? 'border-primary/50 ring-1 ring-primary/30' : 'border-border',
              // Left out of the ranking: drawn as an outline rather than dimmed, which would cost contrast.
              excluded && 'border-dashed bg-muted/40 shadow-none',
            )}
          >
            <span className="grid size-8 shrink-0 place-items-center rounded-full bg-muted font-medium text-xs">
              {row.name
                .split(' ')
                .map((part) => part[0])
                .join('')}
            </span>
            <span className="flex min-w-0 flex-1 flex-col">
              <span className="truncate font-medium">{row.name}</span>
              <span className="truncate text-muted-foreground text-xs">{row.detail}</span>
            </span>
            {row.chosen ? (
              <span className="inline-flex items-center gap-1 rounded-badge bg-success px-2 py-0.5 font-medium text-success-foreground text-xs">
                <CheckIcon className="size-3" />
                {text.chosen}
              </span>
            ) : excluded ? (
              <span className="text-muted-foreground text-xs">{text.excluded}</span>
            ) : null}
          </li>
        )
      })}
    </ul>
  )
}

function DuplicateIllustration() {
  const text = landing.features.duplicates
  const shared = new Set<string>(text.shared)
  const words = (title: string) =>
    // The example titles repeat no word, so a word is its own key.
    title.split(' ').map((word) => (
      <span
        key={word}
        className={cn(
          shared.has(word.toLowerCase().replace(/[^a-z]/g, '')) && 'rounded-xs bg-primary/15 font-medium',
        )}
      >
        {word}{' '}
      </span>
    ))

  return (
    <div className="flex flex-col gap-3">
      <div className="rounded-card border border-border bg-surface px-4 py-3 text-sm shadow-1">
        {words(text.newTicket)}
      </div>
      <div className="rounded-card border border-primary/40 border-dashed bg-surface px-4 py-3 text-sm shadow-2">
        <div className="mb-1.5 flex items-center justify-between text-muted-foreground text-xs">
          <span>{text.similarity}</span>
          <span className="flex gap-1">
            {text.shared.map((word) => (
              <span key={word} className="rounded-badge bg-muted px-1.5 py-0.5 font-mono">
                {word}
              </span>
            ))}
          </span>
        </div>
        {words(text.match)}
      </div>
    </div>
  )
}

function SlaIllustration() {
  const text = landing.features.sla
  return (
    <div className="rounded-card border border-border bg-surface p-4 shadow-2">
      <div className="flex h-3 overflow-hidden rounded-full bg-muted">
        <span className="h-full w-[38%] bg-sla-ok" />
        <span className="h-full w-[17%] bg-sla-paused/60 [background-image:repeating-linear-gradient(45deg,transparent,transparent_4px,var(--surface)_4px,var(--surface)_6px)]" />
        <span className="h-full w-[20%] bg-sla-ok" />
        <span className="h-full w-[12%] bg-sla-warning" />
      </div>
      <div className="relative mt-2 h-4 text-muted-foreground text-xs">
        <span className="absolute start-[75%] -translate-x-1/2 whitespace-nowrap">{text.warning}</span>
        <span className="absolute end-0">{text.due}</span>
      </div>
      <ul className="mt-4 flex flex-col gap-2 text-sm">
        <li className="flex items-center gap-2">
          <span className="size-2.5 rounded-full bg-sla-ok" />
          {text.running}
        </li>
        <li className="flex items-center gap-2">
          <span className="size-2.5 rounded-full bg-sla-paused" />
          {text.paused}
        </li>
        <li className="flex items-center gap-2">
          <span className="size-2.5 rounded-full bg-sla-warning" />
          {text.warning}
        </li>
      </ul>
    </div>
  )
}

/* ---------- Showcase ---------- */

function Showcase() {
  const text = landing.showcase
  return (
    <section aria-labelledby="showcase-title" className="border-border border-y bg-muted/60 py-20 md:py-28">
      <div className={cn(CONTAINER, 'grid items-center gap-12 lg:grid-cols-[2fr_3fr]')}>
        <SectionIntro id="showcase-title" title={text.heading} body={text.body} />
        <ProductShot name="ticket" alt={text.imageAlt} sizes="(min-width: 1024px) 60vw, calc(100vw - 2rem)" />
      </div>
    </section>
  )
}

/* ---------- More features (Tailark Mist features two: icon grid) ---------- */

const MORE_ICONS = [FolderLockIcon, BarChart3Icon, UsersIcon, ImageIcon, ScrollTextIcon, KeyboardIcon]

function MoreFeatures() {
  const text = landing.more
  return (
    <section aria-labelledby="more-title" className="py-20 md:py-28">
      <div className={CONTAINER}>
        <SectionIntro id="more-title" title={text.heading} />
        <ul className="mt-12 grid gap-x-8 gap-y-10 sm:grid-cols-2 lg:grid-cols-3">
          {text.items.map((item, index) => {
            const Icon = MORE_ICONS[index] ?? ShieldCheckIcon
            return (
              <li key={item.title}>
                <Icon aria-hidden="true" className="size-5 text-primary" />
                <h3 className="mt-3 font-semibold text-base">{item.title}</h3>
                <p className="mt-1.5 text-pretty text-muted-foreground text-sm">{item.body}</p>
              </li>
            )
          })}
        </ul>
      </div>
    </section>
  )
}

/* ---------- How it works ---------- */

function HowItWorks() {
  const text = landing.how
  return (
    <section
      id="how-it-works"
      aria-labelledby="how-title"
      className="scroll-mt-20 border-border border-y bg-surface py-20 md:py-28"
    >
      <div className={CONTAINER}>
        <SectionIntro id="how-title" title={text.heading} />
        <ol className="mt-12 grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          {text.steps.map((step, index) => (
            <li key={step.title} className="relative rounded-modal border border-border bg-background p-6">
              <span className="font-mono font-semibold text-primary text-sm">0{index + 1}</span>
              <h3 className="mt-3 font-semibold text-lg">{step.title}</h3>
              <p className="mt-2 text-pretty text-muted-foreground text-sm">{step.body}</p>
            </li>
          ))}
        </ol>
      </div>
    </section>
  )
}

/* ---------- FAQ (Tailark Mist faqs two, as native disclosure widgets) ---------- */

function Faq() {
  const text = landing.faq
  return (
    <section id="faq" aria-labelledby="faq-title" className="scroll-mt-20 py-20 md:py-28">
      <div className={cn(CONTAINER, 'grid gap-10 md:grid-cols-5 md:gap-12')}>
        <div className="md:col-span-2">
          <h2 id="faq-title" className="font-semibold text-3xl tracking-tight md:text-4xl">
            {text.heading}
          </h2>
          <p className="mt-4 text-lg text-muted-foreground">{text.body}</p>
        </div>
        <div className="divide-y divide-border border-border border-y md:col-span-3">
          {text.items.map((item) => (
            <details key={item.question} className="group">
              <summary className="flex cursor-pointer list-none items-center justify-between gap-4 py-5 font-medium text-base [&::-webkit-details-marker]:hidden">
                {item.question}
                <ChevronDownIcon
                  aria-hidden="true"
                  className="size-4 shrink-0 text-muted-foreground transition-transform group-open:rotate-180"
                />
              </summary>
              <p className="pb-5 text-pretty text-base text-muted-foreground">{item.answer}</p>
            </details>
          ))}
        </div>
      </div>
    </section>
  )
}

/* ---------- Call to action (Tailark Mist call-to-action one) ---------- */

function CallToAction() {
  const text = landing.cta
  return (
    <section aria-labelledby="cta-title" className="pb-20 md:pb-28">
      <div className={CONTAINER}>
        <div className="relative overflow-hidden rounded-modal border border-border bg-muted px-6 py-16 text-center">
          <div aria-hidden="true" className="absolute inset-0 bg-brand-glow" />
          <div className="relative">
            <h2 id="cta-title" className="text-balance font-semibold text-3xl tracking-tight md:text-4xl">
              {text.heading}
            </h2>
            <p className="mt-4 text-lg text-muted-foreground">{text.body}</p>
            <div className="mt-8 flex flex-col items-stretch justify-center gap-3 sm:flex-row sm:items-center">
              <a href={LINKS.app} className={cn(buttonVariants({ size: 'lg' }), 'h-11 px-6 text-base')}>
                {text.primary}
                <ArrowRightIcon aria-hidden="true" />
              </a>
              <a
                href={LINKS.find}
                className={cn(
                  buttonVariants({ variant: 'outline', size: 'lg' }),
                  'h-11 bg-surface px-6 text-base',
                )}
              >
                {text.secondary}
              </a>
            </div>
          </div>
        </div>
      </div>
    </section>
  )
}

/* ---------- Footer (Tailark Mist footer two) ---------- */

function Footer() {
  const text = landing.footer
  const groups = [
    {
      title: text.product,
      links: [
        { href: '#features', label: landing.nav.features },
        { href: '#how-it-works', label: landing.nav.how },
        { href: '#faq', label: landing.nav.faq },
      ],
    },
    {
      title: text.developers,
      links: [
        { href: LINKS.docs, label: `${text.apiReference} (${text.apiReferenceNote})`, icon: BookOpenIcon },
      ],
    },
    {
      title: text.account,
      links: [
        { href: LINKS.app, label: landing.nav.signIn },
        { href: LINKS.find, label: landing.hero.secondary },
      ],
    },
  ]

  return (
    <footer className="border-border border-t bg-surface">
      <div className={cn(CONTAINER, 'grid gap-10 py-14 md:grid-cols-5')}>
        <div className="md:col-span-2">
          <BrandMark />
          <p className="mt-3 max-w-xs text-muted-foreground text-sm">{text.tagline}</p>
        </div>
        {groups.map((group) => (
          <div key={group.title}>
            <h2 className="font-medium text-sm">{group.title}</h2>
            <ul className="mt-3 flex flex-col gap-2 text-sm">
              {group.links.map((link) => (
                <li key={link.label}>
                  <a href={link.href} className="text-muted-foreground hover:text-foreground">
                    {link.label}
                  </a>
                </li>
              ))}
            </ul>
          </div>
        ))}
      </div>
      <div className={CONTAINER}>
        <div className="flex flex-wrap items-center justify-between gap-3 border-border border-t py-6 text-muted-foreground text-xs">
          <span>
            © <span data-year>2026</span> {text.rights}
          </span>
          <a href={LINKS.licences} className="hover:text-foreground">
            {text.licences}
          </a>
        </div>
      </div>
    </footer>
  )
}
