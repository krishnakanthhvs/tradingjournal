(() => {
  const system = matchMedia('(prefers-color-scheme: dark)');
  let preference = 'system';
  try {
    preference = localStorage.getItem('journal-theme') || 'system';
  } catch {}
  if (!['light', 'dark', 'system'].includes(preference)) preference = 'system';
  const apply = () => {
    const theme = preference === 'system' ? (system.matches ? 'dark' : 'light') : preference;
    document.documentElement.dataset.theme = theme;
    document.documentElement.style.colorScheme = theme;
    document
      .querySelector('meta[name="theme-color"]')
      ?.setAttribute('content', theme === 'dark' ? '#0c141c' : '#f4f7fc');
  };
  window.journalTheme = {
    get preference() {
      return preference;
    },
    set(value) {
      preference = value;
      try {
        localStorage.setItem('journal-theme', value);
      } catch {}
      apply();
    },
  };
  system.addEventListener('change', apply);
  apply();
})();
