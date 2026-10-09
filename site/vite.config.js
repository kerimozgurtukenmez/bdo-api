import { writeFileSync } from 'node:fs'
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// URL the built site is served at. XAMPP: site/.htaccess maps /BDO-website/site/ to dist/
const base = process.env.SITE_BASE ?? '/BDO-website/site/'

// The site is a single-page app: Apache answers unknown paths with index.html
function apacheFallback() {
  return {
    name: 'apache-fallback',
    apply: 'build',
    closeBundle() {
      writeFileSync(new URL('./dist/.htaccess', import.meta.url), `FallbackResource ${base}index.html\n`)
    },
  }
}

export default defineConfig(({ command }) => ({
  base: command === 'build' ? base : '/',
  plugins: [vue(), apacheFallback()],
  server: {
    // The API and the icons are served by Apache (XAMPP)
    proxy: { '/BDO-website': 'http://localhost' },
  },
}))
