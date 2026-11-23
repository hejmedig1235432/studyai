import { h, clear, icon, get, post, upload, state, showError, toast, withBusy, confirmDialog, loadingBlock, timeAgo, fmtDate, addRefs, refChips, languageSelect, emptyState, progressBar, escapeHtml, streamPost } from '../lib.js';

// Ready-made criteria the student can start from (and edit).
const PRESETS = {
  'Dansk – stil/essay': ['Besvarelse af opgaven og fokus', 'Struktur og komposition (indledning, afsnit, afslutning)', 'Argumentation og refleksion', 'Brug af tekster, eksempler og citater', 'Sprog, retskrivning og tegnsætning'],
  'Dansk – analyse': ['Analyse af tekstens virkemidler', 'Fortolkning og tema', 'Brug af fagbegreber', 'Belæg med citater fra teksten', 'Perspektivering', 'Sprog og retskrivning'],
  'Engelsk – essay': ['Content and task completion', 'Structure and coherence', 'Grammar and accuracy', 'Vocabulary and style'],
  'Rapport/projekt': ['Problemformulering og afgrænsning', 'Brug af teori og metode', 'Analyse og diskussion', 'Konklusion og sammenhæng', 'Kildebrug og henvisninger', 'Sprog og formidling'],
  'Generel opgave': ['Opfylder opgavens krav', 'Indhold og faglighed', 'Struktur og sammenhæng', 'Sprog og formidling'],
};
const SCALES = { '7': '7-trinsskalaen', points: 'Point (1–10 pr. kriterie)', none: 'Kun skriftlig feedback' };
const MET = { yes: ['checkCircle', 'var(--success)', 'Opfyldt'], partly: ['alert', 'var(--warning)', 'Delvist'], no: ['xCircle', 'var(--danger)', 'Mangler'] };
const VERDICT = { supported: ['checkCircle', 'var(--success)', 'Passer med dit materiale'], contradicted: ['xCircle', 'var(--danger)', 'Passer ikke med dit materiale'], not_in_material: ['info', 'var(--text-3)', 'Står ikke i dit materiale'] };

// The Danish 7-point scale: what each grade means.
const GRADES = [
  ['-3', 'Helt uacceptabel', 'Ikke bestået', 'var(--danger)'],
  ['00', 'Utilstrækkelig', 'Ikke bestået', 'var(--danger)'],
  ['02', 'Tilstrækkelig', 'Lige bestået – det mindst acceptable', 'var(--warning)'],
  ['4', 'Jævn', 'Okay – men med flere væsentlige mangler', 'var(--warning)'],
  ['7', 'God', 'God præstation med en del mangler', 'var(--accent)'],
  ['10', 'Fortrinlig', 'Meget god – kun få mindre mangler', 'var(--success)'],
  ['12', 'Fremragende', 'Fejlfri eller næsten fejlfri – topkarakter', 'var(--success)'],
];
const gradeInfo = g => GRADES.find(x => x[0] === g);

function gradeScale(current) {
  return h('div', { style: { display: 'flex', gap: '4px', flexWrap: 'wrap', marginTop: '10px' } }, GRADES.map(([g, name, desc, color]) => h('span', {
    title: `${g}: ${name} – ${desc}`,
    style: { padding: '3px 8px', borderRadius: '6px', fontSize: '12px', fontWeight: 600, border: '1px solid ' + (g === current ? color : 'var(--border)'),
      background: g === current ? color : 'transparent', color: g === current ? '#fff' : 'var(--text-3)' },
  }, g)));
}

// Steps shown while the AI writes the assessment (keys = the part of the answer being written).
const PROGRESS = [
  ['start', 'Læser din opgave og vælger den hurtigste AI'],
  ['summary', 'Skriver den samlede vurdering'],
  ['criteria', 'Bedømmer hvert kriterie'],
  ['requirements', 'Tjekker opgavens krav'],
  ['strengths', 'Finder det, du gør godt'],
  ['next_steps', 'Vælger det vigtigste at forbedre'],
  ['language_errors', 'Retter sprog og stavning'],
  ['fact_checks', 'Tjekker fakta'],
  ['example_rewrite', 'Skriver et forbedret eksempel'],
];

