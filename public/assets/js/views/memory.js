import { h, clear, icon, get, post, addRefs, showError, toast, emptyState, loadingBlock, progressBar, pct, trackTime } from '../lib.js';
import { studySession } from './cards.js';
import { answerInput, feedbackBlock, markOptions, TYPE_LABELS } from './quiz.js';

/** Mistake review: re-asks questions the student got wrong, on a spaced schedule. */
function questionReview(questions, onDone) {
  const wrap = h('div', { style: { maxWidth: '760px', margin: '0 auto' } });
  let i = 0, right = 0;
  const show = () => {
    if (i >= questions.length) {
      clear(wrap, h('div.card', emptyState({ icon: 'trophy', title: 'Fejl-gennemgang færdig', text: `${right} af ${questions.length} rigtige denne gang. Rigtige svar kommer igen senere, forkerte kommer igen i morgen.`, action: h('button.btn.primary', { onclick: onDone }, 'Færdig') })));
      return;
    }
    const q = questions[i];
    let answered = false;
    const fb = h('div');
    const next = h('button.btn.primary.hidden', { onclick: () => { i++; show(); } }, 'Næste', icon('arrowRight'));
    const input = answerInput(q, {});
    const check = h('button.btn.primary', { onclick: async () => {
      const v = input.value();
      if (!String(v).trim()) return toast('Vælg eller skriv et svar først.');
      if (answered) return;
      answered = true;
      check.disabled = true;
      input.disable();
      try {
        const r = await post('quiz.answer', { question_id: q.id, answer: v });
        addRefs(r.refs);
        if (r.result.is_correct) right++;
        markOptions(input.buttons, r.question, v);
        clear(fb, feedbackBlock(r.question, r.result, v));
        check.classList.add('hidden');
        next.classList.remove('hidden');
      } catch (e) { answered = false; check.disabled = false; showError(e); }
    } }, 'Tjek');
    clear(wrap,
      h('div.row', { style: { marginBottom: '14px' } }, h('span.small.muted', `Fejl-gennemgang · ${i + 1} af ${questions.length}`), h('div', { style: { flex: 1 } }, progressBar((i / questions.length) * 100))),
      h('div.card.q-card.fade-in', h('div.q-meta', h('span.badge.danger', 'Du svarede forkert sidst'), h('span.badge', TYPE_LABELS[q.type]), q.topic ? h('span.badge', q.topic) : null),
        h('div.q-prompt', q.prompt), input.el, fb, h('div.row', { style: { marginTop: '18px' } }, check, h('span.spacer'), next)));
  };
  show();
  return wrap;
}

