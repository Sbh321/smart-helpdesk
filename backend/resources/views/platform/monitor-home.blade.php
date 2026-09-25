<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light dark">
<title>Monitoring · {{ $app }}</title>
<link rel="icon" href="/favicon.ico" sizes="48x48">
<link rel="icon" href="/favicon.svg" type="image/svg+xml">
<link rel="apple-touch-icon" href="/apple-touch-icon.png">
<style>
  /* Colours of the application's tokens (frontend/src/styles/tokens.css), light and dark. */
  :root { --bg: #f9fafb; --surface: #fff; --fg: #0b0d10; --muted: #5b616b; --border: #e3e5e8; --primary: #1d63d8; }
  @media (prefers-color-scheme: dark) { :root { --bg: #0b0d10; --surface: #1a1d21; --fg: #f5f6f7; --muted: #a3a8b0; --border: #2e3238; --primary: #7fb0ff; } }
  * { box-sizing: border-box; }
  body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--fg); }
  main { max-width: 56rem; margin: 0 auto; padding: 3rem 1.5rem; }
  h1 { margin: 0; font-size: 1.75rem; letter-spacing: -0.01em; display: flex; align-items: center; gap: .75rem; }
  h1 img { width: 2rem; height: 2rem; }
  p.lead { margin: .5rem 0 2rem; color: var(--muted); }
  ul.tools { list-style: none; padding: 0; margin: 0; display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(16rem, 1fr)); }
  ul.tools a { display: block; height: 100%; padding: 1.25rem; border: 1px solid var(--border); border-radius: .75rem; background: var(--surface); color: inherit; text-decoration: none; }
  ul.tools a:hover { border-color: var(--primary); }
  ul.tools a:focus-visible { outline: 2px solid var(--primary); outline-offset: 2px; }
  ul.tools strong { display: block; font-size: 1.05rem; color: var(--primary); }
  ul.tools span { display: block; margin-top: .35rem; font-size: .9rem; color: var(--muted); }
  nav { margin-top: 2.5rem; font-size: .9rem; color: var(--muted); }
  nav a { color: var(--primary); margin-right: 1.25rem; }
</style>
</head>
<body>
<main>
  <h1><img src="/favicon.svg" alt="">Monitoring</h1>
  <p class="lead">{{ $app }} operations tools for platform administrators.</p>
  <ul class="tools">
    @foreach ($tools as [$name, $href, $description])
      <li><a href="{{ $href }}"><strong>{{ $name }}</strong><span>{{ $description }}</span></a></li>
    @endforeach
  </ul>
  <nav aria-label="Platform">
    @foreach ($links as [$name, $href])
      <a href="{{ $href }}">{{ $name }}</a>
    @endforeach
  </nav>
</main>
</body>
</html>
