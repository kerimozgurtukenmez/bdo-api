// Global navbar search — it will done in future
document.getElementById('global-search')?.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
        const q = e.target.value.trim();
        if (q) {
            window.location.href = `/bdo-site/items.php?search=${encodeURIComponent(q)}`;
        }
    }
});