import type { ReactNode } from 'react'

const INLINE = /(\*\*[^*]+\*\*|`[^`]+`|\[[^\]]+\]\(https:\/\/[^\s)]+\))/g

/** Splits on a separator and numbers each part by a running position: a key that is stable and unique. */
function withOffsets(source: string, separator: RegExp | string): { text: string; offset: number }[] {
  const parts: { text: string; offset: number }[] = []
  let offset = 0
  for (const text of source.split(separator)) {
    parts.push({ text, offset })
    offset += text.length + 1
  }
  return parts
}

/** A deliberately small Markdown allow-list: paragraphs, lists, bold, code and HTTPS links. HTML remains text. */
function inline(source: string): ReactNode[] {
  return withOffsets(source, INLINE).map(({ text: piece, offset }) => {
    if (piece.startsWith('**') && piece.endsWith('**') && piece.length > 4)
      return <strong key={offset}>{piece.slice(2, -2)}</strong>
    if (piece.startsWith('`') && piece.endsWith('`') && piece.length > 2)
      return (
        <code key={offset} className="rounded bg-muted px-1">
          {piece.slice(1, -1)}
        </code>
      )
    const link = /^\[([^\]]+)\]\((https:\/\/[^\s)]+)\)$/.exec(piece)
    if (link?.[1] && link?.[2])
      return (
        <a key={offset} href={link[2]} target="_blank" rel="noopener noreferrer" className="underline">
          {link[1]}
        </a>
      )
    return piece
  })
}

export function SafeCommentMarkdown({ body }: { body: string }) {
  return (
    <div className="mt-2 space-y-2 wrap-break-word">
      {withOffsets(body, /\n{2,}/).map(({ text: block, offset }) => {
        const lines = withOffsets(block, '\n')
        if (lines.every((line) => line.text.startsWith('- '))) {
          return (
            <ul key={offset} className="list-inside list-disc">
              {lines.map((line) => (
                <li key={line.offset}>{inline(line.text.slice(2))}</li>
              ))}
            </ul>
          )
        }
        return (
          <p key={offset} className="whitespace-pre-wrap">
            {lines.map((line) => (
              <span key={line.offset}>
                {line.offset > 0 ? <br /> : null}
                {inline(line.text)}
              </span>
            ))}
          </p>
        )
      })}
    </div>
  )
}
