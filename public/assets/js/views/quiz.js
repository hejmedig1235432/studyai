import { h, clear, icon, get, post, addRefs, md, refChips, showError, toast, withBusy, progressBar, pct, emptyState, loadingBlock, timeAgo, escapeHtml } from '../lib.js';

export const TYPE_LABELS = { mcq: 'Multiple choice', tf: 'Sandt / falsk', fill: 'Udfyld hullet', short: 'Kort svar', exam: 'Eksamensspørgsmål' };
const DIFF = { 1: 'Let', 2: 'Middel', 3: 'Svær' };

export function tfLabel(v) { return v === 'true' ? 'Sandt' : v === 'false' ? 'Falsk' : v; }

/** Renders an answer input for a question. Returns {el, value(), disable()}. */
export function answerInput(q, { onSubmit, onChange, initial } = {}) {
  let value = initial ?? '';
  if (q.type === 'mcq' || q.type === 'tf') {
    const opts = q.type === 'tf' ? ['true', 'false'] : q.options || [];
    const buttons = opts.map((o, i) => h('button.option' + (value === o ? '.selected' : ''), { type: 'button', 'data-value': o, onclick: () => {
      value = o;
      buttons.forEach(b => b.classList.toggle('selected', b.dataset.value === o));
      onChange?.(value);
      if (onSubmit && q.type === 'tf') onSubmit(value);
    } }, h('span.key', q.type === 'tf' ? (o === 'true' ? 'T' : 'F') : String.fromCharCode(65 + i)), h('span', tfLabel(o))));
    return { el: h('div.options', buttons), value: () => value, buttons, disable: () => buttons.forEach(b => (b.disabled = true)) };
  }
  const input = q.type === 'fill'
    ? h('input.input', { value, placeholder: 'Skriv det manglende ord…', autocomplete: 'off', style: { height: '44px', fontSize: '15px' }, oninput: () => { value = input.value; onChange?.(value); }, onkeydown: e => { if (e.key === 'Enter' && onSubmit) { e.preventDefault(); onSubmit(value); } } })
    : h('textarea.textarea', { value, rows: q.type === 'exam' ? 8 : 4, placeholder: q.type === 'exam' ? 'Skriv et fuldt svar – forklar din tankegang…' : 'Svar med et par sætninger…', oninput: () => { value = input.value; onChange?.(value); }, onkeydown: e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey) && onSubmit) { e.preventDefault(); onSubmit(value); } } });
  return { el: input, value: () => value, disable: () => (input.disabled = true), focus: () => input.focus() };
}

/** Feedback block after answering: verdict, correct answer, explanation, option notes, sources. */
export function feedbackBlock(q, result, userAnswer) {
  const ok = result.is_correct;
  const partial = !ok && result.score > 0.3;
  return h('div.feedback.fade-in.' + (ok ? 'ok' : 'bad'),
    h('div.verdict', icon(ok ? 'checkCircle' : 'xCircle'), ok ? (result.score < 1 && result.score > 0 ? 'Næsten rigtigt' : 'Rigtigt') : partial ? 'Delvist rigtigt' : 'Ikke helt'),
    result.feedback ? h('p', { style: { marginBottom: '10px' } }, result.feedback) : null,
    !ok || ['short', 'exam'].includes(q.type) ? h('div', { style: { marginBottom: '10px' } },
      h('div.label', ['short', 'exam'].includes(q.type) ? 'Modelsvar' : 'Rigtigt svar'),
      h('div', { style: { fontWeight: 550, marginTop: '2px' } }, tfLabel(q.answer)),
      q.key_points?.length ? h('ul.small.muted', { style: { margin: '6px 0 0', paddingLeft: '18px' } }, q.key_points.map(k => h('li', k))) : null) : null,
    q.explanation ? h('div', h('div.label', 'Hvorfor'), md(q.explanation)) : null,
    q.type === 'mcq' && userAnswer && !ok && q.option_notes?.[userAnswer] ? h('div.small', { style: { marginTop: '8px' } }, h('b', 'Hvorfor dit valg er forkert: '), q.option_notes[userAnswer]) : null,
    q.refs?.length ? h('div.row.wrap.tiny.faint', { style: { marginTop: '10px', gap: '6px' } }, 'Kilde:', refChips(q.refs)) : null);
}

