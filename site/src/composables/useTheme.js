import { ref, watch } from 'vue'

// index.html sets the initial theme before the page paints
const theme = ref(document.documentElement.dataset.theme === 'light' ? 'light' : 'dark')

watch(theme, (value) => {
  document.documentElement.dataset.theme = value
  try {
    localStorage.setItem('theme', value)
  } catch {
    // Storage blocked (private mode): the choice lasts until reload
  }
})

export function useTheme() {
  return {
    theme,
    toggleTheme: () => {
      theme.value = theme.value === 'dark' ? 'light' : 'dark'
    },
  }
}
