import { h, clear, icon, get, post, addRefs, md, menu, toast, showError, withBusy, confirmDialog, promptDialog, emptyState, loadingBlock, timeAgo, languageSelect, modal, progressBar } from '../lib.js';

export async function guideTab(el, ctx) {
  clear(el, loadingBlock('Indlæser studieguide…'));
  let data = await get('guide.get', { subject_id: ctx.id });
  addRefs(data.refs);

  const toc = h('nav.toc');
  const content = h('div');
  const readBar = h('div');

  const topicSections = () => data.sections.filter(s => s.topic_id);
  const renderReadBar = () => {
    const all = topicSections();
    if (!all.length) return clear(readBar);
    const done = all.filter(s => s.read_at).length;
    const complete = done === all.length;
    clear(readBar, h('div.card.card-pad', { style: { marginBottom: '16px', borderColor: complete ? 'color-mix(in srgb, var(--success) 35%, transparent)' : '' } },
      h('div.row.wrap', { style: { gap: '12px' } },
        h('div', { style: { flex: 1, minWidth: '220px' } },
          h('div', { style: { fontWeight: 600 } }, complete ? 'Studieguiden er læst – øvelserne er låst op' : 'Læs alle afsnit for at låse op for flashcards og quizzer'),
          h('div', { style: { margin: '8px 0 4px' } }, progressBar(Math.round(done / all.length * 100), complete ? 'success' : '')),
          h('div.small.muted', `${done} af ${all.length} afsnit læst`)),
        complete
          ? h('div.row', { style: { gap: '6px' } }, h('a.btn.primary', { href: `#/s/${ctx.id}/cards` }, icon('cards'), 'Flashcards'), h('a.btn', { href: `#/s/${ctx.id}/quiz` }, icon('quiz'), 'Quiz'))
          : h('button.btn', { onclick: () => { const next = all.find(s => !s.read_at); next && document.getElementById(`sec-${next.id}`)?.scrollIntoView({ behavior: 'smooth' }); } }, icon('arrowRight'), 'Næste ulæste afsnit'))));
  };

  const setRead = async (sec, read, btn) => {
    try {
      const r = await post('guide.read', { id: sec.id, read });
      sec.read_at = read ? Math.floor(Date.now() / 1000) : null;
      Object.assign(ctx.data.subject, { guide_total: r.total, guide_read: r.read });
      ctx.refreshHeader?.();
      render();
      if (read && r.read === r.total && r.total > 0) {
        toast('Studieguiden er læst! Flashcards, quizzer og prøveeksamen er nu låst op.', 'success', 7000);
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } else if (read) {
        const next = topicSections().find(s => !s.read_at);
        if (next) setTimeout(() => document.getElementById(`sec-${next.id}`)?.scrollIntoView({ behavior: 'smooth' }), 150);
      }
    } catch (e) { showError(e); }
  };

  const render = () => {
    clear(toc, h('div.nav-label', { style: { padding: '0 10px 6px' } }, 'Indhold'),
      data.sections.map(s => h('a', { href: `#sec-${s.id}`, style: s.topic_id && s.read_at ? { color: 'var(--success)' } : null, onclick: e => { e.preventDefault(); document.getElementById(`sec-${s.id}`)?.scrollIntoView({ behavior: 'smooth' }); } }, s.topic_id && s.read_at ? '✓ ' : '', s.title)));
    renderReadBar();
    clear(content, data.sections.length
      ? data.sections.map(sectionCard)
      : h('div.card', emptyState({ icon: 'book', title: 'Ingen studieguide endnu', text: 'Guiden bliver skrevet under analysen.' })));
  };

  function sectionCard(sec) {
    const card = h('div.card.guide-section', { id: `sec-${sec.id}` });
    const bodyBox = h('div');
    const extra = h('div');

    const showView = () => clear(bodyBox, md(sec.content));

    const showEditor = () => {
      const ta = h('textarea.textarea', { value: sec.content, rows: Math.min(30, Math.max(10, sec.content.split('\n').length + 2)), style: { fontFamily: 'var(--mono)', fontSize: '13px' } });
      const wrap = (before, after = before) => {
        const { selectionStart: a, selectionEnd: b, value } = ta;
        if (a === b) return toast('Markér noget tekst først.');
        ta.value = value.slice(0, a) + before + value.slice(a, b) + after + value.slice(b);
        ta.focus();
        ta.setSelectionRange(a + before.length, b + before.length);
      };
      clear(bodyBox,
        h('div.editor-tools',
          h('button.btn.sm', { onclick: () => wrap('**') }, h('b', 'B')),
          h('button.btn.sm', { onclick: () => wrap('==') }, icon('highlight'), 'Fremhæv'),
          h('button.btn.sm', { onclick: () => { const p = ta.selectionStart; ta.setRangeText('\n- ', p, p, 'end'); ta.focus(); } }, '• Liste'),
          h('span.tiny.faint', { style: { marginLeft: 'auto', alignSelf: 'center' } }, 'Markdown · ==tekst== fremhæver · [C12] er kildehenvisninger')),
        ta,
        h('div.row', { style: { marginTop: '10px' } },
          h('button.btn.primary', { onclick: async e => {
            await withBusy(e.currentTarget, async () => {
              try {
                await post('guide.save', { id: sec.id, content: ta.value });
                sec.content = ta.value;
                sec.edited = true;
                toast('Afsnittet er gemt', 'success');
                showView();
              } catch (ex) { showError(ex); }
            });
          } }, 'Gem'),
          h('button.btn', { onclick: showView }, 'Annuller')));
      ta.focus();
    };

    const transform = async (mode, language = '') => {
      const labels = { simplify: 'Forenkler', shorter: 'Forkorter', detailed: 'Uddyber', highlight: 'Fremhæver nøglepunkter', translate: 'Oversætter' };
      clear(extra, h('div.proposal', h('div.row', h('span.spinner'), h('span.muted', `${labels[mode]}…`))));
      try {
        const r = await post('guide.transform', { id: sec.id, mode, language });
        addRefs(r.refs);
        clear(extra, h('div.proposal',
          h('div.row.between', { style: { marginBottom: '10px' } }, h('b', { style: { fontSize: '13px' } }, { simplify: 'Enklere version', shorter: 'Kort resumé', detailed: 'Uddybende forklaring', highlight: 'Fremhævet version', translate: 'Oversættelse' }[mode]),
            h('div.row', { style: { gap: '6px' } },
              h('button.btn.sm.primary', { onclick: async e => {
                await withBusy(e.currentTarget, async () => {
                  await post('guide.save', { id: sec.id, content: r.content });
                  sec.content = r.content;
                  clear(extra);
                  showView();
                  toast('Afsnittet er opdateret', 'success');
                });
              } }, 'Erstat afsnit'),
              h('button.btn.sm', { onclick: async e => {
                await withBusy(e.currentTarget, async () => {
                  const merged = sec.content + '\n\n---\n\n' + r.content;
                  await post('guide.save', { id: sec.id, content: merged });
                  sec.content = merged;
                  clear(extra);
                  showView();
                });
              } }, 'Tilføj nedenunder'),
              h('button.btn.sm.ghost', { onclick: () => clear(extra) }, 'Kassér'))),
          md(r.content)));
      } catch (e) {
        clear(extra);
        showError(e);
      }
    };

    clear(card,
      h('div.sec-head',
        h('h2', sec.title),
        sec.edited ? h('span.badge', 'Redigeret') : null,
        h('button.btn.sm.ghost', { onclick: showEditor }, icon('edit'), 'Redigér'),
        h('button.btn.sm', { onclick: e => menu(e.currentTarget, [
          { label: 'Forklar mere enkelt', icon: 'bulb', onClick: () => transform('simplify') },
          { label: 'Gør det kortere', icon: 'shrink', onClick: () => transform('shorter') },
          { label: 'Mere uddybende forklaring', icon: 'expand', onClick: () => transform('detailed') },
          { label: 'Fremhæv det vigtigste', icon: 'highlight', onClick: () => transform('highlight') },
          { label: 'Oversæt…', icon: 'chat', onClick: () => {
            const sel = languageSelect('en');
            sel.querySelector('option[value="auto"]')?.remove();
            const m = modal({ title: 'Oversæt afsnit', body: h('div.field', h('label', 'Oversæt til'), sel),
              footer: [h('button.btn', { onclick: () => m.close() }, 'Annuller'), h('button.btn.primary', { onclick: () => { m.close(); transform('translate', sel.value); } }, 'Oversæt')] });
          } },
          'sep',
          sec.topic_id ? { label: 'Skriv igen ud fra kilderne', icon: 'refresh', onClick: async () => {
            if (sec.edited && !(await confirmDialog({ title: 'Skriv afsnittet igen?', message: 'Dine ændringer i afsnittet bliver erstattet af en ny version skrevet ud fra dine kilder.', confirm: 'Skriv igen' }))) return;
            clear(bodyBox, loadingBlock('Skriver igen ud fra dine kilder…'));
            try {
              const r = await post('guide.regenerate', { id: sec.id });
              addRefs(r.refs);
              sec.content = r.content;
              sec.edited = false;
            } catch (e) { showError(e); }
            showView();
          } } : { label: 'Slet note', icon: 'trash', danger: true, onClick: async () => {
            if (!(await confirmDialog({ title: 'Slet denne note?', message: 'Det kan ikke fortrydes.', confirm: 'Slet', danger: true }))) return;
            try { await post('guide.delete', { id: sec.id }); data.sections = data.sections.filter(x => x.id !== sec.id); render(); } catch (e) { showError(e); }
          } },
        ]) }, icon('wand'), 'AI-værktøjer', icon('chevronDown'))),
      bodyBox, extra,
      h('div.row.wrap', { style: { marginTop: '16px', paddingTop: '14px', borderTop: '1px solid var(--border)', gap: '10px' } },
        sec.topic_id
          ? (sec.read_at
            ? [h('span.badge.success', icon('check'), 'Læst'), h('button.btn.sm.ghost', { onclick: e => setRead(sec, false, e.currentTarget) }, 'Markér som ulæst')]
            : h('button.btn.primary', { onclick: e => setRead(sec, true, e.currentTarget) }, icon('check'), 'Jeg har læst dette afsnit'))
          : null,
        h('span.spacer'),
        h('span.tiny.faint', `Opdateret ${timeAgo(sec.updated_at)}`)));
    showView();
    return card;
  }

  const toolbar = h('div.row.wrap', { style: { marginBottom: '16px' } },
    h('div', { style: { flex: 1, minWidth: '200px' } }, h('h2', 'Studieguide'), h('div.small.muted', 'Skrevet ud fra dine kilder. Klik på en kildehenvisning for at se det oprindelige afsnit.')),
    h('button.btn', { onclick: e => withBusy(e.currentTarget, async () => {
      try {
        data = await post('guide.summary', { subject_id: ctx.id });
        addRefs(data.refs);
        render();
        toast('Resumé på én side er tilføjet øverst', 'success');
        window.scrollTo({ top: 0, behavior: 'smooth' });
      } catch (ex) { showError(ex); }
    }) }, icon('sparkles'), 'Resumé på én side'),
    h('button.btn', { onclick: async () => {
      const title = await promptDialog({ title: 'Tilføj din egen note', label: 'Titel', value: 'Mine noter' });
      if (title === null) return;
      try {
        await post('guide.add', { subject_id: ctx.id, title: title || 'Mine noter', content: '' });
        data = await get('guide.get', { subject_id: ctx.id });
        render();
      } catch (e) { showError(e); }
    } }, icon('plus'), 'Note'),
    h('button.btn', { onclick: e => menu(e.currentTarget, [
      { label: 'Eksportér som PDF', icon: 'print', onClick: () => window.open(`print.php?subject_id=${ctx.id}`, '_blank') },
      { label: 'Download som Markdown', icon: 'download', onClick: () => (location.href = `api.php?r=guide.markdown&subject_id=${ctx.id}`) },
    ]) }, icon('download'), 'Eksportér'));

  render();
  clear(el, toolbar, readBar, h('div.guide-layout', toc, content));
}