/** Marks MCQ/TF option buttons as correct / wrong after grading. */
export function markOptions(buttons, q, userAnswer) {
  (buttons || []).forEach(b => {
    b.disabled = true;
    if (b.dataset.value === q.answer) b.classList.add('correct');
    else if (b.dataset.value === userAnswer) b.classList.add('wrong');
    if (q.type === 'mcq' && q.option_notes?.[b.dataset.value] && b.dataset.value !== q.answer) {
      b.querySelector('span:last-child').appendChild(h('span.note', q.option_notes[b.dataset.value]));
    }
  });
}

export function scoreRing(score) {
  const r = 52, c = 2 * Math.PI * r;
  const color = score >= 0.7 ? 'var(--success)' : score >= 0.5 ? 'var(--warning)' : 'var(--danger)';
  const ring = h('div.score-ring');
  ring.innerHTML = `<svg viewBox="0 0 120 120"><circle cx="60" cy="60" r="${r}" fill="none" stroke="var(--surface-3)" stroke-width="10"/><circle cx="60" cy="60" r="${r}" fill="none" stroke="${color}" stroke-width="10" stroke-linecap="round" stroke-dasharray="${c}" stroke-dashoffset="${c * (1 - score)}" style="transition: stroke-dashoffset .8s ease"/></svg><div class="num">${escapeHtml(String(pct(score)))}%</div>`;
  return ring;
}

