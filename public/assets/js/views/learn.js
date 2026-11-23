import { h, clear, icon, get, post, addRefs, md, showError, withBusy, emptyState, loadingBlock, pct, progressBar, toast } from '../lib.js';

export async function learnTab(el, ctx, query) {
  const topics = ctx.data.topics;

  async function chooser() {
    clear(el, loadingBlock());
    let latest = null;
    try { latest = (await get('learn.latest', { subject_id: ctx.id })).session; } catch { /* ignore */ }
    const startBtn = h('button.btn.primary.lg', { onclick: () => start(0, startBtn) }, icon('play'), 'Start smart runde');
    clear(el, h('div.split',
      h('div.stack',
        latest ? h('div.card.card-pad', h('div.row.wrap',
          h('div', { style: { flex: 1 } }, h('h3', 'Fortsæt hvor du slap'), h('div.small.muted', latest.topics.map(t => t.name).join(' · '))),
          h('button.btn.primary', { onclick: () => render(latest) }, icon('play'), 'Fortsæt'))) : null,
        h('div.card.card-pad',
          h('h2', { style: { marginBottom: '4px' } }, 'Lær trin for trin'),
          h('p.small.muted', { style: { marginBottom: '18px' } }, 'Din AI-lærer forklarer et emne, stiller dig et spørgsmål, tjekker dit svar og forklarer eventuelle fejl med et nyt eksempel. Du går videre, når du har svaret rigtigt to gange i træk.'),
          startBtn,
          h('div.tiny.faint', { style: { marginTop: '8px' } }, 'Smart runde: de næste 4 emner, du ikke kan endnu, i rækkefølge.')),
        h('div.card', h('div.card-head', h('h3', 'Eller vælg et emne')),
          h('div.list', topics.map(t => h('div.list-item.click', { onclick: e => start(t.id, e.currentTarget.querySelector('.btn')) },
            t.understood ? h('span.badge.success', icon('check')) : h('span.badge', String(t.position + 1)),
            h('div', { style: { flex: 1, minWidth: 0 } }, h('div', { style: { fontWeight: 550 } }, t.name), h('div.tiny.faint', t.summary || '')),
            h('div', { style: { width: '90px' } }, progressBar(pct(t.mastery))),
            h('button.btn.sm', 'Lær')))))),
      h('div.card.card-pad', h('div.row', { style: { marginBottom: '8px' } }, icon('bulb', 'faint'), h('h3', 'Tips')),
        h('ul.small.muted', { style: { margin: 0, paddingLeft: '18px' } },
          h('li', 'Svar med dine egne ord – sådan finder du ud af, hvad du virkelig forstår.'),
          h('li', '“Ved ikke” er helt i orden: du får en forklaring og et nyt eksempel.'),
          h('li', 'Brug “Forklar på en anden måde”, hvis en forklaring ikke giver mening.')))));
  }

  async function start(topicId, btn) {
    const run = async () => {
      clear(el, h('div.card', loadingBlock('Forbereder din lektion…', 'Læser de relevante dele af dit materiale.')));
      try {
        const r = await post('learn.start', { subject_id: ctx.id, topic_id: topicId || 0, topics: 4 });
        render(r);
      } catch (e) { showError(e); chooser(); }
    };
    btn ? withBusy(btn, run) : run();
  }

  function render(v) {
    addRefs(v.refs);
    const stream = h('div.learn-stream');
    const side = h('div.learn-side.stack');
    let busy = false;

    const evEl = (e, isLast) => {
      switch (e.type) {
        case 'topic': return h('div.row', { style: { marginTop: '8px' } }, h('span.badge.accent', icon('graduation'), 'Emne'), h('h2', e.name));
        case 'explain': return h('div.card.ev-card.fade-in', h('div.ev-label', icon(e.alt ? 'repeat' : 'book'), e.alt ? 'Forklaret på en anden måde' : 'Forklaring'), md(e.content));
        case 'example': return h('div.card.ev-card.fade-in', h('div.ev-label', icon('bulb'), 'Et andet eksempel'), md(e.content));
        case 'answer': return h('div.msg.user', { style: { alignSelf: 'flex-end' } }, h('div.bubble', e.content));
        case 'feedback': return h('div.card.ev-card.fade-in.' + (e.correct ? 'feedback-ok' : 'feedback-bad'),
          h('div.ev-label', { style: { color: e.correct ? 'var(--success)' : 'var(--danger)' } }, icon(e.correct ? 'checkCircle' : 'xCircle'), e.correct ? 'Rigtigt' : e.score > 0.3 ? 'Delvist rigtigt' : 'Ikke helt'),
          md(e.content),
          !e.correct && e.answer ? h('div.small', { style: { marginTop: '8px' } }, h('span.faint', 'Rigtigt svar: '), e.answer) : null);
        case 'topic_done': return h('div.notice', { style: { borderColor: 'color-mix(in srgb, var(--success) 35%, transparent)' } }, icon('trophy'), h('div', h('b', `Du har styr på “${e.name}”!`), ' Markeret som lært.'));
        case 'topic_skipped': return h('div.notice', icon('arrowRight'), h('div', `Sprang “${e.name}” over.`));
        case 'complete': return h('div.card', emptyState({ icon: 'trophy', title: 'Runden er færdig', text: 'Flot arbejde. Lås det fast med en quiz eller flashcards.', action: h('div.row', { style: { justifyContent: 'center' } }, h('a.btn.primary', { href: `#/s/${ctx.id}/quiz` }, 'Tag en quiz'), h('a.btn', { href: `#/s/${ctx.id}/cards` }, 'Flashcards'), h('button.btn', { onclick: chooser }, 'Ny runde')) }));
        case 'question': return questionCard(e, isLast && v.awaiting === 'answer' && v.session.status === 'active');
        default: return null;
      }
    };

    function questionCard(q, active) {
      const card = h('div.card.ev-card.fade-in', h('div.ev-label', icon('quiz'), 'Tjek din forståelse'), h('div.q-prompt', { style: { fontSize: '16px', marginBottom: '14px' } }, q.prompt));
      if (!active) {
        card.style.opacity = '.75';
        return card;
      }
      let value = '';
      let input;
      if (q.kind === 'mcq' && q.options) {
        const buttons = q.options.map((o, i) => h('button.option', { type: 'button', onclick: () => { value = o; buttons.forEach(b => b.classList.toggle('selected', b === buttons[i])); } }, h('span.key', String.fromCharCode(65 + i)), h('span', o)));
        input = h('div.options', buttons);
      } else {
        input = h('textarea.textarea', { rows: 3, placeholder: 'Svar med dine egne ord…', oninput: e => (value = e.target.value), onkeydown: e => { if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) submit(false); } });
      }
      const submitBtn = h('button.btn.primary', { onclick: () => submit(false) }, 'Send svar');
      const idkBtn = h('button.btn.ghost', { onclick: () => submit(true) }, 'Ved ikke');
      async function submit(dontKnow) {
        if (busy) return;
        if (!dontKnow && !String(value).trim()) return toast('Skriv eller vælg et svar først.');
        busy = true;
        submitBtn.disabled = idkBtn.disabled = true;
        card.appendChild(h('div.row.small.muted', { style: { marginTop: '12px' } }, h('span.spinner'), 'Tjekker dit svar…'));
        try {
          render(await post('learn.answer', { session_id: v.session.id, answer: dontKnow ? "I don't know" : value, dont_know: dontKnow }));
        } catch (e) {
          busy = false;
          showError(e);
          submitBtn.disabled = idkBtn.disabled = false;
          card.lastChild.remove();
        }
      }
      card.append(input, h('div.row', { style: { marginTop: '14px' } }, submitBtn, idkBtn));
      setTimeout(() => input.tagName === 'TEXTAREA' && input.focus(), 50);
      return card;
    }

    const action = async (name, btn) => withBusy(btn, async () => {
      try { render(await post('learn.action', { session_id: v.session.id, action: name })); } catch (e) { showError(e); }
    });

    clear(stream, v.events.map((e, i) => evEl(e, i === v.events.length - 1)));
    const done = v.topics.filter(t => t.done).length;
    clear(side,
      h('div.card.card-pad',
        h('div.row.between', { style: { marginBottom: '10px' } }, h('h3', 'Plan for runden'), h('span.small.faint', `${done}/${v.topics.length}`)),
        progressBar((done / Math.max(1, v.topics.length)) * 100),
        h('div', { style: { marginTop: '10px' } }, v.topics.map(t => h('div.plan-item' + (t.done ? '.done' : '') + (t.current && !t.done ? '.current' : ''), h('span.ck', t.done ? icon('check') : null), h('span', t.name))))),
      v.session.status === 'active' ? h('div.card.card-pad',
        h('div.small.muted', { style: { marginBottom: '10px' } }, `Rigtige i træk: ${v.streak} / ${v.needed}`),
        h('div.stack.sm',
          h('button.btn.block', { onclick: e => action('explain_differently', e.currentTarget) }, icon('repeat'), 'Forklar på en anden måde'),
          h('button.btn.block', { onclick: e => action('skip', e.currentTarget) }, icon('arrowRight'), 'Spring emnet over'),
          h('a.btn.block', { href: `#/s/${ctx.id}/tutor` }, icon('chat'), 'Spørg AI-læreren'),
          h('button.btn.block.ghost', { onclick: e => action('end', e.currentTarget).then(chooser) }, 'Afslut runde'))) : null);
    clear(el, h('div.learn-layout', stream, side));
    const last = stream.lastElementChild;
    if (last && stream.children.length > 3) last.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  if (query.topic) start(+query.topic);
  else await chooser();
}
