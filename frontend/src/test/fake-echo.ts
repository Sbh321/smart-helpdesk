import type { ConnectionStatus } from 'laravel-echo'
import { overrideEchoOptionsForTests } from '@/lib/realtime'

type Listener = (payload: unknown) => void

/** A private channel as Echo sees it: listeners per event name, no network. */
export class FakeChannel {
  readonly listeners = new Map<string, Set<Listener>>()
  subscribed = true

  constructor(readonly name: string) {}

  listen(event: string, callback: Listener): this {
    const set = this.listeners.get(event) ?? new Set<Listener>()
    set.add(callback)
    this.listeners.set(event, set)
    return this
  }

  stopListening(event: string, callback?: Listener): this {
    if (callback) this.listeners.get(event)?.delete(callback)
    else this.listeners.delete(event)
    return this
  }

  unsubscribe(): void {
    this.subscribed = false
    this.listeners.clear()
  }
}

/**
 * Stands in for Echo's Pusher connector in browser tests: `emit` delivers a broadcast, `setStatus`
 * moves the connection (connected, connecting, failed …). The latest instance is `FakeConnector.current`.
 */
export class FakeConnector {
  static current: FakeConnector | null = null

  channels: Record<string, FakeChannel> = {}
  private status: ConnectionStatus = 'connected'
  private readonly statusListeners = new Set<(status: ConnectionStatus) => void>()

  constructor(readonly options: unknown) {
    FakeConnector.current = this
  }

  channel(name: string): FakeChannel {
    this.channels[name] ??= new FakeChannel(name)
    return this.channels[name]
  }

  privateChannel(name: string): FakeChannel {
    return this.channel(`private-${name}`)
  }

  presenceChannel(name: string): FakeChannel {
    return this.channel(`presence-${name}`)
  }

  leave(name: string): void {
    for (const variant of [name, `private-${name}`, `presence-${name}`]) this.leaveChannel(variant)
  }

  leaveChannel(name: string): void {
    this.channels[name]?.unsubscribe()
    delete this.channels[name]
  }

  socketId(): string {
    return '1234.5678'
  }

  connectionStatus(): ConnectionStatus {
    return this.status
  }

  onConnectionChange(callback: (status: ConnectionStatus) => void): () => void {
    this.statusListeners.add(callback)
    return () => this.statusListeners.delete(callback)
  }

  disconnect(): void {
    this.status = 'disconnected'
  }

  /** Test API: the server broadcast `event` (with its leading dot) on a private channel. */
  emit(channel: string, event: string, payload: unknown = {}): void {
    for (const listener of this.channels[`private-${channel}`]?.listeners.get(event) ?? []) listener(payload)
  }

  setStatus(status: ConnectionStatus): void {
    this.status = status
    for (const listener of this.statusListeners) listener(status)
  }

  /** Names of the private channels currently joined, without the `private-` prefix. */
  joined(): string[] {
    return Object.keys(this.channels).map((name) => name.replace(/^private-/, ''))
  }
}

/** Runtime config with Reverb on, for `renderApp(path, { config: realtimeConfig })`. */
export const realtimeConfig = { realtime: { enabled: true, key: 'test-key', host: 'api.shp.test' } }

/** Routes Echo to {@link FakeConnector}; call in `beforeEach`, undo with `restoreEcho`. */
export function useFakeEcho(): void {
  FakeConnector.current = null
  overrideEchoOptionsForTests({ broadcaster: FakeConnector as never })
}

export function restoreEcho(): void {
  overrideEchoOptionsForTests(null)
  FakeConnector.current = null
}

export function fakeEcho(): FakeConnector {
  if (!FakeConnector.current) throw new Error('Echo has not connected')
  return FakeConnector.current
}