/** Shared results view for quizzes and exams. */
export function resultsView(ctx, r, { onRetry, retryLabel = 'Ny quiz', mode = 'quiz' } = {}) {
  addRefs(r.refs);
  const a = r.attempt;
  const score = a.score ?? 0;
  let onlyWrong = false;
  const list = h('div.stack');
  const renderList = () => clear(list, r.items.filter(it => !onlyWrong || !it.is_correct).map((it, i) => h('div.card.card-pad',
    h('div.q-meta', h('span.badge' + (it.is_correct ? '.success' : it.score > 0.3 ? '.warning' : '.danger'), it.is_correct ? 'Rigtigt' : it.score > 0.3 ? 'Delvist' : it.answered ? 'Forkert' : 'Ikke besvaret'),
      h('span.badge', TYPE_LABELS[it.question.type]), it.question.topic ? h('span.badge', it.question.topic) : null),
    h('div', { style: { fontWeight: 550, marginBottom: '8px' } }, it.question.prompt),
    it.question.type === 'mcq' ? h('div.small.muted', { style: { marginBottom: '6px' } }, it.question.options.map((o, j) => `${String.fromCharCode(65 + j)}. ${o}`).join('   ')) : null,
    h('div.small', h('span.faint', 'Dit svar: '), it.user_answer ? tfLabel(it.user_answer) : h('i.faint', 'intet svar')),
    feedbackBlock(it.question, { is_correct: it.is_correct, score: it.score, feedback: it.feedback }, it.user_answer))));
  renderList();
  const meta = a.meta || {};
  return h('div.stack.fade-in',
    h('div.card.card-pad', h('div.row.wrap', { style: { gap: '24px' } },
      scoreRing(score),
      h('div', { style: { flex: 1, minWidth: '220px' } },
        h('h2', score >= 0.85 ? 'Fremragende!' : score >= 0.7 ? 'Godt gået!' : score >= 0.5 ? 'Du er på vej' : 'Bliv ved med at øve'),
        h('p.muted', { style: { margin: '4px 0 12px' } }, `${a.correct} af ${a.total} rigtige`
          + (meta.time_used_sec ? ` · ${Math.floor(meta.time_used_sec / 60)} min ${meta.time_used_sec % 60} sek` : '')
          + (meta.overtime ? ' · afleveret efter tidsfristen' : '')
          + (a.finished_at ? ` · ${timeAgo(a.finished_at)}` : '')),
        h('div.row.wrap',
          onRetry ? h('button.btn.primary', { onclick: onRetry }, icon('refresh'), retryLabel) : null,
          r.weak_topics.length ? h('a.btn', { href: `#/s/${ctx.id}/quiz?topic=${r.weak_topics[0].topic_id}` }, icon('target'), 'Øv dit svageste emne') : null,
          r.weak_topics.length ? h('a.btn', { href: `#/s/${ctx.id}/learn?topic=${r.weak_topics[0].topic_id}` }, icon('graduation'), 'Lær det trin for trin') : null)))),
    h('div.grid.cols-2',
      h('div.card', h('div.card-head', h('h3', 'Resultat pr. emne')),
        h('div.list', r.by_topic.map(t => h('div.list-item', { style: { display: 'block' } },
          h('div.row.between.small', h('span', { style: { fontWeight: 500 } }, t.name), h('span.faint', `${t.percent}% · ${t.total} spm.`)),
          h('div', { style: { marginTop: '6px' } }, progressBar(t.percent, t.percent >= 70 ? 'success' : t.percent >= 50 ? 'warning' : 'danger')))))),
      h('div.card', h('div.card-head', h('h3', 'Fokusér næste gang på')),
        r.weak_topics.length
          ? h('div.list', r.weak_topics.map(t => h('div.list-item', icon('target', 'faint'), h('span', { style: { flex: 1 } }, t.name), h('a.btn.sm', { href: `#/s/${ctx.id}/quiz?topic=${t.topic_id}` }, 'Quiz'), h('a.btn.sm', { href: `#/s/${ctx.id}/learn?topic=${t.topic_id}` }, 'Lær'))))
          : h('div.card-body.muted.small', 'Ingen svage emner – flot! Prøv en højere sværhedsgrad næste gang.'))),
    h('div.row.between', h('h2', 'Gennemgå svarene'), h('label.check', h('input', { type: 'checkbox', onchange: e => { onlyWrong = e.target.checked; renderList(); } }), 'Vis kun fejl')),
    list);
}

// -------------------------------------------------------------------- tab

