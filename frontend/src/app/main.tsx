import { QueryClientProvider } from '@tanstack/react-query'
import { RouterProvider } from '@tanstack/react-router'
import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { copy } from '@/copy/en'
import { initApiClient } from '@/lib/api/client'
import { loadRuntimeConfig } from '@/lib/config'
import { ThemeProvider } from '@/lib/theme'
import '@/styles/globals.css'
import { createQueryClient } from './query-client'
import { createAppRouter } from './router'

async function bootstrap(): Promise<void> {
  const rootElement = document.getElementById('root')
  if (!rootElement) {
    throw new Error('Missing #root element')
  }
  const root = createRoot(rootElement)

  try {
    const config = await loadRuntimeConfig()
    initApiClient(config)
    const queryClient = createQueryClient()
    const router = createAppRouter({ queryClient, config })

    root.render(
      <StrictMode>
        <ThemeProvider>
          <QueryClientProvider client={queryClient}>
            <RouterProvider router={router} />
          </QueryClientProvider>
        </ThemeProvider>
      </StrictMode>,
    )
  } catch (error) {
    console.error(error)
    root.render(<p role="alert">{copy.errors.configMissing}</p>)
  }
}

void bootstrap()
