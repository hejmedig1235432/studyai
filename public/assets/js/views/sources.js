import { h, clear, icon, get, post, menu, showError, toast, withBusy, confirmDialog, promptDialog, emptyState, loadingBlock, sourceIcon, kindLabel, openSourceViewer, timeAgo } from '../lib.js';
import { onJob, jobFor, refreshSubjects } from '../app.js';
import { createUploader } from './uploader.js';
import { processingCard } from './workspace.js';

const STATUS = {
  queued: ['badge', 'Venter'],
  processing: ['badge.accent', 'Læser…'],
  ocr: ['badge.accent', 'Kører OCR…'],
  ready: ['badge.success', 'Klar'],
  error: ['badge.danger', 'Fejlede'],
};

export async function sourcesTab(el, ctx) {
  let added = 0;
  const listBox = h('div');
  const banner = h('div');
  let proc = null;

  const renderBanner = () => {
    proc?.cleanup?.();
    proc = null;
    const job = jobFor(ctx.id);
    if (job && ['pending', 'running', 'failed'].includes(job.status)) {
      proc = processingCard(ctx);
      clear(banner, proc);
    } else if (added > 0 || ctx.data.subject.status === 'outdated' || ctx.data.subject.status === 'empty' && ctx.data.subject.source_count) {
      clear(banner, h('div.notice.info', icon('sparkles'), h('div', { style: { flex: 1 } }, h('b', 'Nyt materiale tilføjet. '), 'Analysér det for at opdatere studieguide, emner og flashcards. Dine fremskridt bevares.'),
        h('button.btn.primary.sm', { onclick: e => withBusy(e.currentTarget, async () => { await ctx.startProcessing(); added = 0; renderBanner(); }) }, icon('sparkles'), ctx.data.analysis ? 'Analysér igen' : 'Analysér nu')));
    } else clear(banner);
  };

  const renderList = async () => {
    const { sources } = await get('sources.list', { subject_id: ctx.id });
    const roots = sources.filter(s => !s.parent_id);
    const children = id => sources.filter(s => s.parent_id === id);
    const row = (s, nested = false) => {
      const [cls, label] = STATUS[s.status] || STATUS.queued;
      return h('div.upload-item', { style: nested ? { paddingLeft: '52px', background: 'var(--bg-elev)' } : {} },
        sourceIcon(s),
        h('div', { style: { flex: 1, minWidth: 0 } },
          h('div.row', { style: { gap: '8px' } }, h('span.truncate', { style: { fontWeight: 550 } }, s.title), h('span.' + cls, label)),
          h('div.tiny.faint', [kindLabel(s), s.char_count ? `${Math.round(s.char_count / 1000)}k tegn` : null, s.meta?.pages ? `${s.meta.pages} sider` : null, s.meta?.ocr_pages ? `${s.meta.ocr_pages} sider læst med OCR` : null, s.meta?.children ? `${s.meta.children} filer indeni` : null, `tilføjet ${timeAgo(s.created_at)}`].filter(Boolean).join(' · ')),
          s.error ? h('div.small', { style: { color: 'var(--danger)', marginTop: '3px' } }, s.error) : null,
          s.meta?.notice ? h('div.tiny', { style: { color: 'var(--warning)', marginTop: '3px' } }, s.meta.notice) : null,
          s.url ? h('a.tiny', { href: s.url, target: '_blank', rel: 'noopener noreferrer' }, s.url) : null),
        h('button.btn.ghost.icon.sm', { 'aria-label': 'Kildeindstillinger', onclick: e => menu(e.currentTarget, [
          s.status === 'ready' && s.chunk_count ? { label: 'Se udtrukket tekst', icon: 'eye', onClick: () => openSourceViewer(s.id) } : null,
          { label: 'Omdøb', icon: 'edit', onClick: async () => {
            const t = await promptDialog({ title: 'Omdøb kilde', value: s.title });
            if (!t) return;
            try { await post('sources.rename', { id: s.id, title: t }); renderList(); } catch (ex) { showError(ex); }
          } },
          s.status === 'error' ? { label: 'Prøv igen', icon: 'refresh', onClick: async () => { try { await post('sources.retry', { id: s.id }); await ctx.startProcessing(); renderList(); } catch (ex) { showError(ex); } } } : null,
          'sep',
          { label: 'Fjern', icon: 'trash', danger: true, onClick: async () => {
            if (!(await confirmDialog({ title: 'Fjern denne kilde?', message: `“${s.title}” og den udtrukne tekst bliver slettet. Analysér igen bagefter for at opdatere studieguiden.`, confirm: 'Fjern', danger: true }))) return;
            try { await post('sources.delete', { id: s.id }); toast('Kilden er fjernet', 'success'); await ctx.reload(); refreshSubjects(); renderBanner(); renderList(); } catch (ex) { showError(ex); }
          } },
        ].filter(Boolean)) }, icon('more')));
    };
    clear(listBox, roots.length
      ? h('div.card', h('div.card-head', h('h3', 'Kilder'), h('span.badge', String(roots.length))), roots.map(s => [row(s), children(s.id).map(c => row(c, true))]))
      : h('div.card', emptyState({ icon: 'folder', title: 'Ingen kilder endnu', text: 'Tilføj filer, links eller tekst ovenfor.' })));
  };

  const uploader = createUploader(() => ctx.id, {
    onAdded: () => { added++; renderBanner(); },
    onIdle: async () => { await ctx.reload(); renderList(); renderBanner(); refreshSubjects(); },
  });

  const off = onJob(async (job, extra) => {
    if (job.subject_id !== ctx.id) return;
    if (extra?.sources || !['pending', 'running'].includes(job.status)) renderList().catch(() => {});
    if (!['pending', 'running'].includes(job.status)) { added = 0; renderBanner(); }
  });

  clear(el, h('div.stack', banner,
    h('div.card.card-pad', h('h3', { style: { marginBottom: '4px' } }, 'Tilføj materiale'), h('p.small.muted', { style: { marginBottom: '14px' } }, 'Alt, hvad du tilføjer her, samles i dette fag.'), uploader),
    listBox));
  renderBanner();
  clear(listBox, loadingBlock());
  await renderList();
  return () => { off(); proc?.cleanup?.(); };
}
