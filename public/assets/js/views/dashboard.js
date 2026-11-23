import { taskHref, TASK_ICONS } from './plan.js';
import { h, clear, icon, get, state, fmtDuration, timeAgo, progressBar, pct, emptyState, skeleton, subjectIcon, plural } from '../lib.js';

function greeting() {
  const hr = new Date().getHours();
  return hr < 10 ? 'Godmorgen' : hr < 18 ? 'Goddag' : 'Godaften';
}

export async function dashboardView(view) {
  const page = h('div.page');
  clear(view, page);
  clear(page, h('div.page-head', h('div', h('h1', `${greeting()}, ${state.user.name.split(' ')[0]}`), h('div.sub', 'Her er status.'))),
    h('div.grid.cols-4', Array.from({ length: 4 }, () => h('div.card.stat', skeleton(2)))));
  const d = await get('dashboard');
  const s = d.stats;

  const stat = (ic, k, v, sub) => h('div.card.stat', h('div.k', icon(ic), k), h('div.v', v), sub ? h('div.d', sub) : null);
  const maxAct = Math.max(1, ...d.activity.map(a => a.seconds));
  const dayName = iso => new Date(iso + 'T12:00:00').toLocaleDateString('da-DK', { weekday: 'narrow' });
  const ai = await get('ai.status').catch(() => null);

  clear(page,
    h('div.page-head',
      h('div', h('h1', `${greeting()}, ${state.user.name.split(' ')[0]}`),
        h('div.sub', s.today_seconds ? `Du har læst ${fmtDuration(s.today_seconds)} i dag.` : s.streak ? `Hold din stime på ${s.streak} dage i gang i dag.` : 'Klar når du er.')),
      h('div.actions', h('a.btn', { href: '#/memory' }, icon('brain'), 'Dagens gentagelse'), h('a.btn.primary', { href: '#/new' }, icon('plus'), 'Nyt fag'))),

    ai && !ai.configured ? h('div.notice.warning', { style: { marginBottom: '18px' } }, icon('key'),
      h('div', ai.is_admin
        ? ['Der er ikke sat en AI-nøgle op endnu, så analyse og quizzer kan ikke køre. ', h('a', { href: '#/settings?tab=ai' }, 'Tilføj din OpenRouter API-nøgle'), '.']
        : 'AI-tjenesten er ikke sat op endnu. Bed administratoren om at tilføje en API-nøgle.')) : null,

    h('div.grid.cols-4', { style: { marginBottom: '18px' } },
      stat('clock', 'Studietid', fmtDuration(s.total_seconds), s.today_seconds ? `${fmtDuration(s.today_seconds)} i dag` : 'Ingen studietid i dag'),
      stat('flame', 'Stime', plural(s.streak, 'dag', 'dage'), s.streak ? 'Læs i dag for at holde den' : 'Start en i dag'),
      stat('quiz', 'Gennemførte quizzer', String(s.quizzes_completed), s.average_score !== null ? `Gennemsnit ${s.average_score}%` : 'Ingen quizzer endnu'),
      stat('cards', 'Flashcards du kan', `${s.cards_mastered}`, s.cards_total ? `af ${s.cards_total} kort` : 'Ingen kort endnu')),

    d.plan_today?.length ? h('div.card', { style: { marginBottom: '18px' } },
      h('div.card-head', h('h3', icon('calendar'), ' I dag i din studieplan'), h('span.small.faint', `${d.plan_today.filter(t => t.done).length} af ${d.plan_today.length} klaret`)),
      h('div.list', d.plan_today.map(t => h('a.list-item.click', { href: t.done ? `#/s/${t.subject_id}/plan` : taskHref(t, t.subject_id), style: { color: 'inherit', textDecoration: 'none' } },
        h('span.file-ic', { style: { width: '28px', height: '28px', color: t.done ? 'var(--success)' : 'var(--accent)' } }, icon(t.done ? 'check' : (TASK_ICONS[t.type] || 'check'))),
        h('span', { style: { flex: 1, textDecoration: t.done ? 'line-through' : 'none', color: t.done ? 'var(--text-3)' : '' } }, t.title),
        h('span.dot', { style: { width: '8px', height: '8px', borderRadius: '3px', background: t.color } }), h('span.tiny.faint', t.subject_name))))) : null,
    h('div.split',
      h('div.stack',
        // Recommended next
        h('div.card',
          h('div.card-head', h('h3', 'Anbefalet nu'), h('span.faint.small', 'Ud fra dine fremskridt')),
          d.recommendations.length
            ? h('div.list', d.recommendations.map((r, i) => h('a.list-item.click', { href: r.href, style: { color: 'inherit', textDecoration: 'none' } },
              h('div.file-ic', { style: i === 0 ? { background: 'var(--accent-soft)', color: 'var(--accent)', borderColor: 'var(--accent-ring)' } : {} },
                icon({ read: 'book', memory: 'brain', quiz: 'target', learn: 'graduation', cards: 'cards', process: 'refresh', create: 'plus', exam: 'exam' }[r.kind] || 'sparkles')),
              h('div', { style: { flex: 1, minWidth: 0 } }, h('div', { style: { fontWeight: 550 } }, r.title), h('div.small.muted', r.detail)),
              icon('chevronRight', 'faint'))))
            : h('div.card-body.muted', 'Du er helt opdateret. Tilføj mere materiale eller tag en prøveeksamen.')),

        // Continue learning
        h('div.card',
          h('div.card-head', h('h3', 'Fortsæt hvor du slap'), h('a.small', { href: '#/subjects' }, 'Alle fag')),
          d.continue.length
            ? h('div.list', d.continue.map(sub => h('a.list-item.click', { href: `#/s/${sub.id}`, style: { color: 'inherit', textDecoration: 'none' } },
              subjectIcon(sub, 34),
              h('div', { style: { flex: 1, minWidth: 0 } },
                h('div.row', h('span.truncate', { style: { fontWeight: 550 } }, sub.name), sub.job ? h('span.badge.accent', h('span.spinner', { style: { width: '10px', height: '10px', borderWidth: '1.5px' } }), 'Behandler') : null),
                h('div.small.faint', `${plural(sub.topic_count, 'emne', 'emner')} · ${plural(sub.card_count, 'kort', 'kort')} · læst ${timeAgo(sub.last_studied_at)}`)),
              h('div', { style: { width: '120px' } }, h('div.meter', progressBar(sub.progress), h('span.pct', sub.progress + '%'))))))
            : emptyState({ icon: 'layers', title: 'Ingen fag endnu', text: 'Opret et fag og upload dit materiale for at komme i gang.', action: h('a.btn.primary', { href: '#/new' }, icon('plus'), 'Nyt fag') })),

        // Activity
        h('div.card',
          h('div.card-head', h('h3', 'Seneste 14 dage'), h('span.faint.small', fmtDuration(d.activity.reduce((a, b) => a + b.seconds, 0)))),
          h('div.card-body', h('div.bars', d.activity.map((a, i) => h('div.b' + (i === d.activity.length - 1 ? '.today' : ''), { title: `${a.day}: ${fmtDuration(a.seconds)}` },
            h('i' + (a.seconds ? '' : '.zero'), { style: { height: Math.max(3, Math.round(a.seconds / maxAct * 90)) + 'px' } }), h('span', dayName(a.day)))))))),

      h('div.stack',
        // Overall progress
        h('div.card.card-pad',
          h('div.row.between', h('h3', 'Samlede fremskridt'), h('span', { style: { fontWeight: 650, fontSize: '18px' } }, s.overall_progress + '%')),
          h('div', { style: { margin: '12px 0 6px' } }, progressBar(s.overall_progress, 'lg')),
          h('div.small.faint', 'Gennemsnitlig beherskelse af alle emner i dine fag.')),

        // Weakest topics
        h('div.card',
          h('div.card-head', h('h3', 'Svageste emner')),
          d.weak_topics.length
            ? h('div.list', d.weak_topics.map(t => h('a.list-item.click', { href: `#/s/${t.subject_id}/quiz?topic=${t.id}`, style: { color: 'inherit', textDecoration: 'none', display: 'block' } },
              h('div.row.between', h('span.truncate', { style: { fontWeight: 500 } }, t.name), h('span.small.faint', pct(t.mastery) + '%')),
              h('div.tiny.faint', { style: { margin: '2px 0 6px' } }, t.subject_name),
              progressBar(pct(t.mastery), t.mastery < 0.45 ? 'danger' : 'warning'))))
            : h('div.card-body.small.muted', 'Svar på nogle quizspørgsmål, så dukker dine svageste emner op her.')),

        // Upcoming reviews
        h('div.card',
          h('div.card-head', h('h3', 'Kommende gentagelser'), s.due_now ? h('a.btn.sm.primary', { href: '#/memory' }, `Gentag ${s.due_now}`) : null),
          h('div.list', d.upcoming.map((u, i) => h('div.list-item', { style: { padding: '8px 18px' } },
            h('span', { style: { width: '90px' } }, i === 0 ? 'I dag' : i === 1 ? 'I morgen' : new Date(u.day + 'T12:00:00').toLocaleDateString('da-DK', { weekday: 'long' })),
            h('div', { style: { flex: 1 } }, progressBar(Math.min(100, (u.cards + u.questions) * 4))),
            h('span.small.faint', { style: { width: '64px', textAlign: 'right' } }, String(u.cards + u.questions) + ' stk.'))))),

        d.recent_attempts.length ? h('div.card',
          h('div.card-head', h('h3', 'Seneste resultater')),
          h('div.list', d.recent_attempts.map(a => h('a.list-item.click', { href: `#/s/${a.subject_id}/${a.mode === 'exam' ? 'exam' : 'quiz'}?attempt=${a.id}`, style: { color: 'inherit', textDecoration: 'none' } },
            h('span.badge' + (a.score >= 0.7 ? '.success' : a.score >= 0.5 ? '.warning' : '.danger'), pct(a.score) + '%'),
            h('div', { style: { flex: 1, minWidth: 0 } }, h('div.truncate.small', { style: { fontWeight: 500 } }, (a.mode === 'exam' ? 'Prøveeksamen · ' : 'Quiz · ') + a.subject_name), h('div.tiny.faint', `${a.correct}/${a.total} rigtige · ${timeAgo(a.finished_at)}`)))))) : null)));
}
