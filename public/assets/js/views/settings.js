import { h, clear, icon, get, post, state, showError, toast, withBusy, confirmDialog, loadingBlock, timeAgo, modal, languageSelect } from '../lib.js';
import { setTheme, logout } from '../app.js';

const MODEL_SUGGESTIONS = ['google/gemini-2.5-flash', 'google/gemini-2.5-pro', 'openai/gpt-4.1-mini', 'openai/gpt-4o-mini', 'anthropic/claude-sonnet-4.5', 'meta-llama/llama-3.3-70b-instruct', 'deepseek/deepseek-chat'];
const EMBED_SUGGESTIONS = ['openai/text-embedding-3-small', 'openai/text-embedding-3-large'];

export async function aiKeysPanel(onChange) {
  const box = h('div');
  const render = async () => {
    clear(box, loadingBlock());
    const s = await get('ai.status');
    if (!s.is_admin) {
      clear(box, h('div.notice' + (s.configured ? '.info' : '.warning'), icon(s.configured ? 'checkCircle' : 'alert'), h('div', s.configured ? 'AI er sat op og klar.' : 'AI er ikke sat op endnu. Bed administratoren om at tilføje en API-nøgle.')));
      return;
    }
    const label = h('input.input', { placeholder: 'Navn (f.eks. “Hovednøgle”)', maxlength: 60 });
    const key = h('input.input', { placeholder: 'sk-or-v1-…', type: 'password', autocomplete: 'off' });
    const err = h('div');
    const addForm = h('form', { onsubmit: async e => {
      e.preventDefault();
      clear(err);
      await withBusy(addForm.querySelector('button[type=submit]'), async () => {
        try {
          const r = await post('ai.key.add', { label: label.value, key: key.value });
          toast(r.test.ok ? 'Nøglen er tilføjet og virker' : 'Nøglen er tilføjet', 'success');
          onChange?.();
          render();
        } catch (ex) { clear(err, h('div.form-error', ex.message)); }
      });
    } },
    err,
    h('div.row.wrap', { style: { alignItems: 'flex-end' } },
      h('div.field', { style: { flex: '1 1 160px', margin: 0 } }, h('label', 'Navn'), label),
      h('div.field', { style: { flex: '3 1 260px', margin: 0 } }, h('label', 'OpenRouter API-nøgle'), key),
      h('button.btn.primary', { type: 'submit', 'data-busy': 'Tester…' }, icon('plus'), 'Tilføj og test nøgle')),
    h('div.hint', { style: { marginTop: '6px' } }, 'Hent nøgler på openrouter.ai/keys. Du kan tilføje flere: forespørgsler fordeles mellem dem og skifter automatisk, hvis én løber tør for kredit eller er begrænset. Nøglerne gemmes krypteret på serveren og sendes aldrig til browseren.'));

    const cfg = s.config;
    const field = (name, labelText, list, hint) => {
      const id = 'dl-' + name;
      // Model fields can hold a comma-separated fallback list, so they get a textarea.
      if (name === 'model' || name === 'vision_model') {
        return h('div.field', h('label', labelText), h('textarea.textarea', { name, value: cfg[name] || '', rows: 3, style: { minHeight: '70px', fontFamily: 'var(--mono)', fontSize: '12.5px' } }), hint ? h('div.hint', hint) : null);
      }
      return h('div.field', h('label', labelText), h('input.input', { name, value: cfg[name] || '', list: list ? id : null, placeholder: name === 'embedding_model' ? 'Valgfri – lad stå tom for kun søgeord' : '' }),
        list ? h('datalist', { id }, list.map(m => h('option', { value: m }))) : null, hint ? h('div.hint', hint) : null);
    };
    const freeBtn = h('button.btn', { type: 'button', 'data-busy': 'Henter gratis modeller…', onclick: e => withBusy(e.currentTarget, async () => {
      try {
        const r = await post('ai.free_models');
        toast(`Bruger ${r.free.text.length} gratis modeller (${r.free.vision.length} kan læse billeder). Der skiftes automatisk til den næste, når en model er optaget.`, 'success', 7000);
        render();
      } catch (ex) { showError(ex); }
    }) }, icon('zap'), 'Brug alle gratis modeller');
    const cfgForm = h('form', { onsubmit: async e => {
      e.preventDefault();
      const fd = new FormData(cfgForm);
      await withBusy(cfgForm.querySelector('button[type=submit]'), async () => {
        try { await post('ai.config', Object.fromEntries(fd.entries())); toast('AI-indstillinger er gemt', 'success'); render(); } catch (ex) { showError(ex); }
      });
    } },
    h('div.grid.cols-2', { style: { gap: '0 14px' } },
      field('model', 'Hovedmodel(ler)', MODEL_SUGGESTIONS, 'Bruges til analyse, quizzer, AI-lærer og retning. Adskil flere med komma: de prøves i rækkefølge, når én er optaget.'),
      field('vision_model', 'Billed-/OCR-model(ler)', MODEL_SUGGESTIONS, 'Skal kunne læse billeder. Bruges til scanninger og fotos. Kommasepareret liste er tilladt.'),
      field('embedding_model', 'Embedding-model (valgfri)', EMBED_SUGGESTIONS, 'Forbedrer søgning og AI-lærerens svar med semantisk matchning.'),
      field('base_url', 'API-adresse', null, 'OpenRouter som standard. Alle OpenAI-kompatible API’er virker.')),
    h('div.row.wrap', h('button.btn.primary', { type: 'submit' }, 'Gem AI-indstillinger'), freeBtn,
      cfg.embedding_model ? h('button.btn', { type: 'button', onclick: e => withBusy(e.currentTarget, async () => {
        try { const r = await post('ai.reindex'); toast(`${r.done} afsnit behandlet${r.remaining ? `, ${r.remaining} tilbage – kør igen` : ''}.`, 'success'); } catch (ex) { showError(ex); }
      }) }, icon('refresh'), 'Behandl eksisterende dokumenter') : null));

    clear(box,
      h('div.card', { style: { marginBottom: '16px' } },
        h('div.card-head', h('h3', 'API-nøgler'), h('div.row', { style: { gap: '6px' } }, s.configured ? h('span.badge.success', icon('check'), 'Sat op') : h('span.badge.warning', 'Ingen nøgle endnu'),
          s.keys.length ? h('button.btn.sm', { onclick: e => withBusy(e.currentTarget, async () => {
            try {
              const r = await post('ai.keys.test');
              modal({ title: 'Resultat af nøgletest', body: h('div.stack.sm', r.results.map(x => h('div.notice' + (x.ok ? '.info' : '.danger'), icon(x.ok ? 'checkCircle' : 'alert'), h('div', h('b', x.label + ': '), x.message)))) });
              render();
            } catch (ex) { showError(ex); }
          }) }, icon('zap'), 'Test alle') : null)),
        s.keys.length > 1 ? h('label.row', { style: { gap: '10px', padding: '12px 18px', borderBottom: '1px solid var(--border)', cursor: 'pointer', alignItems: 'flex-start' } },
          h('input', { type: 'checkbox', checked: s.reserve_for_admins, style: { marginTop: '3px' }, onchange: async e => {
            try { await post('ai.config', { reserve_for_admins: e.target.checked }); toast(e.target.checked ? 'Ekstra nøgler er nu kun for administratorer' : 'Alle nøgler bruges nu af alle', 'success'); render(); } catch (ex) { showError(ex); }
          } }),
          h('div', h('div', { style: { fontWeight: 550 } }, 'Ekstra nøgler kun for administratorer'),
            h('div.tiny.faint', 'Alle bruger den første nøgle. Når den er brugt op eller begrænset, må kun administratorer fortsætte på de andre nøgler – almindelige brugere venter, til den første nøgle er klar igen.'))) : null,
        s.keys.length ? h('div.list', s.keys.map((k, i) => h('div.list-item',
          h('div.file-ic', icon('key')),
          h('div', { style: { flex: 1, minWidth: 0 } },
            h('div.row', { style: { gap: '8px' } }, h('span', { style: { fontWeight: 550 } }, k.label), h('span.mono.small.faint', k.masked),
              k.source === 'env' ? h('span.badge', '.env') : null,
              s.keys.length > 1 && s.reserve_for_admins ? (s.keys.findIndex(x => x.enabled) === i ? h('span.badge.accent', 'Alle') : h('span.badge', 'Kun admin')) : null, !k.enabled ? h('span.badge', 'Slået fra') : null,
              k.cooldown_until > Date.now() / 1000 ? h('span.badge.warning', 'Holder pause') : k.last_error ? h('span.badge.danger', 'Fejl') : k.ok_at ? h('span.badge.success', 'Virker') : null),
            k.last_error ? h('div.tiny', { style: { color: 'var(--danger)' } }, k.last_error) : k.ok_at ? h('div.tiny.faint', `Virkede sidst ${timeAgo(k.ok_at)}`) : null),
          k.source === 'settings' ? h('div.row', { style: { gap: '4px' } },
            h('button.btn.sm', { onclick: async () => { try { await post('ai.key.update', { id: k.id, enabled: !k.enabled }); render(); } catch (e) { showError(e); } } }, k.enabled ? 'Slå fra' : 'Slå til'),
            h('button.btn.sm.danger', { onclick: async () => {
              if (!(await confirmDialog({ title: 'Fjern denne nøgle?', message: `“${k.label}” bliver ikke længere brugt.`, confirm: 'Fjern', danger: true }))) return;
              try { await post('ai.key.update', { id: k.id, delete: true }); onChange?.(); render(); } catch (e) { showError(e); }
            } }, icon('trash'))) : h('span.tiny.faint', 'Ændres i .env')))) : null,
        h('div.card-body', addForm)),
      h('div.card', h('div.card-head', h('h3', 'Modeller og udbyder')), h('div.card-body', cfgForm)),
      h('div.tiny.faint', { style: { marginTop: '10px' } }, `PHP ${s.system.php} · database: ${s.system.db} · upload-grænse ${s.system.upload_limit_mb} MB · billedskalering ${s.system.gd ? 'til' : 'fra (slå GD-udvidelsen til)'}`));
  };
  await render();
  return box;
}

