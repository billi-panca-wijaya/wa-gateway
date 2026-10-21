// Runs before ./i18n initialises (imported first by OpenWaApp): the embedded dashboard opens in
// Indonesian and in the dark theme unless the admin already picked something else.
if (!localStorage.getItem('openwa_language')) localStorage.setItem('openwa_language', 'id');
if (!localStorage.getItem('openwa_theme')) localStorage.setItem('openwa_theme', 'dark');
const theme = localStorage.getItem('openwa_theme');
if (theme === 'light' || theme === 'dark') document.documentElement.setAttribute('data-theme', theme);

export {};
