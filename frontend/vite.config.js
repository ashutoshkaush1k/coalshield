import { createHash } from 'node:crypto'
import { readFileSync, writeFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Phase 7B: the service worker, built from sw/sw.js with the list of files to keep offline - every
// emitted file except source maps and the .woff fonts (every browser that runs a service worker
// reads .woff2), plus the public files the app needs (manifest, icons).
const PUBLIC_FILES = ['favicon.svg', 'manifest.webmanifest', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/icon-maskable-512.png']

function serviceWorker() {
  // VITE_SW_SCOPE (the online build, .env.online: /field): the service worker and the installed app
  // cover the field app only, so the dashboard on the same address is never served from a cache.
  // Unset (the laptop): the whole site, as before.
  let scope = '/'
  let manifestPath = null
  return {
    name: 'smg-service-worker',
    apply: 'build',
    configResolved(config) {
      scope = config.env.VITE_SW_SCOPE || '/'
      manifestPath = resolve(config.root, config.build.outDir, 'manifest.webmanifest')
      if (config.mode === 'online' && !config.env.VITE_API_URL) {
        throw new Error('The online build needs VITE_API_URL (the Render API, e.g. https://<service>.onrender.com/v1) - docs/DEPLOYMENT.md')
      }
    },
    closeBundle() {
      if (scope === '/') return
      const manifest = JSON.parse(readFileSync(manifestPath, 'utf8'))
      writeFileSync(manifestPath, JSON.stringify({ ...manifest, start_url: scope, scope }, null, 2) + '\n')
    },
    generateBundle(_, bundle) {
      // The login photo is precached in AVIF only (what current browsers pick); the WebP and JPEG
      // fallbacks load from the network when needed.
      const files = Object.keys(bundle).filter((f) => !f.endsWith('.map') && !f.endsWith('.woff') && f !== 'sw.js' && !/login-bg-\d+-[\w-]+\.(webp|jpg)$/.test(f))
      const precache = ['/index.html', ...[...files, ...PUBLIC_FILES].filter((f) => f !== 'index.html').map((f) => `/${f}`)]
      const version = createHash('sha256').update(precache.join('\n'))
        .update(bundle['index.html']?.source ?? '').digest('hex').slice(0, 12)
      const source = readFileSync(new URL('./sw/sw.js', import.meta.url), 'utf8')
        .replace('__VERSION__', version).replace('__PRECACHE__', JSON.stringify(precache, null, 1))
      this.emitFile({ type: 'asset', fileName: 'sw.js', source })
    },
  }
}

export default defineConfig({
  plugins: [react(), serviceWorker()],
  server: { port: 5173 },
})
