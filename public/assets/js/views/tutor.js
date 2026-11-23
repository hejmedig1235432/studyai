import { h, clear, icon, get, post, addRefs, md, showError, confirmDialog, timeAgo, languageSelect, streamPost } from '../lib.js';

const QUICK = [
  ['Forklar enklere', 'Kan du forklare det igen med enklere ord?'],
  ['Giv et eksempel', 'Kan du give mig et konkret eksempel på det?'],
  ['Trin for trin', 'Forklar det trin for trin.'],
  ['Forklar anderledes', 'Jeg forstår det stadig ikke. Kan du forklare det på en helt anden måde, måske med en sammenligning?'],
  ['Quiz mig', 'Giv mig 3 øvelsesspørgsmål om det, vi lige har talt om, med skjulte svar.'],
];
// Assignment help: these put a starter text in the box so the student can paste their own text.
const ASSIGN = [
  ['Forklar opgaven', 'Forklar hvad min opgave beder mig om, hvilke krav der er, og hvordan den sandsynligvis bliver bedømt.', false],
  ['Feedback på min tekst', 'Giv mig feedback på min tekst (hvor godt den svarer på opgaven, opbygning, argumentation og sprog):\n\n', true],
  ['Tjek grammatik og stavning', 'Tjek grammatik, stavning og tegnsætning i denne tekst. Citér hver fejl og vis rettelsen:\n\n', true],
  ['Hjælp mig med en plan', 'Hjælp mig med at lave en disposition / plan for min opgave, trin for trin.', false],
];

