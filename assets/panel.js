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

for (const form of document.querySelectorAll('.privacy-process')) {
  form.addEventListener('submit', async event => {
    event.preventDefault();
    const button = form.querySelector('button');
    const progress = form.querySelector('.privacy-progress');
    const download = form.querySelector('.privacy-download');
    const kind = form.dataset.kind;
    button.disabled = true;
    progress.textContent = progress.dataset.working;
    download.hidden = true;
    try {
      const endpoint = form.closest('.privacy-request').dataset.endpoint;
      const discovery = await fetch(endpoint, {
        method: 'POST', credentials: 'same-origin',
        body: new URLSearchParams({action: 'dexo_privacy_processors', id: form.dataset.id, security: form.dataset.discovery})
      });
      const processors = await discovery.json();
      if (!discovery.ok || !processors.success || !Number.isInteger(processors.data?.count)) {
        throw new Error(progress.dataset.error);
      }
      const total = Math.max(1, processors.data.count);
      let downloadUrl = '';
      const messages = new Set();
      for (let index = 1; index <= total; index++) {
        let page = 1;
        let done = false;
        while (!done) {
          const data = new URLSearchParams({
            action: form.dataset.action,
            id: form.dataset.id,
            security: form.dataset.nonce,
            [kind]: String(index),
            page: String(page),
            sendAsEmail: 'false'
          });
          const response = await fetch(form.closest('.privacy-request').dataset.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {'X-Dexo-Privacy': '1'},
            body: data
          });
          const result = await response.json();
          if (!response.ok || !result.success || typeof result.data?.done !== 'boolean') {
            const message = typeof result.data === 'string' ? result.data : progress.dataset.error;
            throw new Error(message);
          }
          done = result.data.done;
          if (result.data.url) downloadUrl = result.data.url;
          for (const message of result.data.messages || []) messages.add(String(message));
          page++;
        }
      }
      progress.textContent = [progress.dataset.done, ...messages].join(' ');
      if (downloadUrl) {
        const url = new URL(downloadUrl, location.href);
        if (!['http:', 'https:'].includes(url.protocol)) throw new Error(progress.dataset.error);
        download.href = url.href;
        download.hidden = false;
      }
    } catch (error) {
      progress.textContent = error.message || progress.dataset.error;
      button.disabled = false;
    }
  });
}

// A report is shown only after every batch has completed. Retrying never adds twice.
for (const report of document.querySelectorAll('[data-report-token]')) {
  const status = report.querySelector('[role="status"]');
  const initial = status.textContent;
  const progress = report.querySelector('progress');
  (async () => {
    try {
      let busy = 0;
      for (;;) {
        const response = await fetch(report.dataset.endpoint, {
          method: 'POST', credentials: 'same-origin',
          body: new URLSearchParams({action: 'dexo_report', security: report.dataset.security, token: report.dataset.reportToken})
        });
        if (response.status === 409 && busy++ < 5) { await new Promise(resolve => setTimeout(resolve, 1000)); continue; }
        const result = await response.json();
        if (!response.ok || !result.success) throw new Error();
        busy = 0;
        progress.max = Math.max(1, result.data.total);
        progress.value = result.data.processed;
        status.textContent = `${initial} (${result.data.processed} / ${result.data.total})`;
        if (result.data.done) { location.reload(); break; }
      }
    } catch {
      status.textContent = initial;
      report.querySelector('a').focus();
    }
  })();
}