export async function settingsView(view, query) {
  const u = state.user;
  const sections = {
    profile: ['Profil', 'user'],
    study: ['Studieindstillinger', 'target'],
    language: ['Sprog', 'chat'],
    appearance: ['Udseende', 'sun'],
    security: ['Adgangskode', 'lock'],
    ai: ['AI-udbyder', 'key'],
    account: ['Konto', 'settings'],
  };
  // AI keys and models are managed by the admin only; other users never see them.
  if (u.role !== 'admin') delete sections.ai;
  let tab = sections[query.tab] ? query.tab : 'profile';
  const body = h('div');
  const nav = h('div.nav', { style: { padding: 0 } });

  const renderNav = () => clear(nav, Object.entries(sections).map(([k, [label, ic]]) =>
    h('button.nav-item' + (k === tab ? '.active' : ''), { onclick: () => { tab = k; history.replaceState(null, '', '#/settings?tab=' + k); renderNav(); renderBody(); } }, icon(ic), label)));

  const renderBody = async () => {
    if (tab === 'profile') {
      const name = h('input.input', { value: u.name, maxlength: 80 });
      clear(body, h('div.card.card-pad',
        h('h2', { style: { marginBottom: '16px' } }, 'Profil'),
        h('div.field', h('label', 'Navn'), name),
        h('div.field', h('label', 'E-mail'), h('input.input', { value: u.email, disabled: true })),
        h('button.btn.primary', { onclick: e => withBusy(e.currentTarget, async () => {
          try { const r = await post('auth.profile', { name: name.value }); Object.assign(state.user, r.user); toast('Profilen er gemt', 'success'); } catch (ex) { showError(ex); }
        }) }, 'Gem')));
    } else if (tab === 'study') {
      const prefs = u.prefs || {};
      const len = h('select.select', [10, 20, 30, 50].map(n => h('option', { value: n, selected: (+prefs.session_length || 20) === n }, `${n} kort`)));
      const goal = h('select.select', [10, 15, 20, 30, 45, 60, 90].map(n => h('option', { value: n, selected: (+prefs.daily_goal_min || 30) === n }, `${n} minutter`)));
      clear(body, h('div.card.card-pad',
        h('h2', { style: { marginBottom: '16px' } }, 'Studieindstillinger'),
        h('div.field', h('label', 'Antal kort pr. runde'), len),
        h('div.field', h('label', 'Dagligt studiemål'), goal),
        h('button.btn.primary', { onclick: e => withBusy(e.currentTarget, async () => {
          try { const r = await post('auth.profile', { prefs: { session_length: +len.value, daily_goal_min: +goal.value } }); Object.assign(state.user, r.user); toast('Indstillingerne er gemt', 'success'); } catch (ex) { showError(ex); }
        }) }, 'Gem')));
    } else if (tab === 'language') {
      const sel = languageSelect(u.prefs?.ai_language || 'da', { style: { maxWidth: '360px' } });
      clear(body, h('div.card.card-pad',
        h('h2', { style: { marginBottom: '6px' } }, 'AI-sprog'),
        h('p.small.muted', { style: { marginBottom: '16px' } }, 'Det sprog AI’en bruger til studieguides, quizzer, flashcards, feedback, AI-læreren og trin-for-trin-læring. “Samme som mit materiale” følger sproget i dine uploads. Du kan også vælge sprog pr. fag (fag → Redigér detaljer) og skifte sprog pr. samtale hos AI-læreren.'),
        h('div.field', h('label', 'Standardsprog'), sel),
        h('div.notice.info', { style: { marginBottom: '14px' } }, icon('info'), h('div', 'Indhold, der allerede er lavet, beholder sit sprog. Brug “Analysér alt igen” på et fag eller “Oversæt” i studieguidens AI-værktøjer for at ændre det.')),
        h('button.btn.primary', { onclick: e => withBusy(e.currentTarget, async () => {
          try { const r = await post('auth.profile', { prefs: { ai_language: sel.value } }); Object.assign(state.user, r.user); toast('Sproget er gemt', 'success'); } catch (ex) { showError(ex); }
        }) }, 'Gem')));
    } else if (tab === 'appearance') {
      const cur = document.documentElement.dataset.pref || 'dark';
      const seg = h('div.seg', [['dark', 'Mørk'], ['light', 'Lys'], ['system', 'Følg systemet']].map(([k, l]) => h('button' + (cur === k ? '.on' : ''), { onclick: e => { setTheme(k); seg.querySelectorAll('button').forEach(b => b.classList.remove('on')); e.currentTarget.classList.add('on'); } }, l)));
      clear(body, h('div.card.card-pad', h('h2', { style: { marginBottom: '16px' } }, 'Udseende'), h('div.field', h('label', 'Tema'), seg)));
    } else if (tab === 'security') {
      const cur = h('input.input', { type: 'password', autocomplete: 'current-password' });
      const nw = h('input.input', { type: 'password', autocomplete: 'new-password', minlength: 8 });
      const nw2 = h('input.input', { type: 'password', autocomplete: 'new-password' });
      clear(body, h('div.card.card-pad',
        h('h2', { style: { marginBottom: '16px' } }, 'Skift adgangskode'),
        h('div.field', h('label', 'Nuværende adgangskode'), cur),
        h('div.field', h('label', 'Ny adgangskode'), nw, h('div.hint', 'Mindst 8 tegn.')),
        h('div.field', h('label', 'Bekræft ny adgangskode'), nw2),
        h('button.btn.primary', { onclick: e => withBusy(e.currentTarget, async () => {
          if (nw.value !== nw2.value) return toast('De nye adgangskoder er ikke ens.', 'error');
          try { await post('auth.password', { current_password: cur.value, new_password: nw.value }); toast('Adgangskoden er skiftet', 'success'); cur.value = nw.value = nw2.value = ''; } catch (ex) { showError(ex); }
        }) }, 'Skift adgangskode')));
    } else if (tab === 'ai') {
      clear(body, h('div', h('h2', { style: { marginBottom: '4px' } }, 'AI-udbyder'), h('p.small.muted', { style: { marginBottom: '16px' } }, 'Siden bruger OpenRouter til AI-modeller. API-nøgler bliver på serveren.'), await aiKeysPanel()));
    } else if (tab === 'account') {
      const pw = h('input.input', { type: 'password', placeholder: 'Din adgangskode' });
      clear(body, h('div.stack',
        h('div.card.card-pad', h('h2', { style: { marginBottom: '8px' } }, 'Log ud'), h('p.small.muted', { style: { marginBottom: '12px' } }, `Logget ind som ${u.email}.`), h('button.btn', { onclick: logout }, icon('logout'), 'Log ud')),
        h('div.card.card-pad', { style: { borderColor: 'color-mix(in srgb, var(--danger) 35%, transparent)' } },
          h('h2', { style: { marginBottom: '8px', color: 'var(--danger)' } }, 'Slet konto'),
          h('p.small.muted', { style: { marginBottom: '12px' } }, 'Sletter permanent din konto, alle fag, uploadede filer og fremskridt. Det kan ikke fortrydes.'),
          h('div.row', pw, h('button.btn.danger.solid', { onclick: async e => {
            if (!(await confirmDialog({ title: 'Slet din konto?', message: 'Alt bliver slettet permanent.', confirm: 'Slet alt', danger: true }))) return;
            withBusy(e.target.closest('button'), async () => {
              try { await post('auth.delete', { password: pw.value }); state.user = null; location.hash = '#/login'; location.reload(); } catch (ex) { showError(ex); }
            });
          } }, 'Slet konto')))));
    }
  };

  clear(view, h('div.page',
    h('div.page-head', h('div', h('h1', 'Indstillinger'))),
    h('div', { style: { display: 'grid', gridTemplateColumns: 'minmax(0, 200px) minmax(0, 1fr)', gap: '24px' }, class: 'settings-grid' }, nav, body)));
  renderNav();
  await renderBody();
}
