const menu = document.querySelector('.mobile-menu');
const sidebar = document.querySelector('.sidebar');
const backdrop = document.querySelector('.sidebar-backdrop');
const mobile = matchMedia('(max-width: 760px)');

if (menu && sidebar && backdrop) {
  function setMenu(open, restoreFocus = false) {
    sidebar.classList.toggle('is-open', open);
    sidebar.inert = mobile.matches && !open;
    backdrop.hidden = !open;
    menu.setAttribute('aria-expanded', String(open));
    if (open) sidebar.querySelector('a')?.focus();
    if (restoreFocus) menu.focus();
  }

  setMenu(false);
  menu.addEventListener('click', () => setMenu(menu.getAttribute('aria-expanded') !== 'true'));
  backdrop.addEventListener('click', () => setMenu(false, true));
  mobile.addEventListener('change', () => setMenu(false));
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && menu.getAttribute('aria-expanded') === 'true') {
      setMenu(false, true);
    }
  });
}

const editor = document.getElementById('dexo-editor');
if (editor && typeof editor.showModal === 'function') {
  editor.removeAttribute('open');
  editor.showModal();
  editor.addEventListener('cancel', event => {
    event.preventDefault();
    location.assign(editor.dataset.return);
  });
}
