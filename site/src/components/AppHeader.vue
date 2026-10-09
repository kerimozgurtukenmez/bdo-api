<script setup>
import { useSettingsPanel } from '../composables/useSettingsPanel.js'
import { useTheme } from '../composables/useTheme.js'
import SettingsPanel from './SettingsPanel.vue'

const { theme, toggleTheme } = useTheme()
const { openSettings } = useSettingsPanel()
</script>

<template>
  <header class="header">
    <div class="container header-inner">
      <RouterLink to="/" class="logo" aria-label="BDO Craft home">
        <svg width="26" height="26" viewBox="0 0 32 32" aria-hidden="true">
          <path d="M16 6 25 11v10l-9 5-9-5V11z" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linejoin="round" />
          <path d="M16 11v10M11.5 13.5 16 16l4.5-2.5" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" />
        </svg>
        <span>BDO Craft</span>
      </RouterLink>

      <nav class="nav" aria-label="Main">
        <RouterLink to="/" class="nav-link" exact-active-class="active">Calculator</RouterLink>
        <RouterLink to="/recipes" class="nav-link" active-class="active">Recipes</RouterLink>
        <RouterLink to="/imperial" class="nav-link" active-class="active">Imperial</RouterLink>
        <RouterLink to="/about" class="nav-link" active-class="active">About</RouterLink>
      </nav>

      <div class="actions">
        <button class="btn btn-ghost btn-icon" type="button" aria-label="Settings" title="Settings" @click="openSettings">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12M20 18h0" />
            <circle cx="16" cy="6" r="2" />
            <circle cx="10" cy="12" r="2" />
            <circle cx="18" cy="18" r="2" />
          </svg>
        </button>
        <button
          class="btn btn-ghost btn-icon"
          type="button"
          :aria-label="theme === 'dark' ? 'Switch to light theme' : 'Switch to dark theme'"
          :title="theme === 'dark' ? 'Light theme' : 'Dark theme'"
          @click="toggleTheme"
        >
          <svg v-if="theme === 'dark'" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4" />
          </svg>
          <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z" />
          </svg>
        </button>
      </div>
    </div>
  </header>
  <SettingsPanel />
</template>

<style scoped>
.header {
  position: sticky;
  top: 0;
  z-index: 50;
  border-bottom: 1px solid var(--border);
  background: color-mix(in srgb, var(--surface) 88%, transparent);
  backdrop-filter: blur(10px);
}

.header-inner {
  display: flex;
  align-items: center;
  gap: var(--space-5);
  height: var(--header-height);
}

.logo {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--accent);
  font-size: var(--text-md);
  font-weight: 600;
  letter-spacing: -0.01em;
  white-space: nowrap;
}

.logo span {
  color: var(--text);
}

.nav {
  display: flex;
  gap: var(--space-1);
  margin-right: auto;
}

.nav-link {
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  color: var(--text-muted);
  font-size: var(--text-sm);
  font-weight: 500;
  white-space: nowrap;
  transition: background 140ms var(--ease), color 140ms var(--ease);
}

.nav-link:hover {
  background: var(--surface-2);
  color: var(--text);
}

.nav-link.active {
  background: var(--surface-2);
  color: var(--text);
}

.actions {
  display: flex;
  gap: var(--space-1);
}

/* Small screens: the nav moves to its own scrollable row under the logo */
@media (max-width: 640px) {
  .header-inner {
    flex-wrap: wrap;
    gap: 0 var(--space-2);
    height: auto;
    padding-top: var(--space-2);
  }

  .logo {
    flex: 1;
  }

  .nav {
    order: 3;
    width: 100%;
    margin: var(--space-1) 0 var(--space-2);
    overflow-x: auto;
    scrollbar-width: none;
  }

  .nav-link {
    padding: var(--space-2);
  }
}
</style>
