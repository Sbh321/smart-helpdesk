import { fileURLToPath, URL } from 'node:url'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

/**
 * The landing site on the apex host (M5-04): its own entry (`landing/index.html`), built into
 * `dist-landing/` and prerendered by `scripts/prerender-landing.mjs`; the proxy serves it from
 * `/srv/landing`. Shares the tokens, utilities and components of the application.
 */
export default defineConfig({
  root: fileURLToPath(new URL('./landing', import.meta.url)),
  publicDir: fileURLToPath(new URL('./landing/public', import.meta.url)),
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: { port: 5174, strictPort: true, fs: { allow: ['..'] } },
  build: {
    outDir: fileURLToPath(new URL('./dist-landing', import.meta.url)),
    emptyOutDir: true,
    target: 'es2023',
  },
})
