import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { SafeCommentMarkdown } from './safe-comment-markdown'

/** The HTML a comment body turns into. Everything a user typed must arrive as text or allow-listed tags. */
function render(body: string): string {
  return renderToStaticMarkup(createElement(SafeCommentMarkdown, { body }))
}

/** Tags and attributes that survive in the output, which is what a browser would act on. */
function tags(html: string): string[] {
  return [...html.matchAll(/<([a-z0-9]+)(\s[^>]*)?>/gi)].map((match) => match[0])
}

const ALLOWED_TAG = /^<(div|p|span|br|ul|li|strong|code|a)[\s/>]/

describe('SafeCommentMarkdown', () => {
  it('renders the allow-list: paragraphs, lists, bold, code and https links', () => {
    const html = render('Hello **bold** and `code`\n\n- one\n- two\n\n[docs](https://example.test/a)')
    expect(html).toContain('<strong>bold</strong>')
    expect(html).toContain('>code</code>')
    expect(html).toContain('<li>one</li>')
    expect(html).toContain('<a href="https://example.test/a" target="_blank" rel="noopener noreferrer"')
  })

  it('keeps script tags as inert text', () => {
    const html = render('<script>alert(1)</script>\n\n**<script>alert(2)</script>**')
    expect(html).not.toMatch(/<script/i)
    expect(html).toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
    expect(html).toContain('<strong>&lt;script&gt;alert(2)&lt;/script&gt;</strong>')
  })

  it('never produces an event-handler attribute', () => {
    const html = render(
      [
        '<img src=x onerror=alert(1)>',
        '<a href="https://ok.test" onclick="alert(1)">x</a>',
        '<svg onload=alert(1)>',
        '[x](https://ok.test" onmouseover="alert(1))',
        '`<b onfocus=alert(1) autofocus>`',
      ].join('\n\n'),
    )
    for (const tag of tags(html)) {
      expect(tag).toMatch(ALLOWED_TAG)
      expect(tag).not.toMatch(/\son[a-z]+\s*=/i)
    }
    expect(html).not.toMatch(/<(img|svg|b)\b/i)
  })

  it('does not link javascript:, data:, vbscript:, http: or protocol-relative targets', () => {
    const bodies = [
      '[click](javascript:alert(1))',
      '[click](JaVaScRiPt:alert(1))',
      '[click]( javascript:alert(1))',
      '[click](data:text/html,<script>alert(1)</script>)',
      '[click](vbscript:msgbox(1))',
      '[click](http://plain.test)',
      '[click](//evil.test)',
      '[click](https:javascript:alert(1))',
      '[click](java\tscript:alert(1))',
    ]
    for (const body of bodies) {
      const html = render(body)
      expect(html, body).not.toContain('<a ')
      expect(html, body).not.toMatch(/href=/i)
    }
  })

  it('links only https URLs, with a safe rel, and cannot break out of the href', () => {
    const html = render('[a](https://ok.test/?q="><script>alert(1)</script>)')
    const anchors = tags(html).filter((tag) => tag.startsWith('<a'))
    for (const anchor of anchors) {
      expect(anchor).toMatch(/href="https:\/\/[^"]*"/)
      expect(anchor).toContain('rel="noopener noreferrer"')
    }
    expect(html).not.toMatch(/<script/i)
  })

  it('treats HTML entities and markdown images as text', () => {
    const html = render('&lt;script&gt; ![x](https://ok.test/x.png) <iframe src="https://evil.test">')
    expect(html).not.toMatch(/<(img|iframe)\b/i)
    expect(html).toContain('&amp;lt;script&amp;gt;')
  })
})
