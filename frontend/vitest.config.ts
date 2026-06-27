import { fileURLToPath } from 'node:url'
import { mergeConfig, defineConfig, configDefaults } from 'vitest/config'
import viteConfig from './vite.config'

// vite.config now exports a function (mode-aware); resolve it for vitest under
// the implicit "serve" command so the Vue plugin chain is loaded as expected.
const resolvedViteConfig =
  typeof viteConfig === 'function'
    ? viteConfig({ command: 'serve', mode: 'test' })
    : viteConfig

export default mergeConfig(
  resolvedViteConfig,
  defineConfig({
    test: {
      environment: 'jsdom',
      exclude: [...configDefaults.exclude, 'e2e/**'],
      root: fileURLToPath(new URL('./', import.meta.url)),
    },
    base: '/lokato-platform/',
  }),
)