const scoreColor = s => s >= 8 ? 'var(--success)' : s >= 5 ? 'var(--accent)' : 'var(--warning)';
const words = t => (t.trim().match(/\S+/g) || []).length;

/** #/essay → history + new feedback form;  #/essay/:id → one result. */
export async function essayView(view, id, query = {}) {
  const page = h('div.page');
  clear(view, page);
  if (id) return resultPage(page, +id);
  return formPage(page, query.again ? +query.again : null);
}

// --------------------------------------------------------------------------- form

async function formPage(page, againId) {
  clear(page, loadingBlock());
  const [list, subjects, prev] = await Promise.all([
    get('essay.list'),
    get('subjects.list').catch(() => ({ subjects: [] })),
    againId ? get('essay.get', { id: againId }).then(r => r.review).catch(() => null) : null,
  ]);

  const title = h('input.input', { placeholder: 'F.eks. “Essay om sociale medier”', maxlength: 160, value: prev?.title || '' });
  const subject = h('select.select', h('option', { value: '' }, 'Intet fag (ingen faktatjek)'),
    (subjects.subjects || []).map(s => h('option', { value: s.id, selected: prev?.subject?.id === s.id }, s.name)));
  const assignment = h('textarea.textarea', { rows: 4, placeholder: 'Indsæt opgaveformuleringen fra din lærer (valgfrit, men det giver meget bedre feedback).', value: prev?.assignment || '' });
  const requirements = h('textarea.textarea', { rows: 4, value: (prev?.requirements || []).join('\n'),
    placeholder: 'F.eks.\nMin. 800 ord\nInddrag mindst to kilder\nSkal have indledning, hoveddel og konklusion\nBrug fagbegreber fra forløbet' });
  const criteria = h('textarea.textarea', { rows: 5, placeholder: 'Ét kriterie pr. linje – f.eks. fra din lærers bedømmelsesark.', value: (prev?.criteria || PRESETS['Dansk – stil/essay']).join('\n') });
  const scale = h('select.select', Object.entries(SCALES).map(([k, v]) => h('option', { value: k, selected: (prev?.scale || '7') === k }, v)));
  const lang = languageSelect('', { defaultLabel: 'Som faget / min standard' });
  const essay = h('textarea.textarea', { rows: 16, placeholder: 'Indsæt hele din opgave her – eller upload den som Word, PDF eller tekstfil.', value: prev?.essay || '', style: { fontSize: '14px' } });
  const count = h('span.tiny.faint');
  const updateCount = () => { count.textContent = `${words(essay.value)} ord · ${essay.value.length.toLocaleString('da-DK')} tegn`; };
  essay.addEventListener('input', updateCount);
  updateCount();

  const presetChips = h('div.chips', Object.keys(PRESETS).map(name => h('button.chip', { type: 'button', onclick: () => { criteria.value = PRESETS[name].join('\n'); } }, name)));

  const fileInput = h('input', { type: 'file', accept: '.docx,.pdf,.txt,.md', style: { display: 'none' }, onchange: async () => {
    const f = fileInput.files[0];
    if (!f) return;
    const fd = new FormData();
    fd.append('file', f);
    uploadBtn.disabled = true;
    try {
      const r = await upload('essay.extract', fd);
      essay.value = r.text;
      if (!title.value) title.value = r.title;
      updateCount();
      toast(r.truncated ? 'Teksten er hentet – men den var for lang, så kun begyndelsen er med.' : 'Teksten er hentet fra filen', r.truncated ? 'warning' : 'success');
    } catch (e) { showError(e); }
    uploadBtn.disabled = false;
    fileInput.value = '';
  } });
  const uploadBtn = h('button.btn.sm', { type: 'button', onclick: () => fileInput.click() }, icon('upload'), 'Upload fil');

  const status = h('div');
  const submit = h('button.btn.primary.lg', { type: 'submit', 'data-busy': 'Læser din opgave…' }, icon('sparkles'), againId ? 'Tjek igen' : 'Få feedback');
  const form = h('form.card.card-pad', { onsubmit: async e => {
    e.preventDefault();
    const data = {
      title: title.value, subject_id: subject.value || 0, assignment: assignment.value,
      requirements: requirements.value.split('\n').map(s => s.trim()).filter(Boolean),
      criteria: criteria.value.split('\n').map(s => s.trim()).filter(Boolean), scale: scale.value,
      language: lang.value, essay: essay.value, parent_id: againId || 0,
    };
    // Live progress: which part the AI is writing right now.
    const steps = h('div.stack.sm', { style: { gap: '6px' } });
    const charsEl = h('span.tiny.faint');
    const t0 = Date.now();
    const timer = setInterval(() => { charsEl.dataset.sec = Math.round((Date.now() - t0) / 1000); renderSteps(); }, 1000);
    let current = 'start';
    let chars = 0;
    const renderSteps = () => {
      const idx = PROGRESS.findIndex(([k]) => k === current);
      clear(steps, PROGRESS.map(([k, label], i) => h('div.row', { style: { gap: '8px', opacity: i > idx + 1 ? 0.45 : 1, transition: 'opacity .3s' } },
        h('span', { style: { color: i < idx ? 'var(--success)' : i === idx ? 'var(--accent)' : 'var(--text-3)', display: 'inline-flex' } },
          i < idx ? icon('check') : i === idx ? h('span.spinner', { style: { width: '14px', height: '14px' } }) : icon('clock')),
        h('span.small', { style: { fontWeight: i === idx ? 600 : 400 } }, label))));
      charsEl.textContent = `${charsEl.dataset.sec || 0} s${chars ? ` · ${chars.toLocaleString('da-DK')} tegn skrevet` : ''}`;
    };
    renderSteps();
    clear(status, h('div.card.card-pad', { style: { marginTop: '14px' } },
      h('div.row.between', { style: { marginBottom: '10px' } }, h('b', 'Studiepilot læser din opgave'), charsEl), steps));
    await withBusy(submit, async () => {
      try {
        let r;
        try {
          r = await streamPost('essay.review.stream', data, (event, d) => {
            if (event === 'progress') {
              if (d.section === 'retry') { current = 'start'; chars = 0; } else { current = d.section; chars = d.chars || chars; }
              renderSteps();
            }
          });
        } catch (ex) {
          // Streaming not possible (e.g. a proxy that buffers): fall back to the normal request.
          if (ex.status !== 0 || ex.code === 'banned') throw ex;
          r = await post('essay.review', data);
        }
        clearInterval(timer);
        location.hash = `#/essay/${r.review.id}`;
      } catch (ex) { clearInterval(timer); clear(status); showError(ex); }
    });
  } },
    h('div.grid.cols-2', { style: { gap: '0 14px' } },
      h('div.field', h('label', 'Titel'), title),
      h('div.field', h('label', 'Fag'), subject, h('div.hint', 'Vælger du et fag, tjekkes dine fakta mod dit materiale.'))),
    h('div.field', h('label', 'Opgaveformulering'), assignment),
    h('div.field', h('label', 'Krav til opgaven'), requirements,
      h('div.hint', 'Ét krav pr. linje. Hvert krav bliver tjekket, og du får at vide, hvad du mangler. Krav om længde (ord, tegn eller normalsider) tælles automatisk.')),
    h('div.field', h('label', 'Bedømmelseskriterier'), presetChips, h('div', { style: { height: '8px' } }), criteria),
    h('div.grid.cols-2', { style: { gap: '0 14px' } },
      h('div.field', h('label', 'Bedømmelse'), scale),
      h('div.field', h('label', 'Sprog i feedbacken'), lang)),
    h('div.field',
      h('div.row.between', { style: { marginBottom: '6px' } }, h('label', { style: { margin: 0 } }, 'Din opgave'), h('div.row', { style: { gap: '10px' } }, count, uploadBtn, fileInput)),
      essay,
      againId ? h('div.hint', 'Ret din tekst ovenfor, og tjek igen – så kan du se, hvor meget du er blevet bedre.') : null),
    h('div.row.wrap', submit, h('span.small.faint', 'Studiepilot skriver ikke opgaven for dig – du får feedback, så du selv kan gøre den bedre.')),
    status);

  const history = list.reviews.length ? h('div.card',
    h('div.card-head', h('h3', 'Tidligere feedback')),
    h('div.list', list.reviews.map(r => h('a.list-item.click', { href: `#/essay/${r.id}`, style: { color: 'inherit', textDecoration: 'none' } },
      h('div.file-ic', icon('edit')),
      h('div', { style: { flex: 1, minWidth: 0 } },
        h('div', { style: { fontWeight: 550, overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' } }, r.title),
        h('div.tiny.faint', [r.subject_name, timeAgo(r.created_at), r.parent_id ? 'genbedømt' : null].filter(Boolean).join(' · '))),
      r.grade ? h('span.badge.accent', 'Karakter ' + r.grade) : null,
      r.score !== null ? h('span.badge', `${r.score}/100`) : null)))) : null;

  clear(page,
    h('div.page-head', h('div', h('h1', againId ? 'Tjek din opgave igen' : 'Opgavefeedback'),
      h('div.sub', 'Få din opgave bedømt ud fra din lærers kriterier – med point, konkrete forbedringer og retskrivning.'))),
    h('div.split', h('div.stack', form), h('div.stack', history || h('div.card.card-pad', emptyState({ icon: 'edit', title: 'Ingen feedback endnu', text: 'Indsæt din opgave og dine kriterier, så får du en bedømmelse på et par minutter.' })),
      h('div.card.card-pad.small.muted', h('h3', { style: { marginBottom: '8px' } }, 'Sådan får du den bedste feedback'),
        h('ul', { style: { margin: 0, paddingLeft: '18px', lineHeight: 1.7 } },
          h('li', 'Indsæt opgaveformuleringen, så kravene kan tjekkes.'),
          h('li', 'Brug din lærers kriterier, hvis du har dem.'),
          h('li', 'Vælg faget, så fakta tjekkes mod dit materiale.'),
          h('li', 'Ret dine fejl, og tryk “Ret og tjek igen”.'))))));
}

// --------------------------------------------------------------------------- result

async function resultPage(page, id) {
  clear(page, loadingBlock('Henter feedback…'));
  const { review: r } = await get('essay.get', { id });
  addRefs(r.refs);
  const d = r.result || {};
  const prevBy = Object.fromEntries((r.parent?.criteria || []).map(c => [c.name, c.score]));
  const delta = (now, before) => before == null || now == null || now === before ? null
    : h('span.tiny', { style: { color: now > before ? 'var(--success)' : 'var(--danger)', fontWeight: 600 } }, (now > before ? '▲ +' : '▼ ') + (now - before));

  const section = (ic, titleText, ...body) => h('div.card', h('div.card-head', h('h3', icon(ic), ' ', titleText)), h('div.card-body', ...body));

  // The student's text, with language errors highlighted.
  const essayHtml = () => {
    let html = escapeHtml(r.essay);
    for (const e of d.language_errors || []) {
      const q = escapeHtml(e.quote);
      const i = html.indexOf(q);
      if (i >= 0) html = html.slice(0, i) + `<mark title="${escapeHtml(e.correction)}" style="background:var(--danger-soft);color:inherit;border-bottom:2px solid var(--danger);border-radius:2px">${q}</mark>` + html.slice(i + q.length);
    }
    const div = h('div', { style: { whiteSpace: 'pre-wrap', lineHeight: 1.7, fontSize: '14px' } });
    div.innerHTML = html;
    return div;
  };

  const header = h('div.page-head',
    h('div', h('a.small.faint', { href: '#/essay', style: { display: 'inline-flex', alignItems: 'center', gap: '4px' } }, icon('arrowLeft'), 'Opgavefeedback'),
      h('h1', r.title),
      h('div.sub', [r.subject?.name, fmtDate(r.created_at), `${d.stats?.words ?? words(r.essay)} ord`, SCALES[r.scale]].filter(Boolean).join(' · '))),
    h('div.row', { style: { gap: '8px' } },
      h('a.btn.primary', { href: `#/essay?again=${r.id}` }, icon('repeat'), 'Ret og tjek igen'),
      h('button.btn.ghost.icon', { 'aria-label': 'Slet', onclick: async () => {
        if (!(await confirmDialog({ title: 'Slet denne feedback?', message: 'Din tekst og bedømmelsen bliver slettet.', confirm: 'Slet', danger: true }))) return;
        try { await post('essay.delete', { id: r.id }); toast('Feedbacken er slettet', 'success'); location.hash = '#/essay'; } catch (e) { showError(e); }
      } }, icon('trash'))));

  const scoreCard = h('div.card.card-pad', { style: { display: 'flex', gap: '24px', alignItems: 'center', flexWrap: 'wrap' } },
    h('div', { style: { textAlign: 'center', minWidth: '120px' } },
      d.grade ? h('div', h('div.tiny.faint', 'Forventet karakter'),
        h('div', { style: { fontSize: '44px', fontWeight: 700, letterSpacing: '-.03em', lineHeight: 1.1, color: gradeInfo(d.grade)?.[3] } }, d.grade),
        gradeInfo(d.grade) ? h('div', { style: { fontWeight: 600, color: gradeInfo(d.grade)[3] } }, gradeInfo(d.grade)[1]) : null,
        gradeInfo(d.grade) ? h('div.tiny.faint', { style: { maxWidth: '170px', margin: '0 auto' } }, gradeInfo(d.grade)[2]) : null)
        : h('div', h('div.tiny.faint', 'Samlet score'), h('div', { style: { fontSize: '44px', fontWeight: 700, lineHeight: 1.1 } }, String(d.overall_score))),
      h('div.small.faint', d.grade ? `${d.overall_score}/100 point` : 'af 100'),
      r.parent ? h('div.small', { style: { marginTop: '4px' } }, 'Sidst: ', r.parent.grade ? `${r.parent.grade} · ` : '', `${r.parent.score}/100 `, delta(d.overall_score, r.parent.score)) : null),
    h('div', { style: { flex: '1 1 300px' } },
      h('p', { style: { margin: '0 0 8px', lineHeight: 1.6 } }, d.summary),
      d.grade_note ? h('p.small.muted', { style: { margin: 0 } }, d.grade_note) : null,
      d.grade ? gradeScale(d.grade) : null,
      h('div.tiny.faint', { style: { marginTop: '8px' } }, 'Bedømmelsen er vejledende – din lærer bestemmer den endelige karakter.')));

  const nextSteps = d.next_steps?.length ? h('div.card.card-pad', { style: { borderColor: 'var(--accent-ring)', background: 'var(--accent-soft)' } },
    h('h3', { style: { marginBottom: '10px' } }, icon('target'), ' Det vigtigste at forbedre'),
    h('ol', { style: { margin: 0, paddingLeft: '20px', lineHeight: 1.7 } }, d.next_steps.map(s => h('li', s)))) : null;

  const criteriaCards = section('chart', 'Bedømmelse pr. kriterie', h('div.stack', (d.criteria || []).map(c => h('div', { style: { paddingBottom: '14px', borderBottom: '1px solid var(--border)' } },
    h('div.row.between', { style: { marginBottom: '6px' } }, h('b', c.name), h('span.row', { style: { gap: '8px' } }, delta(c.score, prevBy[c.name]), h('b', { style: { color: scoreColor(c.score) } }, `${c.score}/10`))),
    progressBar(c.score * 10, c.score >= 8 ? 'success' : c.score >= 5 ? '' : 'warning'),
    h('p', { style: { margin: '10px 0 6px', lineHeight: 1.6 } }, c.assessment),
    (c.evidence || []).map(q => h('div.small', { style: { borderLeft: '3px solid var(--border-strong)', padding: '2px 10px', margin: '6px 0', color: 'var(--text-2)', fontStyle: 'italic' } }, `“${q}”`)),
    c.improvements?.length ? h('ul.small', { style: { margin: '6px 0 0', paddingLeft: '18px', lineHeight: 1.6 } }, c.improvements.map(t => h('li', t))) : null))));

  // Requirements: what is met and – most importantly – what is missing.
  const reqList = d.requirements || [];
  const metCount = reqList.filter(q => q.met === 'yes').length;
  const reqs = reqList.length ? h('div.card',
    h('div.card-head', h('h3', icon('checkCircle'), ' Opgavens krav – det mangler du'),
      h('span.badge' + (metCount === reqList.length ? '.success' : '.warning'), `${metCount} af ${reqList.length} opfyldt`)),
    h('div.card-body', h('div.stack.sm', reqList.map(q => {
      const [ic, color, label] = MET[q.met] || MET.partly;
      return h('div.row', { style: { alignItems: 'flex-start', gap: '10px', paddingBottom: '10px', borderBottom: '1px solid var(--border)' } },
        h('span', { style: { color, marginTop: '2px' } }, icon(ic)),
        h('div', { style: { flex: 1 } },
          h('div', h('b', q.requirement), h('span.tiny', { style: { color, marginLeft: '8px', fontWeight: 600 } }, label),
            q.auto ? h('span.badge', { style: { marginLeft: '6px' } }, 'Talt') : null, q.given === undefined && r.requirements?.length ? h('span.tiny.faint', { style: { marginLeft: '6px' } }, '(fra opgaveformuleringen)') : null),
          q.missing ? h('div.small', { style: { marginTop: '3px', color: q.met === 'no' ? 'var(--danger)' : 'var(--warning)', fontWeight: 500, display: 'flex', gap: '6px', alignItems: 'flex-start' } },
            h('span', { style: { flex: 'none', marginTop: '1px' } }, icon('arrowRight')), h('span', q.missing)) : null,
          q.note ? h('div.small.muted', { style: { marginTop: '2px' } }, q.note) : null));
    })))) : null;

  const strengths = d.strengths?.length ? section('trophy', 'Det gør du godt', h('ul', { style: { margin: 0, paddingLeft: '18px', lineHeight: 1.7 } }, d.strengths.map(s => h('li', s)))) : null;

  const errors = section('edit', `Sprog og retskrivning (${(d.language_errors || []).length})`,
    d.language_errors?.length ? h('div.stack.sm', d.language_errors.map(e => h('div', { style: { padding: '8px 0', borderBottom: '1px solid var(--border)' } },
      h('div', h('span', { style: { textDecoration: 'line-through', color: 'var(--danger)' } }, e.quote), ' → ', h('b', { style: { color: 'var(--success)' } }, e.correction)),
      e.explanation ? h('div.small.muted', e.explanation) : null)))
      : h('p.small.muted', { style: { margin: 0 } }, 'Ingen tydelige sprogfejl fundet. Flot!'));

  const rewrite = d.example_rewrite ? section('wand', 'Eksempel på en forbedret passage',
    h('div.small.faint', 'Før'), h('p', { style: { margin: '4px 0 12px', color: 'var(--text-2)' } }, d.example_rewrite.original),
    h('div.small.faint', 'Efter'), h('p', { style: { margin: '4px 0 8px', fontWeight: 500 } }, d.example_rewrite.improved),
    d.example_rewrite.why ? h('div.small.muted', d.example_rewrite.why) : null) : null;

  const facts = d.fact_checks?.length ? section('search', 'Faktatjek mod dit materiale', h('div.stack.sm', d.fact_checks.map(f => {
    const [ic, color, label] = VERDICT[f.verdict] || VERDICT.not_in_material;
    return h('div.row', { style: { alignItems: 'flex-start', gap: '10px' } }, h('span', { style: { color, marginTop: '2px' } }, icon(ic)),
      h('div', h('div', h('b', f.claim)), h('div.small', { style: { color } }, label), f.note ? h('div.small.muted', f.note, ' ', refChips(f.refs.map(String))) : refChips(f.refs.map(String))));
  }))) : null;

  const textCard = h('details.card', h('summary.card-head', { style: { cursor: 'pointer' } }, h('h3', icon('file'), ' Din tekst', d.language_errors?.length ? h('span.tiny.faint', ' – sprogfejl er markeret') : null)),
    h('div.card-body', essayHtml()));

  clear(page, header,
    h('div.stack', scoreCard, reqs, nextSteps,
      h('div.split', h('div.stack', criteriaCards, rewrite), h('div.stack', strengths, errors, facts)),
      textCard));
}
