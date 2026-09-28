import { createHash } from 'node:crypto'
import { readFileSync } from 'node:fs'
import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'

// Phase 7B: the service worker, built from sw/sw.js with the list of files to keep offline - every
// emitted file except source maps and the .woff fonts (every browser that runs a service worker
// reads .woff2), plus the public files the app needs (manifest, icons).
const PUBLIC_FILES = ['favicon.svg', 'manifest.webmanifest', 'icons/icon-192.png', 'icons/icon-512.png', 'icons/icon-maskable-512.png']

function serviceWorker() {
  return {
    name: 'smg-service-worker',
    apply: 'build',
    generateBundle(_, bundle) {
      const files = Object.keys(bundle).filter((f) => !f.endsWith('.map') && !f.endsWith('.woff') && f !== 'sw.js')
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
