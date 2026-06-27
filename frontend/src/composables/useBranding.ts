import { ref, type Ref } from 'vue'

export interface BrandingAnimations {
  files: string[]
  cooldownSeconds: number
  playWithSound: boolean
}

export interface BrandingConfig {
  facilityName: string
  facilityShortName: string
  tagline: string
  primaryColor: string
  animations: BrandingAnimations
}

const DEFAULT_BRANDING: BrandingConfig = {
  facilityName: '',
  facilityShortName: '',
  tagline: '',
  primaryColor: '#2A7CD9',
  animations: {
    files: [],
    cooldownSeconds: 10,
    playWithSound: true,
  },
}

const config: Ref<BrandingConfig> = ref({ ...DEFAULT_BRANDING })
let loadPromise: Promise<void> | null = null

async function load(): Promise<void> {
  try {
    const response = await fetch('/branding/config.json', { cache: 'no-cache' })
    if (!response.ok) return
    const data = (await response.json()) as Partial<BrandingConfig>
    config.value = {
      ...DEFAULT_BRANDING,
      ...data,
      animations: {
        ...DEFAULT_BRANDING.animations,
        ...(data.animations ?? {}),
      },
    }
  } catch {
    // Branding is optional — keep defaults silently.
  }
}

/**
 * Returns the reactive branding config plus a one-shot loader.
 * The loader is fired exactly once across the whole app; subsequent calls
 * await the same promise so concurrent components don't all re-fetch.
 */
export function useBranding() {
  if (!loadPromise) {
    loadPromise = load()
  }
  return { config, ready: loadPromise }
}
