import { ref } from 'vue'

// The settings dialog lives in the header; any page can open it
const open = ref(false)

export function useSettingsPanel() {
  return {
    settingsOpen: open,
    openSettings: () => (open.value = true),
    closeSettings: () => (open.value = false),
  }
}
