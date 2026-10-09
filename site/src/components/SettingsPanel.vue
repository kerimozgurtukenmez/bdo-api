<script setup>
import { ref, watch } from 'vue'
import { useMastery } from '../composables/useMastery.js'
import { FAME_TIERS, MASTERY_SKILLS, MAX_MASTERY, clampMastery, useSettings } from '../composables/useSettings.js'
import { useSettingsPanel } from '../composables/useSettingsPanel.js'
import { SKILLS, number } from '../format.js'
import SampleBadge from './SampleBadge.vue'

const { settings, rate } = useSettings()
const { settingsOpen, closeSettings } = useSettingsPanel()
const { bonus, isSample } = useMastery()

const dialog = ref(null)

watch(settingsOpen, (open) => {
  if (open && !dialog.value.open) dialog.value.showModal()
  if (!open && dialog.value.open) dialog.value.close()
})

const percent = (fraction) => `${number(fraction * 100)}%`

function setMastery(skill, value) {
  settings.mastery[skill] = clampMastery(value)
}
</script>

<template>
  <dialog ref="dialog" class="settings" aria-labelledby="settings-title" @close="closeSettings" @click.self="closeSettings">
    <div class="panel">
      <header class="panel-head">
        <h2 id="settings-title">Settings</h2>
        <button class="btn btn-ghost btn-icon" type="button" aria-label="Close settings" @click="closeSettings">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
            <path d="M6 6l12 12M18 6 6 18" />
          </svg>
        </button>
      </header>
      <p class="faint small">Saved in this browser and used on every page.</p>

      <section class="group">
        <h3>Selling on the market</h3>
        <label class="check">
          <input v-model="settings.valuePack" type="checkbox" />
          Value Pack active
        </label>
        <label class="field">
          <span class="label">Family fame</span>
          <select v-model.number="settings.fame" class="select">
            <option v-for="tier in FAME_TIERS" :key="tier.bonus" :value="tier.bonus">{{ tier.label }}</option>
          </select>
        </label>
        <p class="help">You keep <strong>{{ percent(rate) }}</strong> of a market sale.</p>
      </section>

      <section class="group">
        <h3>Mastery <SampleBadge v-if="isSample" /></h3>
        <p class="help">More products per craft and a higher Imperial delivery payout. Processing mastery does not change yields.</p>
        <div v-for="skill in MASTERY_SKILLS" :key="skill" class="mastery">
          <label class="mastery-label" :for="`mastery-${skill}`">
            <span class="dot" :style="{ background: `var(--${skill})` }" aria-hidden="true"></span>{{ SKILLS[skill] }}
          </label>
          <div class="mastery-inputs">
            <input
              class="range"
              type="range"
              min="0"
              :max="MAX_MASTERY"
              step="50"
              :value="settings.mastery[skill]"
              :aria-label="`${SKILLS[skill]} mastery`"
              @input="setMastery(skill, $event.target.value)"
            />
            <input
              :id="`mastery-${skill}`"
              class="input num mastery-number"
              type="number"
              min="0"
              :max="MAX_MASTERY"
              :value="settings.mastery[skill]"
              @change="setMastery(skill, $event.target.value)"
            />
          </div>
          <p v-if="bonus(skill, settings.mastery[skill])" class="faint small num">
            +{{ percent(bonus(skill, settings.mastery[skill]).product) }} products ·
            +{{ percent(bonus(skill, settings.mastery[skill]).imperial) }} Imperial delivery
          </p>
        </div>
      </section>
    </div>
  </dialog>
</template>

<style scoped>
.settings {
  width: min(420px, 100vw);
  max-width: none;
  height: 100vh;
  max-height: none;
  margin: 0 0 0 auto;
  padding: 0;
  border: 0;
  border-left: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
}

.settings::backdrop {
  background: rgb(0 0 0 / 0.45);
}

.settings[open] {
  animation: slide-in 180ms var(--ease);
}

@keyframes slide-in {
  from { transform: translateX(24px); opacity: 0; }
}

.panel {
  padding: var(--space-5);
}

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.group {
  margin-top: var(--space-5);
  padding-top: var(--space-5);
  border-top: 1px solid var(--border);
}

.group > * + * {
  margin-top: var(--space-3);
}

.group h3 {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.check {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--text-sm);
  cursor: pointer;
}

.check input {
  width: 16px;
  height: 16px;
  accent-color: var(--accent);
}

.field .select {
  width: 100%;
}

.help {
  color: var(--text-muted);
  font-size: var(--text-xs);
}

.mastery + .mastery {
  margin-top: var(--space-4);
}

.mastery-label {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: var(--text-sm);
  font-weight: 500;
}

.dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
}

.mastery-inputs {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin: var(--space-2) 0 var(--space-1);
}

.range {
  flex: 1;
  accent-color: var(--accent);
}

.mastery-number {
  width: 84px;
  text-align: right;
}
</style>
