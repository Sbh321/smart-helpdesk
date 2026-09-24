import { cn } from '@/lib/utils'

/**
 * A trend line without axes for a KPI tile (roadmap M4-08). Plain SVG: the tile states the value in
 * words, and the series has its own chart and table on the page, so the line is decoration and hidden
 * from assistive technology. Missing points (`null`, e.g. a week without resolved timers) break the line
 * instead of being drawn as zero.
 */
export function Sparkline({ values, className }: { values: readonly (number | null)[]; className?: string }) {
  const known = values.filter((value): value is number => value !== null)
  if (known.length < 2) return null
  const min = Math.min(...known)
  const max = Math.max(...known)
  const width = 100
  const height = 24
  const x = (index: number) => (values.length === 1 ? 0 : (index / (values.length - 1)) * width)
  const y = (value: number) =>
    max === min ? height / 2 : height - ((value - min) / (max - min)) * (height - 2) - 1

  // One path, with a "move" after every gap.
  let path = ''
  let drawing = false
  values.forEach((value, index) => {
    if (value === null) {
      drawing = false
      return
    }
    path += `${drawing ? 'L' : 'M'}${x(index).toFixed(2)} ${y(value).toFixed(2)} `
    drawing = true
  })

  return (
    <svg
      aria-hidden="true"
      data-slot="sparkline"
      viewBox={`0 0 ${width} ${height}`}
      preserveAspectRatio="none"
      className={cn('h-6 w-full text-primary', className)}
    >
      <path
        d={path.trim()}
        fill="none"
        stroke="currentColor"
        strokeWidth="1.5"
        vectorEffect="non-scaling-stroke"
      />
    </svg>
  )
}
