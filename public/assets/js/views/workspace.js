import { h, clear, icon, get, post, addRefs, refChips, menu, md, progressBar, pct, emptyState, skeleton, subjectIcon, plural, showError, withBusy, trackTime, fmtDuration } from '../lib.js';
import { onJob, watchJob, jobFor } from '../app.js';
import { statusBadge, editSubjectDialog, deleteSubject } from './subjects.js';
import { guideTab } from './guide.js';
import { quizTab } from './quiz.js';
import { examTab } from './exam.js';
import { cardsTab } from './cards.js';
import { tutorTab } from './tutor.js';
import { learnTab } from './learn.js';
import { memoryTab } from './memory.js';
import { sourcesTab } from './sources.js';
import { planTab } from './plan.js';
import { shareDialog } from './share.js';

// Practice tabs that unlock only after the whole study guide has been read.
const LOCKED_UNTIL_READ = ['quiz', 'cards', 'exam', 'memory'];
const isLocked = s => s.guide_total > 0 && s.guide_read < s.guide_total;

const TABS = [
  ['overview', 'Overblik', 'chart'],
  ['plan', 'Studieplan', 'calendar'],
  ['guide', 'Studieguide', 'book'],
  ['quiz', 'AI-quiz', 'quiz'],
  ['cards', 'Flashcards', 'cards'],
  ['exam', 'Prøveeksamen', 'exam'],
  ['tutor', 'AI-lærer', 'chat'],
  ['learn', 'Lær trin for trin', 'graduation'],
  ['memory', 'Hukommelse', 'brain'],
  ['sources', 'Kilder', 'folder'],
];

