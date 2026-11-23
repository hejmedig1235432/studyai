import { h, clear, icon, get, post, state, showError, toast, withBusy, confirmDialog, loadingBlock, timeAgo, fmtDate, menu, modal } from '../lib.js';

/** A readable random password (no look-alike characters). */
function generatePassword() {
  const chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  const bytes = crypto.getRandomValues(new Uint32Array(14));
  return Array.from(bytes, b => chars[b % chars.length]).join('').replace(/(.{4})(?!$)/g, '$1-').slice(0, 14);
}

/** Admin sets a new password for a user; all of that user's sessions are signed out. */
function passwordDialog(u) {
  const input = h('input.input', { type: 'text', autocomplete: 'new-password', placeholder: 'Mindst 8 tegn', spellcheck: false, style: { fontFamily: 'var(--mono)' } });
  const err = h('div');
  const save = h('button.btn.primary', { onclick: () => withBusy(save, async () => {
    clear(err);
    if (input.value.length < 8) { clear(err, h('div.form-error', 'Adgangskoden skal være mindst 8 tegn.')); return; }
    try {
      await post('admin.user.password', { id: u.id, password: input.value });
      m.close();
      toast(`Adgangskoden for ${u.name} er ændret. Brugeren er logget ud alle steder.`, 'success', 6000);
    } catch (e) { clear(err, h('div.form-error', e.message)); }
  }) }, 'Gem ny adgangskode');
  const m = modal({
    title: `Ny adgangskode til ${u.name}`,
    body: h('div',
      h('p.small.muted', { style: { marginTop: 0 } }, `Brugeren (${u.email}) bliver logget ud på alle enheder og skal logge ind med den nye adgangskode. Giv den til brugeren på en sikker måde.`),
      err,
      h('div.field', h('label', 'Ny adgangskode'),
        h('div.row', { style: { gap: '6px' } }, input,
          h('button.btn', { type: 'button', onclick: () => { input.value = generatePassword(); input.select(); } }, icon('refresh'), 'Generér'),
          h('button.btn', { type: 'button', onclick: async () => { try { await navigator.clipboard.writeText(input.value); toast('Kopieret', 'success'); } catch { input.select(); } } }, 'Kopiér')))),
    footer: [h('button.btn', { onclick: () => m.close() }, 'Annuller'), save],
  });
}

