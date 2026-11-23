import { h, clear, icon, get, post, state, BOOT, modal, showError, toast, withBusy, loadingBlock, subjectIcon } from '../lib.js';

/** Owner: turn the share link on/off and copy it. */
export async function shareDialog(subject) {
  const body = h('div', loadingBlock());
  const m = modal({ title: `Del “${subject.name}”`, body });
  const render = st => {
    if (!st.shared) {
      clear(body,
        h('p.muted', { style: { marginBottom: '14px' } }, 'Lav et link, som andre kan bruge til at kopiere faget til deres egen konto – med studieguide, emner, flashcards og quizspørgsmål. Dine egne fremskridt og samtaler deles ikke.'),
        h('button.btn.primary', { onclick: e => withBusy(e.currentTarget, async () => {
          try { render(await post('share.toggle', { id: subject.id, enable: true })); } catch (ex) { showError(ex); }
        }) }, icon('link'), 'Lav delingslink'));
      return;
    }
    const input = h('input.input', { value: st.url, readonly: true, onfocus: e => e.target.select() });
    clear(body,
      h('p.muted', { style: { marginBottom: '12px' } }, 'Alle med dette link kan se en oversigt og kopiere faget til deres egen konto.'),
      h('div.row', input, h('button.btn.primary', { onclick: async () => {
        try { await navigator.clipboard.writeText(st.url); } catch { input.select(); document.execCommand('copy'); }
        toast('Linket er kopieret', 'success');
      } }, icon('download'), 'Kopiér')),
      h('div.row', { style: { marginTop: '16px' } }, h('span.spacer'), h('button.btn.danger', { onclick: e => withBusy(e.currentTarget, async () => {
        try { render(await post('share.toggle', { id: subject.id, enable: false })); toast('Delingen er stoppet – det gamle link virker ikke længere.', 'success'); } catch (ex) { showError(ex); }
      }) }, icon('x'), 'Stop deling')));
  };
  try { render(await get('share.status', { id: subject.id })); } catch (e) { showError(e); m.close(); }
}

/** Recipient: preview of a shared subject + copy button (works signed out too). */
export async function shareView(view, token) {
  const box = h('div.card.card-pad', { style: { maxWidth: '560px', margin: '0 auto' } }, loadingBlock());
  clear(view, h('div.page', { style: { paddingTop: '48px' } },
    h('div.brand', { style: { justifyContent: 'center', marginBottom: '20px' } }, h('span.brand-mark', icon('logo')), BOOT.appName || 'Studiepilot'),
    box));
  let d;
  try {
    d = await get('share.info', { token });
  } catch (e) {
    clear(box, h('div.empty', h('div.icon-wrap', icon('alert')), h('h3', 'Linket virker ikke'), h('p', e.message), h('a.btn', { href: '#/' }, 'Til forsiden')));
    return;
  }
  const loggedIn = !!state.user;
  const copyBtn = h('button.btn.primary.lg', { onclick: () => withBusy(copyBtn, async () => {
    try {
      const r = await post('share.copy', { token });
      sessionStorage.removeItem('pendingShare');
      toast('Faget er kopieret til dine fag', 'success');
      location.hash = `#/s/${r.subject_id}`;
    } catch (e) { showError(e); }
  }) }, icon('plus'), 'Kopiér til mine fag');
  clear(box,
    h('div.row', { style: { marginBottom: '14px' } }, subjectIcon({ name: d.name, color: d.color }, 44),
      h('div', h('h2', d.name), h('div.small.muted', `Delt af ${d.owner}`))),
    d.description ? h('p.muted', { style: { marginBottom: '12px' } }, d.description) : null,
    h('div.chips', { style: { marginBottom: '14px' } },
      h('span.badge', `${d.topics.length} emner`), h('span.badge', `${d.sections} afsnit i studieguiden`),
      h('span.badge', `${d.cards} flashcards`), h('span.badge', `${d.questions} quizspørgsmål`), h('span.badge', `${d.sources} kilder`)),
    d.topics.length ? h('div', { style: { marginBottom: '18px' } }, h('div.label', 'Emner'), h('ul.small', { style: { margin: '6px 0 0', paddingLeft: '18px' } }, d.topics.map(t => h('li', t)))) : null,
    d.is_mine
      ? h('div.notice.info', icon('info'), h('div', 'Det er dit eget fag. Send linket til andre, så kan de kopiere det.'))
      : loggedIn
        ? h('div', copyBtn, h('div.tiny.faint', { style: { marginTop: '8px' } }, 'Du får din egen kopi med frisk start – det koster ingen AI-forespørgsler.'))
        : h('div.stack.sm',
          h('p.small.muted', 'Log ind eller opret en gratis konto for at kopiere faget.'),
          h('div.row',
            h('a.btn.primary', { href: '#/login', onclick: () => sessionStorage.setItem('pendingShare', token) }, 'Log ind'),
            h('a.btn', { href: '#/register', onclick: () => sessionStorage.setItem('pendingShare', token) }, 'Opret konto'))));
}
