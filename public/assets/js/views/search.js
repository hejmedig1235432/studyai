import { h, clear, icon, get, state, escapeHtml, debounce, emptyState, openSourceViewer } from '../lib.js';

export async function searchView(view, query) {
  const input = h('input.input', { placeholder: 'Søg i alle dine dokumenter, emner og flashcards…', value: query.q || '', style: { height: '44px', fontSize: '15px', paddingLeft: '40px' }, autofocus: true });
  const subjectSel = h('select.select', { style: { width: 'auto', height: '44px' } }, h('option', { value: '' }, 'Alle fag'), state.subjects.map(s => h('option', { value: s.id }, s.name)));
  const results = h('div');

  const highlight = (text, q) => {
    let html = escapeHtml(text);
    const terms = q.split(/\s+/).filter(t => t.length > 1).map(t => t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'));
    if (terms.length) html = html.replace(new RegExp(`(${terms.join('|')})`, 'gi'), '<b>$1</b>');
    return html;
  };

  const run = async () => {
    const q = input.value.trim();
    history.replaceState(null, '', '#/search' + (q ? '?q=' + encodeURIComponent(q) : ''));
    if (q.length < 2) {
      clear(results, h('div.card', emptyState({ icon: 'search', title: 'Søg i dit materiale', text: 'Find ethvert begreb, navn eller dato på tværs af alle dine uploadede dokumenter.' })));
      return;
    }
    clear(results, h('div.loading-block', h('div.spinner')));
    try {
      const r = await get('search', { q, subject_id: subjectSel.value || 0 });
      const total = r.results.length + r.topics.length + r.cards.length;
      if (!total) {
        clear(results, h('div.card', emptyState({ icon: 'search', title: 'Ingen resultater', text: `Intet matchede “${q}”. Prøv et andet ord eller et synonym.` })));
        return;
      }
      clear(results, h('div.stack',
        r.topics.length ? h('div.card', h('div.card-head', h('h3', 'Emner')), h('div.list', r.topics.map(t => h('a.list-item.click', { href: `#/s/${t.subject_id}/guide`, style: { color: 'inherit', textDecoration: 'none' } }, icon('book', 'faint'), h('span', { style: { flex: 1 } }, t.name), h('span.tiny.faint', t.subject_name))))) : null,
        r.cards.length ? h('div.card', h('div.card-head', h('h3', 'Flashcards')), h('div.list', r.cards.map(c => h('a.list-item.click', { href: `#/s/${c.subject_id}/cards`, style: { color: 'inherit', textDecoration: 'none', display: 'block' } }, h('div', { style: { fontWeight: 550 } }, c.front), h('div.small.muted', c.back), h('div.tiny.faint', c.subject_name))))) : null,
        r.results.length ? h('div.card', h('div.card-head', h('h3', 'I dine dokumenter'), h('span.badge', String(r.results.length))),
          r.results.map(x => h('div.search-hit', { onclick: () => openSourceViewer(x.source_id, x.chunk_id) },
            h('div.row', { style: { gap: '8px' } }, icon('file', 'faint'), h('span.truncate', { style: { fontWeight: 550 } }, x.source_title), x.label ? h('span.badge', x.label) : null, h('span.spacer'), h('span.tiny.faint', x.subject_name)),
            h('div.snip', { html: highlight(x.snippet, q) })))) : null));
    } catch (e) {
      clear(results, h('div.form-error', e.message));
    }
  };
  const debounced = debounce(run, 300);
  input.addEventListener('input', debounced);
  subjectSel.addEventListener('change', run);

  clear(view, h('div.page.narrow',
    h('div.page-head', h('div', h('h1', 'Søg'), h('div.sub', 'Søg på tværs af alle uploadede dokumenter.'))),
    h('div.row', { style: { marginBottom: '18px' } },
      h('div', { style: { position: 'relative', flex: 1 } }, h('span', { style: { position: 'absolute', left: '13px', top: '14px', color: 'var(--text-3)' } }, icon('search')), input),
      subjectSel),
    results));
  setTimeout(() => input.focus(), 30);
  run();
}