export async function quizTab(el, ctx, query) {
  const topics = ctx.data.topics;
  const settings = {
    count: 10,
    types: ['mcq', 'tf', 'fill', 'short'],
    difficulty: 'adaptive',
    topic_ids: query.topic ? [+query.topic] : [],
  };

  if (query.attempt) {
    clear(el, loadingBlock('Indlæser resultater…'));
    try {
      const r = await get('quiz.results', { attempt_id: query.attempt });
      clear(el, resultsView(ctx, r, { onRetry: () => (location.hash = `#/s/${ctx.id}/quiz`) }));
    } catch (e) { clear(el, h('div.card', emptyState({ icon: 'alert', title: 'Resultaterne kunne ikke indlæses', text: e.message }))); }
    return;
  }

  const setup = () => {
    const chip = (label, on, toggle) => h('button.chip' + (on ? '.on' : ''), { type: 'button', onclick: e => { toggle(); e.currentTarget.classList.toggle('on'); } }, label);
    const countLabel = h('b', String(settings.count));
    const historyBox = h('div');
    get('quiz.history', { subject_id: ctx.id, mode: 'quiz' }).then(r => {
      if (!r.attempts.length) return;
      clear(historyBox, h('div.card', h('div.card-head', h('h3', 'Tidligere quizzer')),
        h('div.list', r.attempts.slice(0, 8).map(a => h('a.list-item.click', { href: `#/s/${ctx.id}/quiz?attempt=${a.id}`, style: { color: 'inherit', textDecoration: 'none' } },
          h('span.badge' + (a.score >= 0.7 ? '.success' : a.score >= 0.5 ? '.warning' : '.danger'), pct(a.score) + '%'),
          h('span.small', `${a.correct}/${a.total} rigtige`), h('span.spacer'), h('span.tiny.faint', timeAgo(a.finished_at)))))));
    }).catch(() => {});
    const startBtn = h('button.btn.primary.lg', { onclick: () => start(startBtn) }, icon('play'), 'Start quiz');
    clear(el, h('div.split',
      h('div.card.card-pad',
        h('h2', { style: { marginBottom: '4px' } }, 'AI-quiz'),
        h('p.small.muted', { style: { marginBottom: '20px' } }, 'Nye spørgsmål fra dit materiale hver gang, med feedback med det samme. Adaptiv tilstand tilpasser sværhedsgraden efter, hvor godt du kender hvert emne.'),
        h('div.field', h('label', 'Antal spørgsmål: ', countLabel),
          h('input', { type: 'range', min: 3, max: 30, value: settings.count, oninput: e => { settings.count = +e.target.value; countLabel.textContent = e.target.value; } })),
        h('div.field', h('label', 'Spørgsmålstyper'),
          h('div.chips', Object.entries(TYPE_LABELS).map(([k, label]) => chip(label, settings.types.includes(k), () => {
            settings.types = settings.types.includes(k) ? settings.types.filter(t => t !== k) : [...settings.types, k];
          })))),
        h('div.field', h('label', 'Sværhedsgrad'),
          (() => {
            const seg = h('div.seg', [['adaptive', 'Adaptiv'], ['easy', 'Let'], ['medium', 'Middel'], ['hard', 'Svær'], ['exam', 'Eksamensniveau']].map(([k, label]) =>
              h('button' + (settings.difficulty === k ? '.on' : ''), { type: 'button', onclick: e => { settings.difficulty = k; seg.querySelectorAll('button').forEach(b => b.classList.remove('on')); e.currentTarget.classList.add('on'); } }, label)));
            return seg;
          })()),
        h('div.field', h('label', 'Emner'), h('div.hint', { style: { marginTop: '-2px' } }, 'Ingen valgt = alle emner, med vægt på dine svage punkter.'),
          h('div.chips', topics.map(t => chip(t.name, settings.topic_ids.includes(t.id), () => {
            settings.topic_ids = settings.topic_ids.includes(t.id) ? settings.topic_ids.filter(x => x !== t.id) : [...settings.topic_ids, t.id];
          })))),
        h('div', { style: { marginTop: '8px' } }, startBtn)),
      h('div.stack',
        h('div.card.card-pad', h('div.row', { style: { marginBottom: '8px' } }, icon('info', 'faint'), h('h3', 'Sådan virker det')),
          h('ul.small.muted', { style: { margin: 0, paddingLeft: '18px' } },
            h('li', 'Hvert spørgsmål er skrevet ud fra dine kilder og henviser til det afsnit, det kommer fra.'),
            h('li', 'Spørgsmål, du svarede forkert på, kommer igen senere.'),
            h('li', 'Tidligere stillede spørgsmål undgås, så hver quiz er ny.'))),
        historyBox)));
  };

  async function start(btn) {
    if (!settings.types.length) return showError(new Error('Vælg mindst én spørgsmålstype.'));
    clear(el, h('div.card', loadingBlock('Skriver din quiz…', 'Læser dit materiale og laver spørgsmål – det tager typisk 10–60 sekunder.')));
    try {
      const r = await post('quiz.start', { subject_id: ctx.id, ...settings });
      addRefs(r.refs);
      run(r.attempt, r.questions);
    } catch (e) {
      showError(e);
      setup();
    }
  }

  function run(attempt, questions) {
    let i = 0;
    let correct = 0;
    const wrap = h('div', { style: { maxWidth: '760px', margin: '0 auto' } });
    clear(el, wrap);

    const show = () => {
      const q = questions[i];
      let answered = false;
      const feedback = h('div');
      const nextBtn = h('button.btn.primary.hidden', { onclick: () => { i++; i < questions.length ? show() : finish(); } }, i + 1 < questions.length ? 'Næste spørgsmål' : 'Se resultatet', icon('arrowRight'));
      const checkBtn = h('button.btn.primary', { onclick: () => { const v = input.value(); if (!String(v).trim()) return toast('Vælg eller skriv et svar først.'); submit(v); } }, 'Tjek svar');
      const skipBtn = h('button.btn.ghost', { onclick: () => submit('') }, 'Ved ikke');

      const submit = async ans => {
        if (answered) return;
        answered = true;
        input.disable();
        checkBtn.disabled = true;
        skipBtn.disabled = true;
        clear(feedback, h('div.row.muted.small', { style: { marginTop: '16px' } }, h('span.spinner'), ['short', 'exam'].includes(q.type) ? 'Retter dit svar…' : 'Tjekker…'));
        try {
          const r = await post('quiz.answer', { attempt_id: attempt.id, question_id: q.id, answer: ans });
          addRefs(r.refs);
          if (r.result.is_correct) correct++;
          markOptions(input.buttons, r.question, ans);
          clear(feedback, feedbackBlock(r.question, r.result, ans));
          checkBtn.classList.add('hidden');
          skipBtn.classList.add('hidden');
          nextBtn.classList.remove('hidden');
          nextBtn.focus();
        } catch (e) {
          answered = false;
          checkBtn.disabled = false;
          skipBtn.disabled = false;
          clear(feedback);
          showError(e);
        }
      };

      const input = answerInput(q, { onSubmit: v => (q.type === 'tf' || q.type === 'fill') && String(v).trim() && submit(v) });
      if (q.type === 'mcq') input.buttons.forEach(b => b.addEventListener('dblclick', () => submit(b.dataset.value)));
      const onKey = e => {
        if (answered && e.key === 'Enter' && !e.target.closest('textarea')) { e.preventDefault(); nextBtn.click(); }
        if (!answered && q.type === 'mcq' && /^[1-6]$/.test(e.key) && !e.target.closest('input,textarea')) input.buttons[+e.key - 1]?.click();
      };
      document.addEventListener('keydown', onKey);
      nextBtn.addEventListener('click', () => document.removeEventListener('keydown', onKey), { once: true });

      clear(wrap,
        h('div.row', { style: { marginBottom: '14px' } },
          h('span.small.muted', `Spørgsmål ${i + 1} af ${questions.length}`),
          h('div', { style: { flex: 1 } }, progressBar((i / questions.length) * 100)),
          h('span.small.faint', `${correct} rigtige`)),
        h('div.card.q-card.fade-in',
          h('div.q-meta', h('span.badge.accent', TYPE_LABELS[q.type]), q.topic ? h('span.badge', q.topic) : null, h('span.badge', DIFF[q.difficulty] || 'Medium')),
          h('div.q-prompt', q.prompt),
          input.el,
          feedback,
          h('div.row', { style: { marginTop: '18px' } },
            q.type !== 'tf' ? checkBtn : null, skipBtn, h('span.spacer'), nextBtn,
            q.type === 'mcq' ? h('span.tiny.faint', 'Tast 1–4 for at vælge') : null)));
      input.focus?.();
    };

    const finish = async () => {
      clear(wrap, loadingBlock('Udregner dit resultat…'));
      try {
        const r = await post('quiz.finish', { attempt_id: attempt.id });
        clear(el, resultsView(ctx, r, { onRetry: () => setup() }));
        window.scrollTo({ top: 0 });
      } catch (e) { showError(e); }
    };

    show();
  }

  setup();
}