export async function workspaceView(view, subjectId, tab, query) {
  if (!subjectId) throw Object.assign(new Error('Faget blev ikke fundet.'), { status: 404 });
  const page = h('div.page.wide');
  clear(view, page, );
  clear(page, skeleton(4));
  let cleanupTab = null;
  let offJob = null;

  const ctx = {
    id: subjectId,
    data: null,
    async reload() {
      ctx.data = await get('subjects.get', { id: subjectId });
      addRefs(ctx.data.refs);
      return ctx.data;
    },
    hasTopics() { return (ctx.data?.topics || []).length > 0; },
  };
  await ctx.reload();

  const header = h('div');
  const tabsEl = h('nav.tabs');
  const body = h('div');

  const renderHeader = () => {
    const s = ctx.data.subject;
    clear(header, h('div.subject-head',
      subjectIcon(s, 42),
      h('div', { style: { flex: 1, minWidth: 0 } },
        h('div.crumbs', { style: { marginBottom: '2px' } }, h('a', { href: '#/subjects' }, 'Fag')),
        h('div.row', h('h1.truncate', s.name), statusBadge(s)),
        h('div.small.faint', [
          plural(s.source_count, 'kilde', 'kilder'), plural(s.topic_count, 'emne', 'emner'), plural(s.card_count, 'flashcard', 'flashcards'),
          s.exam_date ? `prøve ${new Date(s.exam_date + 'T12:00:00').toLocaleDateString('da-DK', { weekday: 'short', month: 'short', day: 'numeric' })}` : null,
        ].filter(Boolean).join(' · '))),
      h('div.row', { style: { width: '150px' } }, h('div.meter', { style: { flex: 1 } }, progressBar(s.progress), h('span.pct', s.progress + '%'))),
      h('button.btn.icon', { 'aria-label': 'Fagindstillinger', onclick: e => menu(e.currentTarget, [
        { label: 'Redigér detaljer', icon: 'edit', onClick: () => editSubjectDialog(s, async () => { await ctx.reload(); renderHeader(); }) },
        { label: 'Tilføj kilder', icon: 'upload', onClick: () => (location.hash = `#/s/${s.id}/sources`) },
        { label: 'Del fag', icon: 'link', onClick: () => shareDialog(s) },
        { label: 'Analysér alt igen', icon: 'refresh', onClick: () => startProcessing(true) },
        'sep',
        { label: 'Slet fag', icon: 'trash', danger: true, onClick: async () => { if (await deleteSubject(s)) location.hash = '#/subjects'; } },
      ]) }, icon('more'))));
    const locked = isLocked(s);
    clear(tabsEl, TABS.map(([k, label, ic]) => h('a' + (k === tab ? '.active' : ''), { href: `#/s/${subjectId}/${k}`, title: locked && LOCKED_UNTIL_READ.includes(k) ? 'Læs studieguiden først' : null },
      icon(locked && LOCKED_UNTIL_READ.includes(k) ? 'lock' : ic), label,
      k === 'guide' && s.guide_total ? h('span.badge' + (s.guide_read >= s.guide_total ? '.success' : '.accent'), { style: { marginLeft: '2px' } }, `${s.guide_read}/${s.guide_total}`) : null)));
  };
  ctx.refreshHeader = renderHeader;

  async function startProcessing(full = false) {
    try {
      const r = await post('subjects.process', { id: subjectId, full });
      watchJob(r.job);
      if (tab !== 'overview' && tab !== 'sources') location.hash = `#/s/${subjectId}/overview`;
      else { await ctx.reload(); renderHeader(); renderTab(); }
    } catch (e) { showError(e); }
  }
  ctx.startProcessing = startProcessing;

  // Refresh when this subject's job finishes.
  offJob = onJob(async job => {
    if (job.subject_id !== subjectId) return;
    if (!['pending', 'running'].includes(job.status)) {
      await ctx.reload();
      renderHeader();
      renderTab();
    }
  });

  function renderTab() {
    if (typeof cleanupTab === 'function') { try { cleanupTab(); } catch { /* ignore */ } }
    cleanupTab = null;
    body.replaceChildren();
    const needsTopics = ['guide', 'quiz', 'cards', 'exam', 'learn'].includes(tab);
    if (needsTopics && !ctx.hasTopics()) {
      clear(body, notReady(ctx));
      return;
    }
    if (LOCKED_UNTIL_READ.includes(tab) && isLocked(ctx.data.subject)) {
      clear(body, readFirst(ctx));
      return;
    }
    const fns = { plan: planTab, overview: overviewTab, guide: guideTab, quiz: quizTab, cards: cardsTab, exam: examTab, tutor: tutorTab, learn: learnTab, memory: memoryTab, sources: sourcesTab };
    const fn = fns[tab] || overviewTab;
    Promise.resolve(fn(body, ctx, query)).then(c => { cleanupTab = c; }).catch(e => {
      clear(body, h('div.card', emptyState({ icon: 'alert', title: 'Kunne ikke indlæses', text: e.message })));
    });
    trackTime(tab, subjectId);
  }

  renderHeader();
  clear(page, header, tabsEl, body);
  renderTab();

  return () => {
    offJob?.();
    if (typeof cleanupTab === 'function') cleanupTab();
  };
}

function readFirst(ctx) {
  const s = ctx.data.subject;
  const pctRead = Math.round((s.guide_read / s.guide_total) * 100);
  return h('div.card', { style: { maxWidth: '640px', margin: '0 auto' } }, h('div.empty',
    h('div.icon-wrap', icon('lock')),
    h('h3', 'Læs din studieguide først'),
    h('p', 'Du får mere ud af flashcards og quizzer, når du allerede kender stoffet. Læs alle afsnit i studieguiden, så åbner de automatisk.'),
    h('div', { style: { maxWidth: '320px', margin: '0 auto 16px' } }, progressBar(pctRead, 'lg'), h('div.small.muted', { style: { marginTop: '6px' } }, `${s.guide_read} af ${s.guide_total} afsnit læst`)),
    h('div.row', { style: { justifyContent: 'center' } },
      h('a.btn.primary', { href: `#/s/${ctx.id}/guide` }, icon('book'), s.guide_read ? 'Læs videre' : 'Begynd at læse'),
      h('a.btn', { href: `#/s/${ctx.id}/tutor` }, icon('chat'), 'Spørg AI-læreren'))));
}