/** Memory training dashboard + daily review (global when subjectId is null). */
async function memoryPanel(el, subjectId) {
  let session = null;
  const load = async () => {
    session?.cleanup?.();
    clear(el, loadingBlock('Indlæser din hukommelsesprofil…'));
    const d = await get('memory', subjectId ? { subject_id: subjectId } : {});
    const dueTotal = d.due_cards + d.due_questions;
    const maxSched = Math.max(1, ...d.schedule.map(s => s.count));

    const startReview = async () => {
      clear(el, loadingBlock('Gør dagens gentagelse klar…'));
      try {
        const [cards, qs] = await Promise.all([
          get('cards.session', { subject_id: subjectId || 0, mode: 'smart', limit: 40 }),
          get('quiz.review', { subject_id: subjectId || 0, limit: 15 }),
        ]);
        addRefs(cards.refs);
        addRefs(qs.refs);
        const afterCards = () => {
          if (qs.questions.length) clear(el, back(), questionReview(qs.questions, load));
          else load();
        };
        if (cards.cards.length) {
          session = studySession(cards.cards, { onDone: afterCards });
          clear(el, back(), session);
        } else afterCards();
      } catch (e) { showError(e); load(); }
    };
    const back = () => h('div.row', { style: { marginBottom: '16px' } }, h('button.btn.ghost', { onclick: load }, icon('arrowLeft'), 'Hukommelsesoversigt'));

    clear(el,
      h('div.card.card-pad', { style: { marginBottom: '16px' } }, h('div.row.wrap', { style: { gap: '16px' } },
        h('div.file-ic', { style: { width: '44px', height: '44px', borderRadius: '12px', color: 'var(--accent)' } }, icon('brain')),
        h('div', { style: { flex: 1, minWidth: '220px' } },
          h('h2', dueTotal ? 'Dagens gentagelse er klar' : d.new_cards ? 'Lær nye kort' : 'Du er helt opdateret'),
          h('div.small.muted', dueTotal
            ? `${d.due_cards} flashcards klar · ${d.due_questions} fejl at prøve igen · ${d.new_cards} nye kort venter`
            : d.new_cards ? `${d.new_cards} nye kort venter på første gennemgang.` : 'Intet skal gentages nu. Gentagelser planlægges lige før du sandsynligvis glemmer det.'),
          d.reviewed_today ? h('div.tiny.faint', { style: { marginTop: '4px' } }, `${d.reviewed_today} kort gentaget i dag`) : null),
        h('button.btn.primary.lg', { onclick: startReview, disabled: !dueTotal && !d.new_cards }, icon('play'), dueTotal ? 'Start dagens gentagelse' : 'Øv nye kort'))),
      h('div.grid.cols-4', { style: { marginBottom: '16px' } },
        h('div.card.stat', h('div.k', icon('brain'), 'Gns. hukommelse'), h('div.v', d.average_retention === null ? '—' : pct(d.average_retention) + '%'), h('div.d', 'Hvor meget du husker lige nu (anslået)')),
        h('div.card.stat', h('div.k', icon('cards'), 'Flashcards klar'), h('div.v', String(d.due_cards))),
        h('div.card.stat', h('div.k', icon('repeat'), 'Fejl at prøve igen'), h('div.v', String(d.due_questions))),
        h('div.card.stat', h('div.k', icon('layers'), 'Kort i alt'), h('div.v', String(d.total_cards)))),
      h('div.split',
        h('div.stack',
          h('div.card', h('div.card-head', h('h3', 'Hukommelse pr. emne'), h('span.tiny.faint', 'Svageste først')),
            d.strength.length ? h('div.list', d.strength.map(t => h('div.list-item', { style: { display: 'block' } },
              h('div.row.between.small', h('span', { style: { fontWeight: 500 } }, t.name), h('span.faint', `${pct(t.strength)}% · ${t.cards} kort`)),
              subjectId ? null : h('div.tiny.faint', t.subject),
              h('div', { style: { marginTop: '6px' } }, progressBar(pct(t.strength), t.strength >= 0.8 ? 'success' : t.strength >= 0.6 ? '' : t.strength >= 0.4 ? 'warning' : 'danger')))))
              : h('div.card-body.small.muted', 'Gentag nogle flashcards for at se, hvor godt du husker hvert emne.')),
          h('div.card', h('div.card-head', h('h3', 'Ved at blive glemt'), h('span.tiny.faint', 'Sandsynligvis glemt')),
            d.forgotten.length ? h('div.list', d.forgotten.map(f => h('div.list-item',
              h('span.badge.danger', pct(f.retention) + '%'), h('div', { style: { flex: 1, minWidth: 0 } }, h('div.truncate', f.front), h('div.tiny.faint', [f.topic, subjectId ? null : f.subject].filter(Boolean).join(' · '))))))
              : h('div.card-body.small.muted', 'Intet er ved at blive glemt lige nu.'))),
        h('div.card', h('div.card-head', h('h3', 'Gentagelsesplan'), h('span.tiny.faint', 'Næste 14 dage')),
          h('div.card-body', h('div.bars', { style: { height: '140px' } }, d.schedule.map((s, i) => h('div.b' + (i === 0 ? '.today' : ''), { title: `${s.day}: ${s.count} gentagelser` },
            h('i' + (s.count ? '' : '.zero'), { style: { height: Math.max(3, Math.round(s.count / maxSched * 110)) + 'px' } }),
            h('span', i === 0 ? 'I dag' : new Date(s.day + 'T12:00:00').toLocaleDateString('da-DK', { day: 'numeric' }))))),
            h('p.tiny.faint', { style: { marginTop: '12px' } }, 'Spaced repetition: hver gang du husker et kort, bliver der længere til næste gentagelse. Kort, du synes er svære, kommer hurtigt igen.')))));
  };
  await load();
  return () => session?.cleanup?.();
}

export async function memoryTab(el, ctx) {
  return memoryPanel(el, ctx.id);
}

export async function memoryView(view) {
  const body = h('div');
  clear(view, h('div.page', h('div.page-head', h('div', h('h1', 'Hukommelsestræning'), h('div.sub', 'Daglig gentagelse på tværs af alle dine fag.'))), body));
  trackTime('memory', 0);
  return memoryPanel(body, null);
}
