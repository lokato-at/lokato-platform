import { fileURLToPath } from 'node:url'
import { mergeConfig, defineConfig, configDefaults } from 'vitest/config'
import viteConfig from './vite.config'

// command="build" laedt vite.config OHNE vue-devtools, sonst scheitert das Plugin
// am Asset-Transform von root-relativen Pfaden (`/branding/...`).
const resolvedViteConfig =
  typeof viteConfig === 'function'
    ? viteConfig({ command: 'build', mode: 'test' })
    : viteConfig

export default mergeConfig(
  resolvedViteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      // JSDOM-URL setzen, sonst werden root-relative Asset-Pfade als file:///... aufgeloest.
      environmentOptions: {
        jsdom: {
          url: 'http://localhost/',
        },
      },
      exclude: [...configDefaults.exclude, 'e2e/**'],
      root: fileURLToPath(new URL('./', import.meta.url)),
    },
    base: '/lokato-platform/',
  }),
)