function notReady(ctx) {
  const s = ctx.data.subject;
  const job = jobFor(ctx.id) || s.job;
  if (job) {
    return h('div.card', emptyState({ icon: 'sparkles', title: 'Dit studieområde bliver bygget', text: 'Siden bliver tilgængelig, så snart analysen er færdig. Du kan følge med på fanen Overblik.', action: h('a.btn', { href: `#/s/${ctx.id}/overview` }, 'Se fremskridt') }));
  }
  if (!s.source_count) {
    return h('div.card', emptyState({ icon: 'upload', title: 'Tilføj materiale først', text: 'Upload dokumenter, slides, en YouTube-video eller en webside. Så analyserer vi det og bygger dette til dig.', action: h('a.btn.primary', { href: `#/s/${ctx.id}/sources` }, icon('upload'), 'Tilføj kilder') }));
  }
  return h('div.card', emptyState({ icon: 'sparkles', title: 'Analysér dit materiale', text: 'Dine kilder er klar til at blive analyseret.', action: h('button.btn.primary', { onclick: () => ctx.startProcessing() }, icon('sparkles'), 'Start analysen') }));
}

// -------------------------------------------------------------- processing

const STAGES = [['extract', 'Læser dine kilder'], ['map', 'Analyserer indholdet'], ['reduce', 'Organiserer emner og nøglepunkter'], ['topics', 'Skriver studieguide og flashcards']];

export function processingCard(ctx) {
  const card = h('div.card.card-pad');
  const sourcesBox = h('div');
  let job = jobFor(ctx.id) || ctx.data.subject.job;
  const render = (sources) => {
    if (!job) return;
    const idx = STAGES.findIndex(s => s[0] === job.stage);
    const failed = job.status === 'failed';
    clear(card,
      h('div.row', { style: { marginBottom: '12px' } },
        failed ? h('div.file-ic', { style: { color: 'var(--danger)' } }, icon('alert')) : h('span.spinner.lg'),
        h('div', { style: { flex: 1 } }, h('h3', failed ? 'Analysen stoppede' : 'Bygger dit studieområde'), h('div.small.muted', failed ? job.error : (job.message || 'Arbejder…'))),
        h('span', { style: { fontWeight: 650, fontSize: '18px' } }, job.progress + '%')),
      progressBar(job.progress, failed ? 'danger' : 'lg'),
      h('div.grid.cols-4', { style: { marginTop: '16px', gap: '8px' } }, STAGES.map(([k, label], i) => h('div.row.small', { style: { gap: '8px', color: i < idx ? 'var(--success)' : i === idx ? 'var(--text)' : 'var(--text-3)' } },
        i < idx ? icon('checkCircle') : i === idx && !failed ? h('span.spinner', { style: { width: '14px', height: '14px' } }) : icon('clock'), label))),
      failed ? h('div.row', { style: { marginTop: '14px' } }, h('button.btn.primary', { onclick: () => ctx.startProcessing() }, icon('refresh'), 'Prøv igen'), h('a.btn', { href: `#/s/${ctx.id}/sources` }, 'Tjek kilder')) : h('p.tiny.faint', { style: { marginTop: '14px' } }, 'Du kan godt forlade siden – behandlingen fortsætter. Store dokumenter kan tage nogle minutter.'),
      sourcesBox);
    if (sources) {
      const errs = sources.filter(s => s.status === 'error');
      clear(sourcesBox, errs.length ? h('div.notice.warning', { style: { marginTop: '12px' } }, icon('alert'), h('div', h('b', `${errs.length} ${errs.length > 1 ? 'kilder' : 'kilde'} kunne ikke læses: `), errs.map(s => `${s.title} — ${s.error}`).join(' · '))) : null);
    }
  };
  render();
  const off = onJob((j, extra) => { if (j.subject_id === ctx.id) { job = j; render(extra?.sources); } });
  card.cleanup = off;
  return card;
}

// ------------------------------------------------------------- overview tab

function section(title, count, content, opts = {}) {
  return h('div.card', { id: opts.id },
    h('div.card-head', h('h3', title), count !== null && count !== undefined ? h('span.badge', String(count)) : null, opts.action || null),
    content);
}