export async function adminView(view) {
  const page = h('div.page');
  clear(view, page);
  if (state.user?.role !== 'admin') {
    clear(page, h('div.empty', h('div.icon-wrap', icon('lock')), h('h3', 'Kun for administratorer'), h('a.btn', { href: '#/' }, 'Til oversigten')));
    return;
  }
  clear(page, loadingBlock('Indlæser administration…'));
  let d = await get('admin.overview');
  let filter = '';

  const render = () => {
    const s = d.stats;
    const stat = (ic, k, v, sub) => h('div.card.stat', h('div.k', icon(ic), k), h('div.v', String(v)), sub ? h('div.d', sub) : null);
    const maxUse = Math.max(1, ...d.usage_days.map(x => x.requests));
    const limitInput = h('input.input', { type: 'number', min: 0, max: 10000, value: d.user_limit, style: { width: '110px' } });

    const userAction = async (u, data, msg) => {
      try { d = await post('admin.user', { id: u.id, ...data }); toast(msg, 'success'); render(); } catch (e) { showError(e); }
    };
    const rows = d.users.filter(u => !filter || (u.name + ' ' + u.email).toLowerCase().includes(filter));
    const table = h('div', { style: { overflowX: 'auto' } }, h('table', { style: { width: '100%', borderCollapse: 'collapse', fontSize: '13px' } },
      h('thead', h('tr', ['Bruger', 'Rolle', 'Status', 'Fag', 'AI i dag', 'AI 7 dage', 'Lager', 'Oprettet', 'Sidst logget ind', ''].map(t =>
        h('th', { style: { textAlign: 'left', padding: '10px 12px', color: 'var(--text-3)', fontWeight: 550, borderBottom: '1px solid var(--border)', whiteSpace: 'nowrap' } }, t)))),
      h('tbody', rows.map(u => {
        const me = u.id === d.me;
        return h('tr', { style: { borderBottom: '1px solid var(--border)', opacity: u.locked ? 0.7 : 1 } },
          h('td', { style: { padding: '10px 12px' } }, h('div', { style: { fontWeight: 550 } }, u.name, me ? h('span.faint', ' (dig)') : null), h('div.tiny.faint', u.email)),
          h('td', { style: { padding: '10px 12px' } }, h('span.badge' + (u.role === 'admin' ? '.accent' : ''), u.role === 'admin' ? 'Admin' : 'Bruger')),
          h('td', { style: { padding: '10px 12px' } }, u.locked ? h('div', h('span.badge.danger', { title: u.lock_reason || '' }, icon('lock'), u.lock_reason ? 'Blokeret' : 'Låst'),
              u.lock_reason ? h('div.tiny', { style: { color: 'var(--danger)', maxWidth: '260px', whiteSpace: 'normal', marginTop: '4px' } }, u.lock_reason) : null)
            : h('div', h('span.badge.success', 'Aktiv'), u.warnings ? h('span.badge.warning', { style: { marginLeft: '4px' }, title: 'Advarsler for forsøg på at manipulere AI’en' }, `${u.warnings} advarsel`) : null)),
          h('td', { style: { padding: '10px 12px' } }, String(u.subjects)),
          h('td', { style: { padding: '10px 12px' } }, String(u.ai_today)),
          h('td', { style: { padding: '10px 12px' } }, String(u.ai_week)),
          h('td', { style: { padding: '10px 12px', whiteSpace: 'nowrap' } }, u.storage_mb + ' MB'),
          h('td', { style: { padding: '10px 12px', whiteSpace: 'nowrap' } }, fmtDate(u.created_at)),
          h('td', { style: { padding: '10px 12px', whiteSpace: 'nowrap' } }, timeAgo(u.last_login_at)),
          h('td', { style: { padding: '10px 12px', textAlign: 'right', whiteSpace: 'nowrap' } }, me ? null : h('div.row', { style: { gap: '6px', justifyContent: 'flex-end' } },
            u.locked
              ? h('button.btn.sm', { onclick: async () => {
                if (u.lock_reason && !(await confirmDialog({ title: `Ophæv blokeringen af ${u.name}?`, message: `Kontoen og de enheder, der blev blokeret sammen med den, får adgang igen. Årsag til blokeringen: ${u.lock_reason}`, confirm: 'Ophæv blokering' }))) return;
                userAction(u, { locked: false }, u.lock_reason ? `Blokeringen af ${u.name} er ophævet` : `${u.name} er låst op`);
              } }, icon('lock'), u.lock_reason ? 'Ophæv blokering' : 'Lås op')
              : h('button.btn.sm.danger', { onclick: async () => {
                if (await confirmDialog({ title: `Lås ${u.name}s konto?`, message: 'Brugeren bliver logget ud med det samme og kan ikke logge ind, før du låser kontoen op igen. Intet bliver slettet.', confirm: 'Lås konto', danger: true })) {
                  userAction(u, { locked: true }, `${u.name} er låst`);
                }
              } }, icon('lock'), 'Lås'),
            h('button.btn.sm.ghost.icon', { 'aria-label': 'Flere handlinger', onclick: e => menu(e.currentTarget, [
              u.role === 'admin'
                ? { label: 'Fjern som administrator', icon: 'user', onClick: () => userAction(u, { role: 'user' }, `${u.name} er ikke længere administrator`) }
                : { label: 'Gør til administrator', icon: 'key', onClick: async () => {
                  if (await confirmDialog({ title: `Gør ${u.name} til administrator?`, message: 'Administratorer kan se og ændre AI-nøgler og låse brugere.', confirm: 'Gør til admin' })) userAction(u, { role: 'admin' }, `${u.name} er nu administrator`);
                } },
              { label: 'Skift adgangskode', icon: 'lock', onClick: () => passwordDialog(u) },
              'sep',
              { label: 'Slet konto', icon: 'trash', danger: true, onClick: async () => {
                if (!(await confirmDialog({ title: `Slet ${u.name}s konto permanent?`, message: `Alle fag, filer og fremskridt for ${u.email} bliver slettet. Det kan ikke fortrydes. Overvej at låse kontoen i stedet.`, confirm: 'Slet permanent', danger: true }))) return;
                try { d = await post('admin.user.delete', { id: u.id }); toast('Kontoen er slettet', 'success'); render(); } catch (e) { showError(e); }
              } },
            ]) }, icon('more')))));
      }))));

    clear(page,
      h('div.page-head', h('div', h('h1', 'Administration'), h('div.sub', 'Brugere, forbrug og indstillinger for hele siden.'))),
      h('div.grid.cols-4', { style: { marginBottom: '16px' } },
        stat('user', 'Brugere', s.users, `${s.active_week} aktive de sidste 7 dage`),
        stat('lock', 'Låste konti', s.locked),
        stat('zap', 'AI-forespørgsler i dag', s.ai_today),
        stat('layers', 'Fag', s.subjects, `${s.sources} kilder · ${s.storage_mb} MB`)),
      h('div.split', { style: { marginBottom: '16px' } },
        h('div.card', h('div.card-head', h('h3', 'AI-forespørgsler de sidste 14 dage')),
          h('div.card-body', h('div.bars', d.usage_days.map((x, i) => h('div.b' + (i === d.usage_days.length - 1 ? '.today' : ''), { title: `${x.day}: ${x.requests}` },
            h('i' + (x.requests ? '' : '.zero'), { style: { height: Math.max(3, Math.round(x.requests / maxUse * 90)) + 'px' } }),
            h('span', new Date(x.day + 'T12:00:00').toLocaleDateString('da-DK', { day: 'numeric' }))))))),
        h('div.card.card-pad',
          h('h3', { style: { marginBottom: '6px' } }, 'AI-grænse pr. bruger'),
          h('p.small.muted', { style: { marginBottom: '12px' } }, 'Hvor mange AI-forespørgsler hver bruger må bruge pr. dag, så én bruger ikke bruger hele sidens kvote. 0 = ingen grænse. Administratorer har ingen grænse.'),
          h('div.row', limitInput, h('span.small.muted', 'pr. dag'), h('button.btn.primary', { onclick: e => withBusy(e.currentTarget, async () => {
            try { d = await post('admin.user_limit', { limit: +limitInput.value || 0 }); toast('Grænsen er gemt', 'success'); render(); } catch (ex) { showError(ex); }
          }) }, 'Gem')))),
      h('div.card',
        h('div.card-head', h('h3', `Brugere (${d.users.length})`),
          h('input.input', { placeholder: 'Søg efter navn eller e-mail…', value: filter, style: { maxWidth: '260px', height: '32px' }, oninput: e => { filter = e.target.value.toLowerCase(); const pos = e.target.selectionStart; render(); const inp = page.querySelector('.card-head input'); inp.focus(); inp.setSelectionRange(pos, pos); } })),
        table),
      h('div.card', { style: { marginTop: '16px' } },
        h('div.card-head', h('h3', icon('alert'), ' Sikkerhed: forsøg på at manipulere AI’en'), h('span.small.faint', `${d.banned_devices} blokerede enheder`)),
        d.security_events.length ? h('div.list', d.security_events.map(ev => {
          const [cls, label] = ({ ban: ['danger', 'Blokeret'], warning: ['warning', 'Advarsel'], admin_warning: ['', 'Advarsel (admin)'] })[ev.kind] || ['', ev.kind];
          return h('div.list-item', { style: { alignItems: 'flex-start' } },
            h('span.badge' + (cls ? '.' + cls : ''), { style: { flex: 'none' } }, label),
            h('div', { style: { flex: 1, minWidth: 0 } },
              h('div', h('b', ev.name || 'Slettet bruger'), h('span.tiny.faint', ` · ${ev.email || ''} · ${ev.route} · ${timeAgo(ev.created_at)}`)),
              h('div.small.muted', ev.label),
              ev.excerpt ? h('div.small', { style: { fontFamily: 'var(--mono)', color: 'var(--text-2)', marginTop: '2px', wordBreak: 'break-word' } }, `“${ev.excerpt}”`) : null));
        })) : h('div.card-body', h('p.small.muted', { style: { margin: 0 } }, 'Ingen forsøg registreret. Brugere, der prøver at manipulere AI’en, får først en advarsel ved svage tegn – og bliver blokeret (konto + enhed, ikke IP) ved klare forsøg eller anden advarsel.'))));
  };
  render();
}
