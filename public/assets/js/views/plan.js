import { h, clear, icon, get, post, showError, toast, withBusy, loadingBlock, progressBar, emptyState } from '../lib.js';

/** Where each kind of plan task is done. */
export function taskHref(t, subjectId) {
  const s = `#/s/${subjectId}`;
  return {
    read: `${s}/guide`,
    learn: `${s}/learn?topic=${t.topic_id}`,
    quiz: `${s}/quiz?topic=${t.topic_id}`,
    cards: `${s}/cards?study=smart`,
    exam: `${s}/exam`,
    review: `#/memory`,
    final: `${s}/guide`,
  }[t.type] || s;
}

export const TASK_ICONS = { read: 'book', learn: 'graduation', quiz: 'quiz', cards: 'cards', exam: 'exam', review: 'repeat', final: 'target' };

function fmtDay(date) {
  return new Date(date + 'T12:00:00').toLocaleDateString('da-DK', { weekday: 'long', day: 'numeric', month: 'long' });
}

export async function planTab(el, ctx) {
  clear(el, loadingBlock('Indlæser studieplan…'));
  let d = await get('plan.get', { subject_id: ctx.id });

  const dateInput = h('input.input', { type: 'date', value: d.exam_date || '', min: new Date().toISOString().slice(0, 10), style: { width: 'auto' } });
  const makeBtn = h('button.btn.primary', { onclick: () => withBusy(makeBtn, async () => {
    if (!dateInput.value) return toast('Vælg din prøvedato først.', 'error');
    try {
      d = await post('plan.generate', { subject_id: ctx.id, exam_date: dateInput.value });
      ctx.data.subject.exam_date = d.exam_date;
      ctx.refreshHeader?.();
      toast('Din studieplan er klar', 'success');
      render();
    } catch (e) { showError(e); }
  }) }, icon('calendar'), d.plan ? 'Lav planen igen' : 'Lav min studieplan');

  const toggle = async (t, done) => {
    try { d = await post('plan.toggle', { subject_id: ctx.id, key: t.key, done }); render(); } catch (e) { showError(e); }
  };

  function render() {
    const p = d.plan;
    const head = h('div.card.card-pad', { style: { marginBottom: '16px' } },
      h('div.row.wrap', { style: { gap: '12px', alignItems: 'flex-end' } },
        h('div', { style: { flex: 1, minWidth: '220px' } },
          h('h2', 'Studieplan'),
          h('div.small.muted', 'En plan for hver dag frem til prøven: læs, lær, quiz, flashcards og prøveeksamen. Opgaverne bliver krydset af automatisk, når du laver dem. Planen bruger ingen AI-forespørgsler.')),
        h('div.field', { style: { margin: 0 } }, h('label', 'Prøvedato'), dateInput),
        makeBtn),
      p ? h('div', { style: { marginTop: '14px' } },
        h('div.row.between.small', h('span', `${p.progress.done} af ${p.progress.total} opgaver klaret`), h('span.faint', `Prøve: ${fmtDay(p.exam_date)}`)),
        h('div', { style: { marginTop: '6px' } }, progressBar(Math.round(p.progress.done / Math.max(1, p.progress.total) * 100), 'lg')),
        p.outdated ? h('div.notice.warning', { style: { marginTop: '12px' } }, icon('alert'), h('div', 'Prøvedatoen er ændret. Lav planen igen, så den passer.')) : null) : null);

    if (!p) {
      clear(el, head, h('div.card', emptyState({ icon: 'calendar', title: 'Ingen studieplan endnu', text: 'Vælg datoen for din prøve, så laver vi en plan for hver dag frem til den – med dine svageste emner først.' })));
      return;
    }
    const days = p.days.map(day => {
      const allDone = day.tasks.length && day.tasks.every(t => t.done);
      return h('div.card' + (day.is_today ? '' : ''), { style: { marginBottom: '10px', borderColor: day.is_today ? 'var(--accent-ring)' : '', opacity: day.is_past && allDone ? 0.65 : 1 } },
        h('div.card-head',
          h('h3', { style: { textTransform: 'capitalize' } }, fmtDay(day.date)),
          day.is_today ? h('span.badge.accent', 'I dag') : day.is_past ? h('span.badge' + (allDone ? '.success' : '.warning'), allDone ? 'Klaret' : 'Ikke færdig') : null),
        h('div.list', day.tasks.map(t => h('div.list-item', { style: { padding: '9px 18px' } },
          h('input', { type: 'checkbox', checked: t.done, title: 'Markér som klaret', style: { accentColor: 'var(--accent)', width: '16px', height: '16px', flex: 'none' }, onchange: e => toggle(t, e.target.checked) }),
          h('span.file-ic', { style: { width: '28px', height: '28px', color: t.done ? 'var(--success)' : 'var(--accent)' } }, icon(TASK_ICONS[t.type] || 'check')),
          h('span', { style: { flex: 1, textDecoration: t.done ? 'line-through' : 'none', color: t.done ? 'var(--text-3)' : '' } }, t.title),
          !t.done ? h('a.btn.sm', { href: taskHref(t, ctx.id) }, 'Gå i gang', icon('arrowRight')) : null))));
    });
    clear(el, head, h('div', days));
    el.querySelector('.card[style*="accent-ring"]')?.scrollIntoView({ block: 'nearest' });
  }
  render();
}