async function overviewTab(el, ctx) {
  const { subject: s, topics, analysis: a } = ctx.data;
  const job = jobFor(ctx.id) || s.job;
  const parts = [];
  let proc = null;
  if (job) {
    proc = processingCard(ctx);
    parts.push(proc);
  } else if (s.status === 'outdated') {
    parts.push(h('div.notice.warning', icon('refresh'), h('div', { style: { flex: 1 } }, 'Dine kilder er ændret siden sidste analyse. Analysér igen for at opdatere studieguide, emner og flashcards (dine fremskridt bevares).'),
      h('button.btn.sm.primary', { onclick: e => withBusy(e.currentTarget, () => ctx.startProcessing()) }, 'Analysér igen')));
  } else if (s.status === 'error') {
    parts.push(h('div.notice.danger', icon('alert'), h('div', { style: { flex: 1 } }, 'Den seneste analyse blev ikke færdig. Tjek dine kilder og prøv igen.'),
      h('button.btn.sm', { onclick: e => withBusy(e.currentTarget, () => ctx.startProcessing()) }, 'Prøv igen')));
  }
  if (!a) {
    if (!job) {
      parts.push(h('div.card', s.source_count
        ? emptyState({ icon: 'sparkles', title: 'Klar til analyse', text: 'Vi læser alle dine kilder, finder de vigtigste emner, definitioner, fakta og datoer og bygger din studieguide.', action: h('button.btn.primary', { onclick: e => withBusy(e.currentTarget, () => ctx.startProcessing()) }, icon('sparkles'), 'Analysér mit materiale') })
        : emptyState({ icon: 'upload', title: 'Tilføj dit undervisningsmateriale', text: 'Upload PDF’er, slides, Word- eller Excel-filer, ZIP-filer, billeder, en YouTube-video eller en webside.', action: h('a.btn.primary', { href: `#/s/${ctx.id}/sources` }, icon('upload'), 'Tilføj kilder') })));
    }
    clear(el, h('div.stack', parts));
    return () => proc?.cleanup?.();
  }

  const topicName = id => topics.find(t => t.id === id)?.name;
  const quick = h('div.grid.cols-4', [
    ['guide', 'book', 'Læs studieguiden', 'Strukturerede noter pr. emne'],
    ['learn', 'graduation', 'Lær trin for trin', 'Forklaring → spørgsmål → feedback'],
    ['quiz', 'quiz', 'Tag en quiz', 'Tilpasser sig dit niveau'],
    ['cards', 'cards', 'Flashcards', `${s.due_cards} klar nu`],
  ].map(([t, ic, title, sub]) => h('a.card.hoverable.card-pad', { href: `#/s/${ctx.id}/${t}`, style: { color: 'inherit', textDecoration: 'none' } },
    h('div.row', h('div.file-ic', { style: { color: 'var(--accent)' } }, icon(ic)), h('div', h('div', { style: { fontWeight: 600 } }, title), h('div.tiny.faint', sub))))));

  const topicList = h('div.list', topics.map(t => h('div.topic-row',
    h('div', { style: { minWidth: 0 } },
      h('div.row', h('span', { style: { fontWeight: 550 } }, t.name), h('span.imp', { title: 'Vigtighed' }, [1, 2, 3].map(i => h('i' + (i <= t.importance ? '.on' : '')))), t.understood ? h('span.badge.success', icon('check'), 'Lært') : null),
      t.summary ? h('div.small.muted', { style: { marginTop: '2px' } }, t.summary) : null,
      t.subtopics?.length ? h('div.tiny.faint', { style: { marginTop: '4px' } }, t.subtopics.slice(0, 6).join(' · ')) : null),
    h('div', h('div.meter', progressBar(pct(t.mastery), t.mastery >= 0.75 ? 'success' : t.mastery > 0 && t.mastery < 0.45 ? 'warning' : ''), h('span.pct', pct(t.mastery) + '%')),
      h('div.tiny.faint', { style: { marginTop: '3px' } }, t.correct + t.incorrect ? `${t.correct}/${t.correct + t.incorrect} rigtige${t.seconds ? ' · ' + fmtDuration(t.seconds) : ''}` : 'Ikke øvet endnu')),
    h('div.row', { style: { gap: '4px' } },
      h('a.btn.sm', { href: `#/s/${ctx.id}/learn?topic=${t.id}`, title: 'Lær dette emne' }, icon('graduation')),
      h('a.btn.sm', { href: `#/s/${ctx.id}/quiz?topic=${t.id}`, title: 'Quiz i dette emne' }, icon('quiz'))))));

  const listItems = (items, render) => h('div.list', items.map(it => h('div.list-item', { style: { display: 'block' } }, render(it))));
  const filterable = (items, render, key) => {
    if (items.length <= 12) return listItems(items, render);
    const box = h('div');
    const input = h('input.input', { placeholder: 'Filtrér…', style: { height: '32px' }, oninput: () => clear(box, listItems(items.filter(i => key(i).toLowerCase().includes(input.value.toLowerCase())), render)) });
    clear(box, listItems(items, render));
    return h('div', h('div', { style: { padding: '10px 18px', borderBottom: '1px solid var(--border)' } }, input), h('div', { style: { maxHeight: '520px', overflow: 'auto' } }, box));
  };
  const topicTag = id => topicName(id) ? h('span.badge', { style: { marginLeft: '6px' } }, topicName(id)) : null;

  parts.push(
    a.overview ? h('div.card.card-pad', h('div.row', { style: { marginBottom: '8px' } }, icon('sparkles'), h('h3', 'Hvad materialet handler om')), h('p.muted', a.overview),
      a.stats ? h('div.tiny.faint', { style: { marginTop: '10px' } }, `Bygger på ${plural(a.stats.sources, 'kilde', 'kilder')} · ${Math.round(a.stats.chars / 1000).toLocaleString('da-DK')}k tegn`) : null) : null,
    quick,
    a.unclear?.length ? h('div.notice.warning', icon('alert'), h('div', h('b', 'Uklart eller ufuldstændigt i dine kilder'), h('ul', { style: { margin: '6px 0 0', paddingLeft: '18px' } }, a.unclear.map(u => h('li', u.issue, ' ', refChips(u.refs)))))) : null,
    h('div.split',
      h('div.stack',
        section('Emner', topics.length, topicList),
        a.test_focus?.length ? section('Kommer sandsynligvis til prøven', a.test_focus.length, listItems(a.test_focus, f => [h('div', { style: { fontWeight: 550 } }, icon('target', 'faint'), ' ', f.point, ' ', refChips(f.refs)), f.why ? h('div.small.muted', f.why) : null])) : null,
        a.definitions?.length ? section('Definitioner', a.definitions.length, filterable(a.definitions, d => [h('b', d.term), topicTag(d.topic_id), h('div.small.muted', { style: { marginTop: '2px' } }, d.definition, ' ', refChips(d.refs))], d => d.term + ' ' + d.definition)) : null,
        a.key_facts?.length ? section('Vigtige fakta', a.key_facts.length, filterable(a.key_facts, f => [h('span.imp', { style: { marginRight: '8px' } }, [1, 2, 3].map(i => h('i' + (i <= (f.importance || 1) ? '.on' : '')))), f.fact, ' ', refChips(f.refs)], f => f.fact)) : null),
      h('div.stack',
        a.dates?.length ? section('Vigtige datoer', a.dates.length, h('div.card-body', h('div.timeline', a.dates.map(d => h('div.ev', h('div', { style: { fontWeight: 600 } }, d.date), h('div.small.muted', d.event, ' ', refChips(d.refs))))))) : null,
        a.people?.length ? section('Personer og navne', a.people.length, listItems(a.people, p => [h('b', p.name), h('div.small.muted', p.description, ' ', refChips(p.refs))])) : null,
        a.relationships?.length ? section('Sammenhænge mellem begreber', a.relationships.length, listItems(a.relationships, r => [h('div.small', h('b', r.from), h('span.faint', ' → '), h('b', r.to)), h('div.small.muted', r.relation, ' ', refChips(r.refs))])) : null,
        ctx.data.recent_attempts?.length ? section('Seneste resultater', null, listItems(ctx.data.recent_attempts, r => h('div.row.between.small', h('span', r.mode === 'exam' ? 'Prøveeksamen' : 'Quiz'), h('span.faint', `${r.correct}/${r.total} · ${pct(r.score)}%`)))) : null)));

  clear(el, h('div.stack', parts));
  return () => proc?.cleanup?.();
}

export { md };
