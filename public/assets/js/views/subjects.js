import { h, clear, icon, get, post, state, menu, confirmDialog, toast, showError, timeAgo, progressBar, emptyState, subjectIcon, plural, withBusy, modal, languageSelect } from '../lib.js';
import { refreshSubjects, watchJob } from '../app.js';
import { createUploader } from './uploader.js';
import { shareDialog } from './share.js';

const COLORS = ['#6d7cff', '#8b5cf6', '#22c55e', '#f59e0b', '#ef4444', '#06b6d4', '#ec4899', '#14b8a6'];

export function statusBadge(s) {
  if (s.job || s.status === 'processing') return h('span.badge.accent', h('span.spinner', { style: { width: '10px', height: '10px', borderWidth: '1.5px' } }), 'Behandler');
  return {
    empty: h('span.badge', 'Ingen kilder'),
    outdated: h('span.badge.warning', 'Skal analyseres igen'),
    error: h('span.badge.danger', 'Fejl'),
    ready: null,
  }[s.status] ?? null;
}

export function editSubjectDialog(s, onSaved) {
  const name = h('input.input', { value: s.name, maxlength: 100 });
  const desc = h('textarea.textarea', { value: s.description || '', rows: 3, maxlength: 500 });
  const exam = h('input.input', { type: 'date', value: s.exam_date || '' });
  const lang = languageSelect(s.language || '', { defaultLabel: 'Brug mit standardsprog' });
  let color = s.color;
  const swatches = h('div.row.wrap', COLORS.map(c => h('button', {
    type: 'button', title: c,
    style: { width: '26px', height: '26px', borderRadius: '8px', background: c, border: c === color ? '2px solid var(--text)' : '2px solid transparent', cursor: 'pointer' },
    onclick: e => { color = c; swatches.querySelectorAll('button').forEach(b => (b.style.border = '2px solid transparent')); e.currentTarget.style.border = '2px solid var(--text)'; },
  })));
  const m = modal({
    title: 'Redigér fag',
    body: h('div', h('div.field', h('label', 'Navn'), name), h('div.field', h('label', 'Beskrivelse'), desc), h('div.field', h('label', 'Prøve-/eksamensdato'), exam, h('div.hint', 'Bruges til at prioritere påmindelser og prøveeksamener.')), h('div.field', h('label', 'AI-sprog for dette fag'), lang, h('div.hint', 'F.eks. Engelsk til engelskfaget, selv om noget af materialet er på dansk.')), h('div.field', h('label', 'Farve'), swatches)),
    footer: [h('button.btn', { onclick: () => m.close() }, 'Annuller'), h('button.btn.primary', { onclick: async e => {
      await withBusy(e.currentTarget, async () => {
        try {
          const r = await post('subjects.update', { id: s.id, name: name.value, description: desc.value, exam_date: exam.value, color, subject_language: lang.value });
          m.close();
          toast('Faget er gemt', 'success');
          await refreshSubjects();
          onSaved?.(r.subject);
        } catch (ex) { showError(ex); }
      });
    } }, 'Gem')],
  });
}

export async function deleteSubject(s) {
  const ok = await confirmDialog({ title: `Slet “${s.name}”?`, message: 'Dette sletter permanent fagets kilder, studieguide, quizzer, flashcards og fremskridt. Det kan ikke fortrydes.', confirm: 'Slet fag', danger: true });
  if (!ok) return false;
  try {
    await post('subjects.delete', { id: s.id });
    toast('Faget er slettet', 'success');
    await refreshSubjects();
    return true;
  } catch (e) { showError(e); return false; }
}

