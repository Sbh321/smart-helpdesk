import { describe, expect, test } from 'vitest'
import { copy } from '@/copy/en'
import type { AuditEntry } from './api/audit-queries'
import {
  actionLabel,
  auditActorLabel,
  auditChanges,
  auditSubjectLabel,
  auditValue,
  fieldLabel,
} from './audit-format'

const base: AuditEntry = {
  id: '019a0aaa-0000-7000-8000-000000000001',
  action: 'user.invited',
  actor_type: 'user',
  actor_id: '019a0aaa-0000-7000-8000-0000000000aa',
  actor_name: null,
  subject_type: 'user',
  subject_id: '019a0aaa-0000-7000-8000-0000000000bb',
  subject_name: null,
  changes: {},
  ip_address: null,
  user_agent: null,
  request_id: null,
  created_at: '2026-09-20T09:00:00.000000Z',
}

describe('auditChanges', () => {
  test('reads {field: {old, new}} as one change per field and keeps context as values', () => {
    expect(auditChanges({ level: { old: 'P3', new: 'P1' }, reason: 'VIP outage' })).toEqual([
      { field: 'level', kind: 'change', old: 'P3', new: 'P1' },
      { field: 'reason', kind: 'value', new: 'VIP outage' },
    ])
  })

  test('reads {old: {…}, new: {…}} per changed field, beside its context', () => {
    expect(
      auditChanges({
        section: 'tickets',
        old: { reopen_window_days: 7, auto_close_days: 7 },
        new: { reopen_window_days: 3, auto_close_days: 7 },
        version: 2,
      }),
    ).toEqual([
      { field: 'reopen_window_days', kind: 'change', old: 7, new: 3 },
      { field: 'section', kind: 'value', new: 'tickets' },
      { field: 'version', kind: 'value', new: 2 },
    ])
  })

  test('reads {before, after} lists as one value change', () => {
    expect(auditChanges({ before: ['tickets.view'], after: ['tickets.view', 'tickets.update'] })).toEqual([
      { field: '', kind: 'change', old: ['tickets.view'], new: ['tickets.view', 'tickets.update'] },
    ])
  })

  test('has no lines for an entry without details', () => {
    expect(auditChanges({})).toEqual([])
  })
})

describe('labels', () => {
  test('names known actions and humanises unknown ones', () => {
    expect(actionLabel('user.invited')).toBe(copy.audit.actions['user.invited'])
    expect(actionLabel('mail_sender.verified')).toBe('Mail sender · verified')
  })

  test('names the actor: you, a name, or the kind with a short id', () => {
    expect(auditActorLabel(base, base.actor_id ?? undefined)).toBe(copy.audit.actors.you)
    expect(auditActorLabel({ ...base, actor_name: 'Chen Wei' }, undefined)).toBe('Chen Wei')
    expect(auditActorLabel(base, undefined)).toBe('User …000000aa')
    expect(auditActorLabel({ ...base, actor_type: 'system', actor_id: null }, undefined)).toBe('System')
  })

  test('names the record, or its type with a short id', () => {
    expect(auditSubjectLabel({ ...base, subject_name: 'Asha Rai' })).toBe('Asha Rai')
    expect(auditSubjectLabel(base)).toBe('User …000000bb')
    expect(auditSubjectLabel({ ...base, subject_type: null, subject_id: null })).toBeNull()
  })

  test('formats values and field names', () => {
    expect(auditValue(['agent', 'manager'], 'UTC')).toBe('agent, manager')
    expect(auditValue(null, 'UTC')).toBe(copy.audit.changes.empty)
    expect(auditValue(true, 'UTC')).toBe(copy.entity360.values.yes)
    expect(auditValue('2026-09-20T09:00:00Z', 'Asia/Kathmandu')).toBe('20 Sep 2026, 14:45:00')
    expect(auditValue({ first_response_minutes: 10 }, 'UTC')).toBe('First response minutes: 10')
    expect(fieldLabel('calendar_id')).toBe('Calendar')
    expect(fieldLabel('')).toBe(copy.audit.changes.value)
  })
})
