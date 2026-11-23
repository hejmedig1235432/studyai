import { h, clear, icon, get, post, addRefs, refChips, md, menu, toast, showError, withBusy, modal, confirmDialog, emptyState, loadingBlock, progressBar, pct } from '../lib.js';

/** Client-side preview of the next interval for each rating (mirrors the server's schedule). */
function preview(card, rating) {
  const reps = card.reps, iv = card.interval_days, ease = 2.5;
  let days;
  if (rating === 'hard') return '10 min';
  if (rating === 'medium') days = reps === 0 ? 1 : reps === 1 ? 3 : Math.max(iv, 1) * ease;
  else days = reps === 0 ? 3 : reps === 1 ? 7 : Math.max(iv, 1) * ease * 1.3;
  days = Math.round(days);
  return days >= 30 ? Math.round(days / 30) + ' md.' : days + ' d.';
}

/**
 * Flashcard study session. Hard cards are re-queued a few cards later in the same session.
 * @returns element
 */
export function studySession(cards, { onDone, title = 'Flashcards' } = {}) {
  const queue = [...cards];
  const stats = { easy: 0, medium: 0, hard: 0 };
  const seen = new Set();
  const wrap = h('div');
  let flipped = false;
  let current = null;

  const onKey = e => {
    if (!current || e.target.closest('input,textarea')) return;
    if (e.key === ' ' || e.key === 'Enter') { e.preventDefault(); flip(); }
    if (flipped && ['1', '2', '3'].includes(e.key)) rate(['hard', 'medium', 'easy'][+e.key - 1]);
  };
  document.addEventListener('keydown', onKey);

  const flip = () => {
    flipped = !flipped;
    wrap.querySelector('.fc')?.classList.toggle('flipped', flipped);
    wrap.querySelector('.rate')?.classList.toggle('hidden', !flipped);
    wrap.querySelector('.flip-hint')?.classList.toggle('hidden', flipped);
  };

  const rate = async rating => {
    const card = current;
    if (!card) return;
    current = null;
    stats[rating]++;
    seen.add(card.id);
    try {
      const r = await post('cards.review', { card_id: card.id, rating });
      Object.assign(card, r.card);
    } catch (e) { showError(e); }
    if (rating === 'hard' && (card.again || 0) < 3) {
      card.again = (card.again || 0) + 1;
      queue.splice(Math.min(queue.length, 3), 0, card);
    }
    next();
  };

  const next = () => {
    flipped = false;
    if (!queue.length) return finish();
    current = queue.shift();
    const c = current;
    const total = seen.size + queue.length + 1;
    clear(wrap,
      h('div.row', { style: { maxWidth: '640px', margin: '0 auto 14px' } },
        h('span.small.muted', `${seen.size} af ${new Set([...seen, ...queue.map(q => q.id), c.id]).size} kort`),
        h('div', { style: { flex: 1 } }, progressBar((seen.size / total) * 100)),
        h('span.tiny.faint', c.again ? 'Gentagelse' : c.state === 'new' ? 'Nyt kort' : 'Gentag')),
      h('div.fc-stage', h('div.fc', { onclick: flip, role: 'button', tabindex: 0, 'aria-label': 'Vend kortet' },
        h('div.fc-face.front', h('div.fc-label', h('span', 'Spørgsmål'), h('span', c.topic || '')), h('div.fc-text', c.front), h('div.tiny.faint.flip-hint', { style: { textAlign: 'center' } }, 'Klik eller tryk på mellemrum for at se svaret')),
        h('div.fc-face.back', h('div.fc-label', h('span', 'Svar'), h('span', c.topic || '')), h('div.fc-text', c.back), c.refs?.length ? h('div.row.tiny.faint', { style: { justifyContent: 'center', gap: '6px' }, onclick: e => e.stopPropagation() }, 'Kilde:', refChips(c.refs)) : null))),
      h('div.rate.hidden',
        h('button.hard', { onclick: () => rate('hard') }, 'Svær', h('small', `Igen om ${preview(c, 'hard')} · 1`)),
        h('button.medium', { onclick: () => rate('medium') }, 'Middel', h('small', `${preview(c, 'medium')} · 2`)),
        h('button.easy', { onclick: () => rate('easy') }, 'Let', h('small', `${preview(c, 'easy')} · 3`))));
  };

  const finish = () => {
    document.removeEventListener('keydown', onKey);
    const n = stats.easy + stats.medium + stats.hard;
    clear(wrap, h('div.card', { style: { maxWidth: '560px', margin: '0 auto' } }, emptyState({
      icon: 'trophy',
      title: n ? 'Runden er færdig!' : 'Intet at gentage lige nu',
      text: n ? `Du gennemgik ${seen.size} kort: ${stats.easy} lette, ${stats.medium} middel, ${stats.hard} svære. Svære kort kommer igen hurtigere.` : 'Alle kort er planlagt til senere. Kom tilbage i morgen, eller øv alle kort alligevel.',
      action: h('div.row', { style: { justifyContent: 'center' } }, onDone ? h('button.btn.primary', { onclick: onDone }, 'Færdig') : null),
    })));
  };

  next();
  wrap.cleanup = () => document.removeEventListener('keydown', onKey);
  return wrap;
}

