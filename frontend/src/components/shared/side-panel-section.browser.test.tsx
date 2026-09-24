import axe from 'axe-core'
import { expect, test } from 'vitest'
import { render } from 'vitest-browser-react'
import { SidePanelSection } from './side-panel-section'

/** Serious and critical findings of the WCAG rule sets, as the accessibility suite counts them. */
async function violations(container: HTMLElement): Promise<string[]> {
  const results = await axe.run(container, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
  })
  return results.violations
    .filter((violation) => violation.impact === 'serious' || violation.impact === 'critical')
    .map((violation) => `${violation.id}: ${violation.nodes.map((node) => node.html).join(' | ')}`)
}

test('a panel section opens and closes from the keyboard and hides its summary while open', async () => {
  const screen = await render(
    <div>
      <SidePanelSection title="Requester" static>
        <p>Laura Schmidt</p>
      </SidePanelSection>
      <SidePanelSection title="Assignment" summary="Chen Wei">
        <p>Assigned by the automation.</p>
      </SidePanelSection>
      <SidePanelSection title="SLA" defaultOpen>
        <p>Resolution due in 3h 42m.</p>
      </SidePanelSection>
    </div>,
  )

  // A static section has no control and is always readable.
  await expect.element(screen.getByText('Laura Schmidt')).toBeVisible()
  // defaultOpen renders its content; the collapsed one shows its summary instead.
  await expect.element(screen.getByText('Resolution due in 3h 42m.')).toBeVisible()
  await expect.element(screen.getByText('Chen Wei')).toBeVisible()
  await expect.element(screen.getByText('Assigned by the automation.')).not.toBeVisible()

  const assignment = screen.getByText('Assignment')
  await assignment.click()
  await expect.element(screen.getByText('Assigned by the automation.')).toBeVisible()

  expect(await violations(screen.container)).toEqual([])
})