export async function subjectsView(view) {
  const page = h('div.page');
  clear(view, page);
  const render = async () => {
    const subjects = await refreshSubjects();
    clear(page,
      h('div.page-head', h('div', h('h1', 'Fag'), h('div.sub', 'Hvert fag er et arbejdsområde, der bygges ud fra det materiale, du tilføjer.')),
        h('div.actions', h('a.btn.primary', { href: '#/new' }, icon('plus'), 'Nyt fag'))),
      subjects.length
        ? h('div.grid.auto', subjects.map(s => h('div.card.hoverable.card-pad.fade-in', { onclick: e => { if (!e.target.closest('button')) location.hash = `#/s/${s.id}`; } },
          h('div.row', { style: { marginBottom: '14px' } }, subjectIcon(s, 36), h('div.spacer'), statusBadge(s),
            h('button.btn.ghost.icon.sm', { 'aria-label': 'Fagmenu', onclick: e => { e.stopPropagation(); menu(e.currentTarget, [
              { label: 'Åbn', icon: 'arrowRight', onClick: () => (location.hash = `#/s/${s.id}`) },
              { label: 'Redigér detaljer', icon: 'edit', onClick: () => editSubjectDialog(s, render) },
              { label: 'Tilføj kilder', icon: 'upload', onClick: () => (location.hash = `#/s/${s.id}/sources`) },
              { label: 'Del fag', icon: 'link', onClick: () => shareDialog(s) },
              'sep',
              { label: 'Slet', icon: 'trash', danger: true, onClick: async () => { if (await deleteSubject(s)) render(); } },
            ]); } }, icon('more'))),
          h('h3', { style: { fontSize: '15px' } }, s.name),
          h('p.small.muted', { style: { margin: '4px 0 14px', minHeight: '20px' } }, s.description || `${plural(s.source_count, 'kilde', 'kilder')} · ${plural(s.topic_count, 'emne', 'emner')}`),
          h('div.meter', progressBar(s.progress), h('span.pct', s.progress + '%')),
          h('div.row.between.tiny.faint', { style: { marginTop: '10px' } },
            h('span', `${plural(s.card_count, 'kort', 'kort')}${s.due_cards ? ` · ${s.due_cards} klar` : ''}`),
            h('span', s.exam_date ? `Prøve ${new Date(s.exam_date + 'T12:00:00').toLocaleDateString('da-DK', { month: 'short', day: 'numeric' })}` : `Læst ${timeAgo(s.last_studied_at)}`)))))
        : h('div.card', emptyState({ icon: 'layers', title: 'Opret dit første fag', text: 'Et fag samler materialet til ét forløb eller én prøve – f.eks. “Biologi kapitel 4” eller “Historieeksamen om 2. verdenskrig”.', action: h('a.btn.primary', { href: '#/new' }, icon('plus'), 'Nyt fag') })));
  };
  await render();
}

export async function newSubjectView(view) {
  let subject = null;
  let added = 0;
  const name = h('input.input', { placeholder: 'f.eks. Biologi – Celledeling', maxlength: 100, autofocus: true });
  const exam = h('input.input', { type: 'date' });
  const lang = languageSelect('', { defaultLabel: 'Mit standardsprog' });
  const err = h('div');
  const createBtn = h('button.btn.primary.lg', { disabled: true, onclick: async () => {
    await withBusy(createBtn, async () => {
      try {
        const id = await ensureSubject();
        const r = await post('subjects.process', { id });
        watchJob(r.job);
        location.hash = `#/s/${id}`;
      } catch (e) { showError(e); }
    });
  } }, icon('sparkles'), 'Byg mit studieområde');
  const counter = h('span.muted.small', 'Tilføj mindst én kilde for at fortsætte.');

  async function ensureSubject() {
    if (subject) {
      if (name.value.trim() && name.value.trim() !== subject.name) {
        subject = (await post('subjects.update', { id: subject.id, name: name.value.trim(), exam_date: exam.value })).subject;
      }
      return subject.id;
    }
    const n = name.value.trim();
    if (!n) {
      clear(err, h('div.form-error', 'Giv faget et navn først.'));
      name.focus();
      throw new Error('Giv faget et navn først.');
    }
    clear(err);
    subject = (await post('subjects.create', { name: n, exam_date: exam.value, subject_language: lang.value })).subject;
    refreshSubjects();
    return subject.id;
  }

  const uploader = createUploader(ensureSubject, {
    onAdded: () => {
      added++;
      createBtn.disabled = false;
      counter.textContent = `${plural(added, 'kilde', 'kilder')} tilføjet. Tilføj flere, eller byg dit studieområde nu.`;
    },
  });

  clear(view, h('div.page.narrow',
    h('div.crumbs', h('a', { href: '#/subjects' }, 'Fag'), icon('chevronRight'), 'Nyt'),
    h('div.page-head', h('div', h('h1', 'Nyt fag'), h('div.sub', 'Giv det et navn, tilføj dit materiale, så bygger vi resten.'))),
    err,
    h('div.card.card-pad', { style: { marginBottom: '16px' } },
      h('div.grid.cols-2', { style: { gap: '12px' } },
        h('div.field', { style: { margin: 0 } }, h('label', 'Fagets navn'), name),
        h('div.field', { style: { margin: 0 } }, h('label', 'Prøvedato (valgfri)'), exam),
        h('div.field', { style: { margin: 0 } }, h('label', 'AI-sprog'), lang, h('div.hint', 'Sprog for studieguide, quizzer og AI-lærer.')))),
    h('div.card.card-pad', { style: { marginBottom: '16px' } },
      h('h3', { style: { marginBottom: '4px' } }, 'Tilføj undervisningsmateriale'),
      h('p.small.muted', { style: { marginBottom: '14px' } }, 'Kombinér så mange filer, links og noter du vil – de bliver alle til ét fag.'),
      uploader),
    h('div.row.wrap', { style: { justifyContent: 'space-between' } }, counter, createBtn)));
}
