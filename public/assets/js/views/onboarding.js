import { h, clear, icon, get, post, state, BOOT, withBusy, showError, toast } from '../lib.js';
import { watchJob, refreshSubjects } from '../app.js';
import { createUploader } from './uploader.js';
import { aiKeysPanel } from './settings.js';

export function onboardingView(el) {
  let step = 0;
  let subject = null;
  let ai = null;
  const steps = [];

  const finish = async (hash = '#/') => {
    try {
      const r = await post('auth.profile', { onboarded: true });
      Object.assign(state.user, r.user);
    } catch { /* not critical */ }
    location.hash = hash;
  };

  const frame = (content, { back = true } = {}) => h('div.onb.fade-in',
    h('div.row', { style: { marginBottom: '28px' } }, h('div.brand', { style: { padding: 0 } }, h('span.brand-mark', icon('logo')), BOOT.appName || 'Studiepilot'), h('span.spacer'),
      h('button.btn.ghost.sm', { onclick: () => { sessionStorage.setItem('skipOnboarding', '1'); finish('#/'); } }, 'Spring over')),
    h('div.onb-steps', steps.map((_, i) => h('i' + (i <= step ? '.on' : '')))),
    content,
    back && step > 0 ? h('button.btn.ghost', { style: { marginTop: '16px' }, onclick: () => { step--; render(); } }, icon('arrowLeft'), 'Tilbage') : null);

  const welcome = () => frame(h('div',
    h('h1', { style: { fontSize: '26px', marginBottom: '8px' } }, `Velkommen, ${state.user.name.split(' ')[0]}!`),
    h('p.muted', { style: { marginBottom: '24px' } }, (BOOT.appName || 'Studiepilot') + ' gør dit eget undervisningsmateriale til alt, hvad du skal bruge for at forberede dig til en prøve. Sådan virker det:'),
    h('div.stack', [
      ['upload', 'Tilføj dit materiale', 'PDF’er, slides, Word, Excel, ZIP-filer, billeder af noter, YouTube-videoer eller websider.'],
      ['sparkles', 'AI bygger dit studieområde', 'Emner, definitioner, vigtige fakta, datoer, en studieguide og flashcards – alt sammen med henvisning til dine kilder.'],
      ['target', 'Øv dig, til du kan det', 'Adaptive quizzer, prøveeksamener, en AI-lærer, trin-for-trin-læring og spaced repetition.'],
    ].map(([ic, t, d]) => h('div.card.card-pad.row', { style: { alignItems: 'flex-start' } }, h('div.file-ic', { style: { color: 'var(--accent)' } }, icon(ic)), h('div', h('div', { style: { fontWeight: 600 } }, t), h('div.small.muted', d))))),
    h('button.btn.primary.lg', { style: { marginTop: '24px' }, onclick: () => { step++; render(); } }, 'Kom i gang', icon('arrowRight'))), { back: false });

  const aiStep = () => {
    const holder = h('div', h('div.loading-block', h('div.spinner')));
    aiKeysPanel(async () => { ai = await get('ai.status'); }).then(p => clear(holder, p));
    return frame(h('div',
      h('h1', { style: { fontSize: '24px', marginBottom: '8px' } }, 'Forbind AI’en'),
      h('p.muted', { style: { marginBottom: '20px' } }, 'Du er administrator for denne side. Tilføj mindst én OpenRouter API-nøgle, så materiale kan analyseres og spørgsmål laves. Du kan tilføje flere nøgler senere under Indstillinger.'),
      holder,
      h('div.row', { style: { marginTop: '20px' } }, h('button.btn.primary.lg', { onclick: async () => {
        ai = await get('ai.status');
        if (!ai.configured) toast('Ingen virkende nøgle endnu – du kan tilføje en senere under Indstillinger.', 'info');
        step++;
        render();
      } }, 'Fortsæt', icon('arrowRight')))));
  };

  const subjectStep = () => {
    const name = h('input.input', { placeholder: 'f.eks. Historie – Den kolde krig', maxlength: 100, style: { height: '44px', fontSize: '15px' } });
    const exam = h('input.input', { type: 'date' });
    const err = h('div');
    const form = h('form', { onsubmit: async e => {
      e.preventDefault();
      if (!name.value.trim()) { clear(err, h('div.form-error', 'Giv dit fag et navn.')); return; }
      await withBusy(form.querySelector('button[type=submit]'), async () => {
        try {
          subject = subject
            ? (await post('subjects.update', { id: subject.id, name: name.value, exam_date: exam.value })).subject
            : (await post('subjects.create', { name: name.value, exam_date: exam.value })).subject;
          refreshSubjects();
          step++;
          render();
        } catch (ex) { clear(err, h('div.form-error', ex.message)); }
      });
    } },
    h('h1', { style: { fontSize: '24px', marginBottom: '8px' } }, 'Hvad læser du op til?'),
    h('p.muted', { style: { marginBottom: '20px' } }, 'Opret dit første fag – ét pr. forløb eller prøve.'),
    err,
    h('div.field', h('label', 'Fagets navn'), name),
    h('div.field', h('label', 'Prøvedato (valgfri)'), exam, h('div.hint', 'Vi bruger den til at minde dig om at læse og foreslå prøveeksamener.')),
    h('button.btn.primary.lg', { type: 'submit' }, 'Fortsæt', icon('arrowRight')));
    if (subject) { name.value = subject.name; exam.value = subject.exam_date || ''; }
    setTimeout(() => name.focus(), 50);
    return frame(form);
  };

  const uploadStep = () => {
    let added = 0;
    const go = h('button.btn.primary.lg', { disabled: true, onclick: async () => {
      await withBusy(go, async () => {
        try {
          const r = await post('subjects.process', { id: subject.id });
          watchJob(r.job);
          finish(`#/s/${subject.id}`);
        } catch (e) { showError(e); }
      });
    } }, icon('sparkles'), 'Byg mit studieområde');
    return frame(h('div',
      h('h1', { style: { fontSize: '24px', marginBottom: '8px' } }, 'Tilføj dit undervisningsmateriale'),
      h('p.muted', { style: { marginBottom: '20px' } }, `Tilføj alt til “${subject.name}”. Du kan tilføje mere senere.`),
      createUploader(() => subject.id, { onAdded: () => { added++; go.disabled = false; } }),
      h('div.row', { style: { marginTop: '20px' } }, go, h('button.btn.ghost', { onclick: () => finish(`#/s/${subject.id}/sources`) }, 'Det gør jeg senere'))));
  };

  function render() {
    clear(el, steps[step]());
  }

  (async () => {
    try { ai = await get('ai.status'); } catch { ai = null; }
    steps.push(welcome);
    if (ai?.is_admin && !ai.configured) steps.push(aiStep);
    steps.push(subjectStep, uploadStep);
    render();
  })();
}
