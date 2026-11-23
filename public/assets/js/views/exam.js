import { h, clear, icon, get, post, addRefs, showError, toast, withBusy, progressBar, pct, emptyState, loadingBlock, timeAgo, confirmDialog, debounce } from '../lib.js';
import { watchJob, onJob } from '../app.js';
import { TYPE_LABELS, answerInput, resultsView } from './quiz.js';

export async function examTab(el, ctx, query) {
  const topics = ctx.data.topics;
  let timerId = null;
  let offJob = null;
  const cleanup = () => { clearInterval(timerId); offJob?.(); };

  const settings = { count: 20, types: ['mcq', 'tf', 'fill', 'short', 'exam'], difficulty: 'exam', time_limit_min: 30, timed: true, topic_ids: [] };

  async function open(attemptId) {
    cleanup();
    clear(el, loadingBlock('Indlæser eksamen…'));
    let r;
    try { r = await get('exam.get', { attempt_id: attemptId }); } catch (e) { showError(e); return setup(); }
    if (r.state === 'finished') return showResults(r);
    if (r.state === 'generating') return generating(attemptId, r.job);
    if (r.state === 'ready') return ready(attemptId, r);
    return take(attemptId, r);
  }

  function setup() {
    cleanup();
    const chip = (label, on, toggle) => h('button.chip' + (on ? '.on' : ''), { type: 'button', onclick: e => { toggle(); e.currentTarget.classList.toggle('on'); } }, label);
    const countLabel = h('b', String(settings.count));
    const minutes = h('input.input', { type: 'number', min: 5, max: 300, value: settings.time_limit_min, style: { width: '90px' }, oninput: e => (settings.time_limit_min = +e.target.value) });
    const history = h('div');
    get('quiz.history', { subject_id: ctx.id, mode: 'exam' }).then(r => {
      if (!r.attempts.length) return;
      clear(history, h('div.card', h('div.card-head', h('h3', 'Tidligere eksamener')),
        h('div.list', r.attempts.slice(0, 8).map(a => h('a.list-item.click', { href: `#/s/${ctx.id}/exam?attempt=${a.id}`, style: { color: 'inherit', textDecoration: 'none' } },
          h('span.badge' + (a.score >= 0.7 ? '.success' : a.score >= 0.5 ? '.warning' : '.danger'), pct(a.score) + '%'),
          h('span.small', `${a.correct}/${a.total} rigtige`), h('span.spacer'), h('span.tiny.faint', timeAgo(a.finished_at)))))));
    }).catch(() => {});
    const createBtn = h('button.btn.primary.lg', { onclick: () => create(createBtn) }, icon('exam'), 'Lav prøveeksamen');
    clear(el, h('div.split',
      h('div.card.card-pad',
        h('h2', { style: { marginBottom: '4px' } }, 'Prøveeksamen'),
        h('p.small.muted', { style: { marginBottom: '20px' } }, 'En realistisk prøve i dit materiale: ingen feedback før du afleverer, valgfri tidsgrænse, og bagefter en grundig gennemgang med forklaringer og dine svage emner.'),
        h('div.field', h('label', 'Antal spørgsmål: ', countLabel),
          h('input', { type: 'range', min: 5, max: 60, value: settings.count, oninput: e => { settings.count = +e.target.value; countLabel.textContent = e.target.value; } })),
        h('div.field', h('label', 'Spørgsmålstyper'), h('div.chips', Object.entries(TYPE_LABELS).map(([k, label]) => chip(label, settings.types.includes(k), () => {
          settings.types = settings.types.includes(k) ? settings.types.filter(t => t !== k) : [...settings.types, k];
        })))),
        h('div.field', h('label', 'Sværhedsgrad'), (() => {
          const seg = h('div.seg', [['adaptive', 'Adaptiv'], ['easy', 'Let'], ['medium', 'Middel'], ['hard', 'Svær'], ['exam', 'Eksamensniveau']].map(([k, label]) =>
            h('button' + (settings.difficulty === k ? '.on' : ''), { type: 'button', onclick: e => { settings.difficulty = k; seg.querySelectorAll('button').forEach(b => b.classList.remove('on')); e.currentTarget.classList.add('on'); } }, label)));
          return seg;
        })()),
        h('div.field', h('label', 'Tidsgrænse'),
          h('div.row', h('label.check', h('input', { type: 'checkbox', checked: settings.timed, onchange: e => { settings.timed = e.target.checked; minutes.disabled = !e.target.checked; } }), 'Med tid'), minutes, h('span.muted.small', 'minutter'))),
        h('div.field', h('label', 'Emner'), h('div.hint', 'Ingen valgt = hele faget.'), h('div.chips', topics.map(t => chip(t.name, settings.topic_ids.includes(t.id), () => {
          settings.topic_ids = settings.topic_ids.includes(t.id) ? settings.topic_ids.filter(x => x !== t.id) : [...settings.topic_ids, t.id];
        })))),
        createBtn),
      h('div.stack', h('div.card.card-pad', h('div.row', { style: { marginBottom: '8px' } }, icon('info', 'faint'), h('h3', 'Eksamenstips')),
        h('ul.small.muted', { style: { margin: 0, paddingLeft: '18px' } },
          h('li', 'Tag det som den rigtige eksamen: ingen noter, ingen AI-lærer.'),
          h('li', 'Markér spørgsmål, du er i tvivl om, og vend tilbage til dem.'),
          h('li', 'Dine svar gemmes automatisk undervejs.'),
          h('li', 'Åbne svar rettes af AI ud fra modelsvar fra dit materiale.'))), history)));
  }

  async function create(btn) {
    if (!settings.types.length) return toast('Vælg mindst én spørgsmålstype.', 'error');
    await withBusy(btn, async () => {
      try {
        const r = await post('exam.create', { subject_id: ctx.id, count: settings.count, types: settings.types, difficulty: settings.difficulty, time_limit_min: settings.timed ? settings.time_limit_min : 0, topic_ids: settings.topic_ids });
        history.replaceState(null, '', `#/s/${ctx.id}/exam?attempt=${r.attempt_id}`);
        watchJob({ id: r.job_id, subject_id: ctx.id, status: 'pending', progress: 0 });
        generating(r.attempt_id, { id: r.job_id, progress: 0, message: 'Skriver din eksamen' });
      } catch (e) { showError(e); }
    });
  }

  function generating(attemptId, job) {
    const bar = h('div', progressBar(job?.progress || 0, 'lg'));
    const msg = h('div.small.muted', job?.message || 'Skriver spørgsmål…');
    clear(el, h('div.card', { style: { maxWidth: '620px', margin: '0 auto' } }, h('div.loading-block', h('div.spinner.lg'), h('h3', 'Forbereder din eksamen'), msg, h('div', { style: { width: '100%', maxWidth: '360px' } }, bar),
      h('div.tiny.faint', 'Spørgsmålene skrives i portioner ud fra dit materiale. Det tager typisk 20–60 sekunder pr. 10 spørgsmål.'))));
    if (job?.id) watchJob({ id: job.id, subject_id: ctx.id, status: 'pending', progress: job.progress || 0 });
    offJob = onJob(j => {
      if (j.id !== job?.id) return;
      clear(bar, progressBar(j.progress, 'lg'));
      msg.textContent = j.message || '';
      if (j.status === 'done') open(attemptId);
      if (j.status === 'failed') clear(el, h('div.card', emptyState({ icon: 'alert', title: 'Eksamen kunne ikke laves', text: j.error || 'Prøv igen.', action: h('button.btn.primary', { onclick: setup }, 'Tilbage') })));
    });
  }

  function ready(attemptId, r) {
    const a = r.attempt;
    clear(el, h('div.card.card-pad', { style: { maxWidth: '620px', margin: '0 auto', textAlign: 'center' } },
      h('div.empty', { style: { padding: '20px' } },
        h('div.icon-wrap', icon('exam')), h('h2', 'Din eksamen er klar'),
        h('p', `${a.total} spørgsmål` + (a.time_limit_sec ? ` · ${Math.round(a.time_limit_sec / 60)} minutter` : ' · ingen tidsgrænse')),
        h('div.chips', { style: { justifyContent: 'center', marginBottom: '20px' } }, Object.entries(r.questions.reduce((m, q) => ((m[q.type] = (m[q.type] || 0) + 1), m), {})).map(([t, n]) => h('span.badge', `${n} × ${TYPE_LABELS[t]}`))),
        h('button.btn.primary.lg', { onclick: async e => withBusy(e.currentTarget, async () => {
          try { take(attemptId, await post('exam.begin', { attempt_id: attemptId })); } catch (ex) { showError(ex); }
        }) }, icon('play'), a.time_limit_sec ? 'Start uret' : 'Start eksamen'))));
  }

  function take(attemptId, r) {
    const qs = r.questions;
    const answers = { ...(r.draft || {}) };
    const flags = new Set(r.flags || []);
    const a = r.attempt;
    const offset = Math.floor(Date.now() / 1000) - a.now;
    let idx = qs.findIndex(q => !answers[q.id]);
    if (idx < 0) idx = 0;
    let submitting = false;

    const save = debounce(() => post('exam.save', { attempt_id: attemptId, answers, flags: [...flags] }).catch(() => {}), 800);
    const nav = h('div.qnav');
    const qBox = h('div');
    const timerEl = h('div.timer');
    const answeredEl = h('span.small.muted');

    const renderNav = () => {
      clear(nav, qs.map((q, i) => h('button' + (answers[q.id] ? '.answered' : '') + (flags.has(q.id) ? '.flag' : '') + (i === idx ? '.current' : ''), { onclick: () => { idx = i; renderQ(); } }, String(i + 1))));
      answeredEl.textContent = `${Object.values(answers).filter(v => String(v).trim()).length} af ${qs.length} besvaret`;
    };

    const renderQ = () => {
      const q = qs[idx];
      const input = answerInput(q, { initial: answers[q.id] || '', onChange: v => { answers[q.id] = v; save(); renderNav(); } });
      clear(qBox, h('div.card.q-card.fade-in',
        h('div.q-meta', h('span.small.muted', `Spørgsmål ${idx + 1} af ${qs.length}`), h('span.badge', TYPE_LABELS[q.type]), h('span.spacer'),
          h('button.btn.sm' + (flags.has(q.id) ? '.primary' : ''), { onclick: () => { flags.has(q.id) ? flags.delete(q.id) : flags.add(q.id); save(); renderQ(); renderNav(); } }, icon('flag'), flags.has(q.id) ? 'Markeret' : 'Markér')),
        h('div.q-prompt', q.prompt),
        input.el,
        h('div.row', { style: { marginTop: '20px' } },
          h('button.btn', { disabled: idx === 0, onclick: () => { idx--; renderQ(); renderNav(); } }, icon('arrowLeft'), 'Forrige'),
          h('span.spacer'),
          idx < qs.length - 1
            ? h('button.btn.primary', { onclick: () => { idx++; renderQ(); renderNav(); } }, 'Næste', icon('arrowRight'))
            : h('button.btn.primary', { onclick: () => submit(false) }, icon('check'), 'Afslut og aflevér'))));
      input.focus?.();
      renderNav();
    };

    const tick = () => {
      if (!a.time_limit_sec) {
        const used = Math.floor(Date.now() / 1000) - offset - a.started_at;
        clear(timerEl, icon('clock'), `${Math.floor(used / 60)}:${String(used % 60).padStart(2, '0')}`);
        return;
      }
      const left = a.started_at + a.time_limit_sec - (Math.floor(Date.now() / 1000) - offset);
      if (left <= 0) {
        clear(timerEl, icon('clock'), '0:00');
        if (!submitting) { toast('Tiden er gået – din eksamen afleveres.', 'info'); submit(true); }
        return;
      }
      timerEl.classList.toggle('low', left <= 300);
      clear(timerEl, icon('clock'), `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`);
    };

    async function submit(force) {
      if (submitting) return;
      const unanswered = qs.filter(q => !String(answers[q.id] || '').trim()).length;
      if (!force) {
        const ok = await confirmDialog({ title: 'Aflevér din eksamen?', message: unanswered ? `Du har ${unanswered} ubesvarede spørgsmål. De tæller som forkerte.` : 'Du har besvaret alle spørgsmål. Klar til at se dit resultat?', confirm: 'Aflevér' });
        if (!ok) return;
      }
      submitting = true;
      clearInterval(timerId);
      clear(el, h('div.card', loadingBlock('Retter din eksamen…', 'Åbne svar sammenlignes med modelsvar fra dit materiale.')));
      try {
        const res = await post('exam.submit', { attempt_id: attemptId, answers });
        showResults(res);
      } catch (e) {
        submitting = false;
        showError(e);
        take(attemptId, { ...r, draft: answers, flags: [...flags] });
      }
    }

    clear(el, h('div.split',
      qBox,
      h('div.stack', { style: { position: 'sticky', top: '16px' } },
        h('div.card.card-pad', h('div.row.between', { style: { marginBottom: '12px' } }, timerEl, answeredEl), progressBar(0), nav,
          h('div.tiny.faint', { style: { marginTop: '10px' } }, 'Svarene gemmes automatisk. Orange prik = markeret.')),
        h('button.btn.primary.block', { onclick: () => submit(false) }, icon('check'), 'Aflevér eksamen'))));
    renderQ();
    tick();
    timerId = setInterval(() => {
      tick();
      const done = Object.values(answers).filter(v => String(v).trim()).length;
      el.querySelector('.stack .progress > i')?.style.setProperty('width', (done / qs.length) * 100 + '%');
    }, 1000);
  }

  function showResults(r) {
    cleanup();
    addRefs(r.refs);
    clear(el, resultsView(ctx, r, { onRetry: () => { location.hash = `#/s/${ctx.id}/exam`; }, retryLabel: 'Ny prøveeksamen', mode: 'exam' }));
    window.scrollTo({ top: 0 });
  }

  if (query.attempt) await open(+query.attempt);
  else setup();
  return cleanup;
}
