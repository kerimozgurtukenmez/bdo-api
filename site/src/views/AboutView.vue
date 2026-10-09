<script setup>
import { useSettingsPanel } from '../composables/useSettingsPanel.js'

const { openSettings } = useSettingsPanel()

const faq = [
  {
    q: 'Which recipe does the calculator use for an item?',
    a: 'The recipe named after the item, cooking or alchemy before processing, then the one bdocodex lists first. Every recipe in the tree can be switched with "Other recipes", and the choice is kept in the link.',
  },
  {
    q: 'How many products does one craft give?',
    a: 'Recipes give a range, for example 1–4. The calculator uses the average by default; "Min" and "Max" are the worst and best case. Your mastery adds products on top of that.',
  },
  {
    q: 'What is the difference between "Craft everything" and "Cheapest"?',
    a: '"Craft everything" makes every intermediate product itself and only buys raw materials and NPC goods. "Cheapest" buys an intermediate from the market whenever that costs less than crafting it.',
  },
  {
    q: 'Why is an item bought even though it has a recipe?',
    a: 'NPC vendor goods (Salt, Mineral Water, Base Sauce, …) cost next to nothing, so they are bought unless you calculate them directly. Ingredients that would loop back into themselves are also bought; the plan says so.',
  },
  {
    q: 'How is the profit calculated?',
    a: 'Sale value minus the cost of every material. The sale value is what you keep after the Central Market tax: price × 0.65 × (1 + 0.30 with a Value Pack + 0.5 / 1 / 1.5% family fame bonus). Set your Value Pack and fame in the settings.',
  },
  {
    q: 'How old are the prices?',
    a: 'Prices come from the EU Central Market and are refreshed regularly. Every plan shows how old its prices are and warns when they are more than a day old.',
  },
  {
    q: 'How does Imperial delivery pay?',
    a: 'The delivery NPC buys a box for its base price × (2.5 + your mastery bonus), without market tax. The Imperial page compares that with what the box ingredients cost.',
  },
]
</script>

<template>
  <article class="about">
    <header class="page-head">
      <h1>About BDO Craft</h1>
      <p class="lead">
        A crafting planner for Black Desert Online life skills. Pick what you want to make and how many: you get every material to
        buy, every crafting step in order and what it costs, down to the raw materials.
      </p>
    </header>

    <section class="card section">
      <h2>How it works</h2>
      <ol class="steps">
        <li><strong>Search</strong> a cooking, alchemy or processing product and enter a quantity.</li>
        <li>The calculator expands the <strong>whole recipe tree</strong> and adds up what every branch needs, rounding crafts up once per item.</li>
        <li>Each material is priced from the <strong>Central Market</strong> or the <strong>NPC vendor</strong>, whichever is cheaper.</li>
        <li>Change recipes, buy intermediates instead of crafting them or use substitute ingredients right in the tree.</li>
      </ol>
      <p class="muted">
        Your Value Pack, family fame and mastery change the results.
        <button class="btn btn-ghost btn-sm" type="button" @click="openSettings">Open settings</button>
      </p>
    </section>

    <section class="section">
      <h2>Questions</h2>
      <div class="faq">
        <details v-for="entry in faq" :key="entry.q" class="card">
          <summary>{{ entry.q }}</summary>
          <p>{{ entry.a }}</p>
        </details>
      </div>
    </section>

    <section class="card section">
      <h2>Data</h2>
      <ul class="sources">
        <li>Items, recipes and mastery tables: <a href="https://bdocodex.com" target="_blank" rel="noopener">bdocodex</a>, used with the site owner's permission.</li>
        <li>Market prices: <a href="https://api.arsha.io" target="_blank" rel="noopener">arsha.io</a>, from the EU Central Market.</li>
      </ul>
      <p class="faint small">
        BDO Craft is a fan project and is not affiliated with Pearl Abyss. Black Desert and all related names and images are
        trademarks or property of Pearl Abyss Corp.
      </p>
    </section>
  </article>
</template>

<style scoped>
.about {
  max-width: 820px;
}

.about > * + * {
  margin-top: var(--space-5);
}

.lead {
  margin-top: var(--space-2);
  color: var(--text-muted);
  font-size: var(--text-md);
}

.section.card {
  padding: var(--space-5);
}

.section h2 {
  margin-bottom: var(--space-3);
}

.steps {
  margin: 0 0 var(--space-3);
  padding-left: var(--space-5);
}

.steps li + li {
  margin-top: var(--space-2);
}

.faq > * + * {
  margin-top: var(--space-2);
}

details {
  padding: 0 var(--space-4);
}

summary {
  padding: var(--space-3) 0;
  font-weight: 500;
  cursor: pointer;
}

details p {
  padding-bottom: var(--space-4);
  color: var(--text-muted);
  font-size: var(--text-sm);
}

.sources {
  margin: 0 0 var(--space-3);
  padding-left: var(--space-5);
  font-size: var(--text-sm);
}

.sources li + li {
  margin-top: var(--space-1);
}
</style>