export async function tutorTab(el, ctx, query) {
  const topics = ctx.data.topics;
  let threads = [];
  let threadId = query.thread ? +query.thread : null;
  let sending = false;

  const threadList = h('div.chat-threads');
  const scroll = h('div.chat-scroll');
  const ta = h('textarea', { rows: 1, placeholder: 'Spørg om hvad som helst i dit materiale…', oninput: () => autosize(), onkeydown: e => { if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send(ta.value); } } });
  const sendBtn = h('button.btn.primary.icon', { 'aria-label': 'Send', onclick: () => send(ta.value) }, icon('send'));
  const langSel = languageSelect(sessionStorage.getItem('tutorLang:' + ctx.id) || '', { defaultLabel: 'Fagets standard', style: { width: 'auto', height: '30px', fontSize: '12.5px' } });
  langSel.addEventListener('change', () => sessionStorage.setItem('tutorLang:' + ctx.id, langSel.value));
  const quick = h('div.chips', { style: { padding: '0 6px 6px' } },
    QUICK.map(([label, text]) => h('button.chip', { type: 'button', onclick: () => send(text) }, label)),
    ASSIGN.map(([label, text, insert]) => h('button.chip', { type: 'button', title: insert ? 'Indsæt din tekst efter teksten, og send' : '', onclick: () => {
      if (!insert) return send(text);
      ta.value = text;
      autosize();
      ta.focus();
      ta.setSelectionRange(ta.value.length, ta.value.length);
    } }, icon('edit'), label)));
  const autosize = () => { ta.style.height = 'auto'; ta.style.height = Math.min(200, ta.scrollHeight) + 'px'; };

  const renderThreads = () => clear(threadList,
    h('button.btn.block', { style: { marginBottom: '8px', justifyContent: 'flex-start' }, onclick: () => { threadId = null; renderMessages([]); renderThreads(); ta.focus(); } }, icon('plus'), 'Ny samtale'),
    threads.map(t => h('button' + (t.id === threadId ? '.active' : ''), { onclick: () => openThread(t.id) },
      icon('chat'), h('span.truncate', { style: { flex: 1 } }, t.title),
      h('span.tiny.faint', timeAgo(t.updated_at)))),
    threadId ? h('button', { style: { marginTop: '8px', color: 'var(--danger)' }, onclick: async () => {
      if (!(await confirmDialog({ title: 'Slet denne samtale?', message: 'Det kan ikke fortrydes.', confirm: 'Slet', danger: true }))) return;
      try { await post('tutor.delete', { thread_id: threadId }); threadId = null; await loadThreads(); renderMessages([]); } catch (e) { showError(e); }
    } }, icon('trash'), 'Slet samtale') : null);

  const msgEl = m => m.role === 'user'
    ? h('div.msg.user.fade-in', h('div.bubble', m.content))
    : h('div.msg.assistant.fade-in', h('div.av', icon('sparkles')), h('div.bubble', { style: { minWidth: 0 } }, md(m.content)));

  function renderMessages(msgs) {
    if (!msgs.length) {
      clear(scroll, h('div.empty', { style: { margin: 'auto' } },
        h('div.icon-wrap', icon('chat')), h('h3', 'Spørg din AI-lærer'),
        h('p', 'Den svarer ud fra dit eget materiale og viser, hvor hvert svar kommer fra. Hvis noget ikke står i dine kilder, siger den det.'),
        h('div.chips', { style: { justifyContent: 'center', maxWidth: '560px', margin: '0 auto' } },
          topics.slice(0, 5).map(t => h('button.chip', { onclick: () => send(`Forklar "${t.name}" for mig på en enkel måde.`) }, `Forklar ${t.name}`)),
          h('button.chip', { onclick: () => send('Hvad er det vigtigste, jeg skal kunne til min prøve?') }, 'Hvad er vigtigst til prøven?'))));
      return;
    }
    clear(scroll, msgs.map(msgEl));
    scroll.scrollTop = scroll.scrollHeight;
  }

  async function loadThreads() {
    threads = (await get('tutor.threads', { subject_id: ctx.id })).threads;
    renderThreads();
  }

  async function openThread(id) {
    threadId = id;
    renderThreads();
    clear(scroll, h('div.loading-block', h('div.spinner')));
    try {
      const r = await get('tutor.messages', { thread_id: id });
      addRefs(r.refs);
      renderMessages(r.messages);
    } catch (e) { showError(e); }
  }

  async function send(text) {
    text = String(text || '').trim();
    if (!text || sending) return;
    sending = true;
    sendBtn.disabled = true;
    ta.value = '';
    autosize();
    if (scroll.querySelector('.empty')) scroll.replaceChildren();
    scroll.appendChild(msgEl({ role: 'user', content: text }));
    const typing = h('div.msg.assistant', h('div.av', icon('sparkles')), h('div.typing', h('i'), h('i'), h('i')));
    scroll.appendChild(typing);
    scroll.scrollTop = scroll.scrollHeight;
    let live = null;
    let started = false;
    try {
      // Stream the answer: text appears while the AI is still writing.
      let partial = '';
      let pending = false;
      const paint = () => {
        pending = false;
        const bubble = h('div.bubble', { style: { minWidth: 0 } }, md(partial));
        if (!live) {
          live = h('div.msg.assistant', h('div.av', icon('sparkles')), bubble);
          typing.replaceWith(live);
        } else {
          live.lastChild.replaceWith(bubble);
        }
        const nearBottom = scroll.scrollHeight - scroll.scrollTop - scroll.clientHeight < 120;
        if (nearBottom) scroll.scrollTop = scroll.scrollHeight;
      };
      const payload = { subject_id: ctx.id, thread_id: threadId || 0, message: text, language: langSel.value };
      let r;
      try {
        r = await streamPost('tutor.stream', payload, (event, data) => {
          if (event === 'start') started = true;
          if (event === 'delta') {
            partial += data.t;
            if (!pending) { pending = true; setTimeout(paint, 60); }
          }
        });
      } catch (streamErr) {
        // Live answer couldn't connect (e.g. server restarted): get a normal answer instead.
        if (started || streamErr.status !== 0) throw streamErr;
        r = await post('tutor.send', payload);
      }
      addRefs(r.refs);
      (live || typing).replaceWith(msgEl(r.message));
      if (!threadId) { threadId = r.thread_id; loadThreads(); }
      scroll.scrollTop = scroll.scrollHeight;
    } catch (e) {
      (live || typing).replaceWith(h('div.notice.danger', icon('alert'), h('div', e.message)));
      ta.value = text;
    } finally {
      sending = false;
      sendBtn.disabled = false;
      ta.focus();
    }
  }

  clear(el, h('div.chat-layout',
    threadList,
    h('div.chat',
      h('div.row', { style: { marginBottom: '8px' } }, h('button.btn.sm.ghost', { class: 'show-threads', onclick: () => threadList.classList.toggle('show') }, icon('menu'), 'Samtaler'), h('span.spacer'), h('span.tiny.faint', 'Svar på'), langSel),
      scroll,
      h('div.composer', quick, h('div.row', { style: { alignItems: 'flex-end' } }, ta, sendBtn)))));
  await loadThreads();
  if (threadId) await openThread(threadId);
  else renderMessages([]);
  if (query.ask) send(query.ask);
}
