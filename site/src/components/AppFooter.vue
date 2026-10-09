<template>
  <footer class="footer">
    <div class="container footer-inner">
      <p>
        Data from <a href="https://bdocodex.com" target="_blank" rel="noopener">bdocodex</a>,
        market prices from <a href="https://api.arsha.io" target="_blank" rel="noopener">arsha.io</a> (EU).
      </p>
      <p>Not affiliated with Pearl Abyss. Black Desert is a trademark of Pearl Abyss Corp.</p>
    </div>
  </footer>
</template>

<style scoped>
.footer {
  border-top: 1px solid var(--border);
  color: var(--text-faint);
  font-size: var(--text-xs);
}

.footer-inner {
  display: flex;
  flex-wrap: wrap;
  justify-content: space-between;
  gap: var(--space-2) var(--space-5);
  padding-top: var(--space-5);
  padding-bottom: var(--space-5);
}

.footer a {
  color: var(--text-muted);
}

.footer a:hover {
  color: var(--text);
}
</style>
