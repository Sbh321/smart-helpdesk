import { describe, expect, test } from 'vitest'
import { assignmentSentence } from './assignment-reason'

const names: Record<string, string> = { a: 'Chen Wei', b: 'Priya Shah' }
const agentName = (id: string) => names[id] ?? 'Unknown Agent'
const base = { outcome: 'assigned', ranking: [], excluded: [] }

describe('assignmentSentence (M4-06)', () => {
  test('an automatic choice names the load that decided it', () => {
    const sentence = assignmentSentence(
      { reason: 'auto', agent_id: 'a' },
      {
        ...base,
        ranking: [
          { rank: 1, agent_id: 'a', open_tickets: 1, capacity: 5 },
          { rank: 2, agent_id: 'b', open_tickets: 3, capacity: 5 },
        ],
      },
      agentName,
    )
    expect(sentence).toBe(
      'Chen Wei was picked automatically: the lowest load of 2 eligible Agents, with 1 of 5 tickets open.',
    )
  })

  test('a single eligible Agent is "the only eligible Agent", not "1 eligible Agents"', () => {
    const sentence = assignmentSentence(
      { reason: 'auto', agent_id: 'a' },
      { ...base, ranking: [{ rank: 1, agent_id: 'a', open_tickets: 10, capacity: 12 }] },
      agentName,
    )
    expect(sentence).toBe(
      'Chen Wei was picked automatically: the only eligible Agent, with 10 of 12 tickets open.',
    )
  })

  test('a manual choice says whether the strategy agreed', () => {
    const row = { reason: 'manual', agent_id: 'a' }
    expect(assignmentSentence(row, { ...base, recommended_agent_id: 'a' }, agentName)).toBe(
      'Chen Wei was chosen by hand; the strategy would have picked them too.',
    )
    expect(assignmentSentence(row, { ...base, recommended_agent_id: 'b' }, agentName)).toBe(
      'Chen Wei was chosen by hand; the strategy would have picked Priya Shah.',
    )
    expect(assignmentSentence(row, { ...base, recommended_agent_id: null }, agentName)).toBe(
      'Chen Wei was chosen by hand; no Agent was eligible for the strategy.',
    )
  })

  test('an override names the broken rule, with the missing skills', () => {
    const sentence = assignmentSentence(
      { reason: 'reassign', agent_id: 'a' },
      {
        ...base,
        manual_override: true,
        override_reason: 'missing_skill',
        excluded: [{ agent_id: 'a', reason: 'missing_skill', missing_skills: ['billing'] }],
      },
      agentName,
    )
    expect(sentence).toBe('Chen Wei was chosen by hand although not eligible: missing skills: billing.')
  })

  test('no eligible Agent and an unassignment have their own sentence', () => {
    expect(
      assignmentSentence(
        { reason: 'auto', agent_id: null },
        { ...base, outcome: 'no_eligible_agent' },
        agentName,
      ),
    ).toBe('Automatic assignment found no eligible Agent.')
    expect(
      assignmentSentence(
        { reason: 'unassign', agent_id: null },
        { ...base, outcome: 'unassigned' },
        agentName,
      ),
    ).toBe('The ticket was unassigned by hand.')
  })
})