function cardDialog(ctx, card, onSaved) {
  const front = h('textarea.textarea', { value: card?.front || '', rows: 3, placeholder: 'Spørgsmål eller begreb' });
  const back = h('textarea.textarea', { value: card?.back || '', rows: 4, placeholder: 'Svar' });
  const topic = h('select.select', h('option', { value: '' }, 'Intet emne'), ctx.data.topics.map(t => h('option', { value: t.id, selected: card?.topic_id === t.id }, t.name)));
  const m = modal({
    title: card ? 'Redigér flashcard' : 'Nyt flashcard',
    body: h('div', h('div.field', h('label', 'Forside'), front), h('div.field', h('label', 'Bagside'), back), card ? null : h('div.field', h('label', 'Emne'), topic)),
    footer: [h('button.btn', { onclick: () => m.close() }, 'Annuller'), h('button.btn.primary', { onclick: async e => withBusy(e.currentTarget, async () => {
      try {
        await post('cards.save', { id: card?.id || 0, subject_id: ctx.id, topic_id: +topic.value || 0, front: front.value, back: back.value });
        m.close();
        toast('Flashcard er gemt', 'success');
        onSaved();
      } catch (ex) { showError(ex); }
    }) }, 'Gem')],
  });
}

export async function cardsTab(el, ctx, query) {
  let session = null;
  const cleanup = () => session?.cleanup?.();

  const load = async () => {
    cleanup();
    clear(el, loadingBlock('Indlæser flashcards…'));
    const d = await get('cards.list', { subject_id: ctx.id });
    addRefs(d.refs);
    const st = d.stats;
    let filter = '';
    let stateFilter = 'all';
    const listBox = h('div.list');
    const renderList = () => clear(listBox, d.cards
      .filter(c => (stateFilter === 'all' || c.state === stateFilter) && (!filter || (c.front + ' ' + c.back).toLowerCase().includes(filter)))
      .slice(0, 400)
      .map(c => h('div.list-item', { style: { alignItems: 'flex-start' } },
        h('span.state-dot.state-' + c.state, { title: c.state, style: { marginTop: '7px' } }),
        h('div', { style: { flex: 1, minWidth: 0 } }, h('div', { style: { fontWeight: 550 } }, c.front), h('div.small.muted', c.back),
          h('div.row.tiny.faint', { style: { marginTop: '4px', gap: '6px', flexWrap: 'wrap' } }, c.topic || 'Intet emne', c.retention !== null ? `· hukommelse ${pct(c.retention)}%` : '· ikke gentaget', c.lapses ? `· glemt ${c.lapses}×` : '', refChips(c.refs))),
        h('button.btn.ghost.icon.sm', { 'aria-label': 'Kortindstillinger', onclick: e => menu(e.currentTarget, [
          { label: 'Redigér', icon: 'edit', onClick: () => cardDialog(ctx, c, load) },
          { label: 'Slet', icon: 'trash', danger: true, onClick: async () => {
            if (!(await confirmDialog({ title: 'Slet dette flashcard?', message: c.front, confirm: 'Slet', danger: true }))) return;
            try { await post('cards.delete', { id: c.id }); d.cards = d.cards.filter(x => x.id !== c.id); renderList(); } catch (ex) { showError(ex); }
          } },
        ]) }, icon('more')))));
    renderList();

    const startSession = async (mode, topicId) => {
      clear(el, loadingBlock('Gør din runde klar…'));
      try {
        const r = await get('cards.session', { subject_id: ctx.id, mode, topic_id: topicId || 0, limit: mode === 'all' ? 60 : 30 });
        addRefs(r.refs);
        session = studySession(r.cards, { onDone: load });
        clear(el, h('div.row', { style: { marginBottom: '16px' } }, h('button.btn.ghost', { onclick: load }, icon('arrowLeft'), 'Alle flashcards')), session);
      } catch (e) { showError(e); load(); }
    };

    const stat = (label, n, cls) => h('div.card.stat', h('div.k', h('span.state-dot.state-' + cls), label), h('div.v', String(n)));
    const topicSelect = h('select.select', { style: { width: 'auto', height: '34px' } }, h('option', { value: '' }, 'Vælg emne…'), ctx.data.topics.map(t => h('option', { value: t.id }, `${t.name} (${t.cards})`)));
    topicSelect.onchange = () => topicSelect.value && startSession('all', +topicSelect.value);

    clear(el,
      h('div.grid.cols-4', { style: { marginBottom: '16px' } }, stat('Nye', st.new, 'new'), stat('Lærer', st.learning, 'learning'), stat('Svære', st.struggling, 'struggling'), stat('Kan', st.mastered, 'mastered')),
      h('div.card.card-pad', { style: { marginBottom: '16px' } },
        h('div.row.wrap', { style: { gap: '12px' } },
          h('div', { style: { flex: 1, minWidth: '220px' } }, h('h3', st.due ? `${st.due} kort klar til at øve` : 'Du er helt opdateret'), h('div.small.muted', 'Smart øvning viser kort, der skal gentages, og nye kort først. Svære kort kommer igen i samme runde.')),
          h('button.btn.primary.lg', { onclick: () => startSession('smart'), disabled: !d.cards.length }, icon('play'), 'Øv nu'),
          h('button.btn', { onclick: () => startSession('all'), disabled: !d.cards.length }, icon('shuffle'), 'Alle kort'),
          h('button.btn', { onclick: () => startSession('hard'), disabled: !st.struggling && !d.cards.some(c => c.lapses) }, icon('flame'), 'Svære kort'),
          topicSelect)),
      h('div.card',
        h('div.card-head',
          h('input.input', { placeholder: 'Søg i kort…', style: { maxWidth: '260px', height: '32px' }, oninput: e => { filter = e.target.value.toLowerCase(); renderList(); } }),
          h('div.seg', [['all', 'Alle'], ['new', 'Nye'], ['learning', 'Lærer'], ['struggling', 'Svære'], ['mastered', 'Kan']].map(([k, l]) => h('button' + (k === 'all' ? '.on' : ''), { onclick: e => { stateFilter = k; e.currentTarget.parentNode.querySelectorAll('button').forEach(b => b.classList.remove('on')); e.currentTarget.classList.add('on'); renderList(); } }, l))),
          h('div.row', { style: { gap: '6px' } },
            h('button.btn.sm', { onclick: () => cardDialog(ctx, null, load) }, icon('plus'), 'Tilføj'),
            h('button.btn.sm', { onclick: e => withBusy(e.currentTarget, async () => {
              try { const r = await post('cards.generate', { subject_id: ctx.id, count: 10 }); toast(r.added ? `${r.added} nye flashcards tilføjet` : 'Ingen nye kort – dit materiale er måske allerede dækket.', r.added ? 'success' : 'info'); load(); } catch (ex) { showError(ex); }
            }) }, icon('sparkles'), 'Lav flere'),
            h('button.btn.sm', { onclick: e => menu(e.currentTarget, [
              { label: 'CSV (Excel, Quizlet)', icon: 'download', onClick: () => (location.href = `api.php?r=cards.export&format=csv&subject_id=${ctx.id}`) },
              { label: 'Anki (tab-separated)', icon: 'download', onClick: () => (location.href = `api.php?r=cards.export&format=anki&subject_id=${ctx.id}`) },
              { label: 'JSON', icon: 'download', onClick: () => (location.href = `api.php?r=cards.export&format=json&subject_id=${ctx.id}`) },
              'sep',
              { label: 'Nulstil fremskridt', icon: 'refresh', danger: true, onClick: async () => {
                if (!(await confirmDialog({ title: 'Nulstil flashcard-fremskridt?', message: 'Alle kort i faget bliver nye igen. Din historik gemmes til statistik.', confirm: 'Nulstil', danger: true }))) return;
                try { await post('cards.reset', { subject_id: ctx.id }); load(); } catch (ex) { showError(ex); }
              } },
            ]) }, icon('download'), 'Eksportér'))),
        d.cards.length ? listBox : emptyState({ icon: 'cards', title: 'Ingen flashcards endnu', text: 'Lav kort ud fra dit materiale, eller tilføj dine egne.' })));
    if (query.study) { const mode = query.study; query.study = null; startSession(mode); }
  };
  await load();
  return cleanup;
}
