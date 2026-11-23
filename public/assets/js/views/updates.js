import { h, clear, icon, get, post, state, loadingBlock } from '../lib.js';

const TYPES = {
  new: ['Nyt', 'badge.accent'],
  improved: ['Forbedret', 'badge.success'],
  fixed: ['Rettet', 'badge.warning'],
};

/** Update log ("Nyheder"): every release with date and changes, newest first. */
export async function updatesView(view) {
  const page = h('div.page.narrow');
  clear(view, page);
  clear(page, loadingBlock('Indlæser opdateringer…'));
  const d = await get('updates');
  const lastSeen = d.seen_version;
  clear(page,
    h('div.page-head', h('div', h('h1', 'Nyheder'), h('div.sub', `Opdateringslog – alt, hvad der er nyt og ændret. Du bruger version ${d.version}.`))),
    h('div.timeline', { style: { paddingLeft: '22px' } }, d.entries.map((e, i) => h('div.ev', { style: { paddingBottom: '22px' } },
      h('div.card.card-pad',
        h('div.row.wrap', { style: { marginBottom: '10px', gap: '8px' } },
          h('span.badge' + (i === 0 ? '.accent' : ''), 'v' + e.version),
          h('h3', { style: { flex: 1 } }, e.title),
          i === 0 && lastSeen !== e.version ? h('span.badge.success', 'Ny') : null,
          h('span.small.faint', new Date(e.date + 'T12:00:00').toLocaleDateString('da-DK', { day: 'numeric', month: 'long', year: 'numeric' }))),
        h('ul', { style: { margin: 0, paddingLeft: 0, listStyle: 'none' } }, e.changes.map(c => h('li.row', { style: { alignItems: 'flex-start', gap: '10px', padding: '5px 0' } },
          h('span.' + (TYPES[c.type] || TYPES.new)[1], { style: { minWidth: '78px', justifyContent: 'center' } }, (TYPES[c.type] || TYPES.new)[0]),
          h('span', c.text)))))))));
  // Opening the page counts as "seen".
  if (lastSeen !== d.version) {
    try {
      await post('updates.seen');
      state.user.prefs = { ...(state.user.prefs || {}), seen_version: d.version };
      document.querySelectorAll('.updates-badge').forEach(b => b.remove());
    } catch { /* not important */ }
  }
}
