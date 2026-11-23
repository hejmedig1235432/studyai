import { h, clear, icon, post, upload, BOOT, fmtBytes, sourceIcon, withBusy, toast } from '../lib.js';

const DOC = ['pdf', 'docx', 'pptx', 'xlsx', 'zip', 'txt', 'md', 'markdown', 'csv', 'html', 'htm', 'json'];
const IMG = ['png', 'jpg', 'jpeg', 'webp', 'gif'];
const OLD = { doc: 'docx', ppt: 'pptx', xls: 'xlsx', odt: 'docx', odp: 'pptx', ods: 'xlsx', rtf: 'docx', pages: 'docx', key: 'pptx' };
const MAX_OCR_PAGES = 200;

let pdfjsPromise = null;
function loadPdfJs() {
  if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
  pdfjsPromise ??= new Promise((resolve, reject) => {
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js';
    s.onload = () => {
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      resolve(window.pdfjsLib);
    };
    s.onerror = () => { pdfjsPromise = null; reject(new Error('pdf.js could not be loaded')); };
    document.head.appendChild(s);
  });
  return pdfjsPromise;
}

/** Extracts text per page in the browser; renders pages without a text layer to JPEG for OCR. */
async function readPdf(file, onStatus, byteBudget) {
  const lib = await loadPdfJs();
  let pdf;
  try {
    pdf = await lib.getDocument({ data: await file.arrayBuffer() }).promise;
  } catch (e) {
    if (e?.name === 'PasswordException') throw new Error('PDF’en er beskyttet med adgangskode. Fjern adgangskoden og upload den igen.');
    throw new Error('PDF’en kunne ikke åbnes. Den er måske beskadiget.');
  }
  const pages = [];
  const ocr = [];
  let ocrBytes = 0;
  let skippedOcr = 0;
  for (let i = 1; i <= pdf.numPages; i++) {
    onStatus(`Læser side ${i} af ${pdf.numPages}`);
    const page = await pdf.getPage(i);
    const tc = await page.getTextContent();
    let text = '';
    for (const it of tc.items) text += (it.str || '') + (it.hasEOL ? '\n' : ' ');
    text = text.replace(/[ \t]+/g, ' ').replace(/ *\n */g, '\n').trim();
    pages.push(text);
    if (text.replace(/\s/g, '').length < 30) {
      if (ocr.length >= MAX_OCR_PAGES || ocrBytes > byteBudget) { skippedOcr++; continue; }
      onStatus(`Scanner side ${i} til OCR`);
      const vp1 = page.getViewport({ scale: 1 });
      const scale = Math.min(2, 1700 / Math.max(vp1.width, vp1.height));
      const vp = page.getViewport({ scale });
      const canvas = document.createElement('canvas');
      canvas.width = Math.round(vp.width);
      canvas.height = Math.round(vp.height);
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);
      await page.render({ canvasContext: ctx, viewport: vp }).promise;
      const blob = await new Promise(res => canvas.toBlob(res, 'image/jpeg', 0.8));
      if (blob) { ocr.push({ label: 'p. ' + i, blob }); ocrBytes += blob.size; }
    }
    page.cleanup();
  }
  return { pages, ocr, skippedOcr };
}

/**
 * Upload panel with Files / Link / Paste tabs.
 * @param {() => Promise<number>|number} getSubjectId resolves the subject to add to (may create it)
 * @param {{onAdded?: Function, onIdle?: Function, compact?: boolean}} opts
 */
export function createUploader(getSubjectId, opts = {}) {
  const limits = BOOT.limits || { max_bytes: 50 * 1048576 };
  const list = h('div');
  const listCard = h('div.card.hidden', { style: { marginTop: '14px' } }, list);
  const queue = [];
  let busy = false;
  let successCount = 0;

  const addRow = (title, sub, ext, kind = 'file') => {
    const status = h('div.tiny.faint', sub);
    const bar = h('div.progress', { style: { width: '120px', display: 'none' } }, h('i', { style: { width: '0%' } }));
    const right = h('div.row', { style: { gap: '8px' } }, bar);
    const row = h('div.upload-item', sourceIcon({ kind, ext }), h('div', { style: { flex: 1, minWidth: 0 } }, h('div.truncate', { style: { fontWeight: 500 } }, title), status), right);
    list.prepend(row);
    listCard.classList.remove('hidden');
    return {
      status: (t, cls) => { status.textContent = t; status.className = 'tiny ' + (cls || 'faint'); },
      progress: p => { bar.style.display = p === null ? 'none' : ''; bar.firstChild.style.width = Math.round((p || 0) * 100) + '%'; },
      done: () => { bar.style.display = 'none'; clear(right, h('span.badge.success', icon('check'), 'Tilføjet')); },
      fail: msg => { bar.style.display = 'none'; clear(right, h('span.badge.danger', 'Fejlede')); status.textContent = msg; status.className = 'tiny'; status.style.color = 'var(--danger)'; },
    };
  };

  const idle = () => {
    if (!busy && !queue.length && successCount > 0) {
      const n = successCount;
      successCount = 0;
      opts.onIdle?.(n);
    }
  };

  async function processQueue() {
    if (busy) return;
    busy = true;
    while (queue.length) {
      const { file, row } = queue.shift();
      const ext = file.name.split('.').pop().toLowerCase();
      try {
        const subjectId = await getSubjectId();
        const fd = new FormData();
        fd.append('subject_id', subjectId);
        fd.append('file', file);
        if (ext === 'pdf') {
          try {
            const res = await readPdf(file, t => row.status(t), limits.max_bytes * 0.7 - file.size);
            fd.append('pdf_pages', JSON.stringify(res.pages));
            res.ocr.forEach((o, i) => fd.append('ocr_pages[]', o.blob, `p${i}.jpg`));
            fd.append('ocr_labels', JSON.stringify(res.ocr.map(o => o.label)));
            if (res.skippedOcr) toast(`${file.name}: ${res.skippedOcr} scannede sider blev sprunget over (upload-grænse). Del PDF’en op for at få dem med.`, 'info', 8000);
          } catch (e) {
            if (/password|damaged/.test(e.message)) throw e;
            // pdf.js unavailable (offline?) → server-side extraction fallback.
          }
        }
        row.status('Uploader…');
        row.progress(0);
        const r = await upload('sources.upload', fd, p => { row.progress(p); row.status(p < 1 ? `Uploader ${Math.round(p * 100)}%` : 'Gemmer…'); });
        row.progress(null);
        row.done();
        row.status(fmtBytes(file.size) + ' · venter på analyse');
        successCount++;
        opts.onAdded?.(r.source);
      } catch (e) {
        row.fail(e.message);
      }
    }
    busy = false;
    idle();
  }

  function addFiles(files) {
    for (const file of files) {
      const ext = (file.name.split('.').pop() || '').toLowerCase();
      if (OLD[ext]) { addRow(file.name, '', ext).fail(`Gammelt format – gem den som .${OLD[ext]} eller PDF først.`); continue; }
      if (![...DOC, ...IMG].includes(ext)) { addRow(file.name, '', ext).fail('Filtypen understøttes ikke.'); continue; }
      if (file.size > limits.max_bytes) { addRow(file.name, '', ext).fail(`For stor (${fmtBytes(file.size)}). Maks. er ${fmtBytes(limits.max_bytes)}.`); continue; }
      if (file.size === 0) { addRow(file.name, '', ext).fail('Filen er tom.'); continue; }
      queue.push({ file, row: addRow(file.name, fmtBytes(file.size) + ' · venter', ext) });
    }
    processQueue();
  }

  // ----- Files tab
  const input = h('input', { type: 'file', multiple: true, class: 'hidden', accept: [...DOC, ...IMG].map(e => '.' + e).join(','), onchange: () => { addFiles([...input.files]); input.value = ''; } });
  const dz = h('div.dropzone', {
    tabindex: 0, role: 'button',
    onclick: () => input.click(),
    onkeydown: e => (e.key === 'Enter' || e.key === ' ') && (e.preventDefault(), input.click()),
    ondragover: e => { e.preventDefault(); dz.classList.add('over'); },
    ondragleave: () => dz.classList.remove('over'),
    ondrop: e => { e.preventDefault(); dz.classList.remove('over'); addFiles([...e.dataTransfer.files]); },
  },
  h('div.ic', icon('upload')),
  h('div', { style: { fontWeight: 600 } }, 'Træk filer hertil, eller klik for at vælge'),
  h('div.muted.small', { style: { marginTop: '4px' } }, `Du kan tilføje flere filer på én gang · op til ${fmtBytes(limits.max_bytes)} pr. fil`),
  h('div.file-types', ['PDF', 'Word', 'PowerPoint', 'Excel', 'ZIP', 'Billeder', 'TXT', 'Markdown'].map(t => h('span.badge', t))),
  input);

  // ----- Link tab
  const urlInput = h('input.input', { placeholder: 'https://www.youtube.com/watch?v=…  eller  https://eksempel.dk/artikel', type: 'url' });
  const urlForm = h('form', { onsubmit: async e => {
    e.preventDefault();
    const url = urlInput.value.trim();
    if (!url) return;
    const isYt = /youtu\.?be/.test(url);
    const row = addRow(url, isYt ? 'YouTube-video' : 'Webside', '', isYt ? 'youtube' : 'url');
    await withBusy(urlForm.querySelector('button'), async () => {
      try {
        const r = await post('sources.url', { subject_id: await getSubjectId(), url });
        row.done();
        row.status(isYt ? 'Transskriptionen hentes under analysen' : 'Siden læses under analysen');
        urlInput.value = '';
        successCount++;
        opts.onAdded?.(r.source);
        idle();
      } catch (ex) { row.fail(ex.message); }
    });
  } },
  h('div.row', urlInput, h('button.btn.primary', { type: 'submit' }, icon('plus'), 'Tilføj link')),
  h('div.stack.sm', { style: { marginTop: '14px' } },
    h('div.notice.info', icon('youtube'), h('div', h('b', 'YouTube: '), 'Vi bruger videoens undertekster eller transskription. Videoer uden transskription kan ikke læses – indsæt i stedet en transskription som tekst.')),
    h('div.notice.info', icon('link'), h('div', h('b', 'Websider: '), 'Den læsbare artikeltekst bliver hentet. Sider bag login eller betalingsmur, eller som blokerer automatisk adgang, bliver ikke omgået.'))));

  // ----- Paste tab
  const titleInput = h('input.input', { placeholder: 'Titel (valgfri)', maxlength: 150 });
  const textInput = h('textarea.textarea', { placeholder: 'Indsæt noter, et kapitel, en transskription…', rows: 8 });
  const textForm = h('form', { onsubmit: async e => {
    e.preventDefault();
    const text = textInput.value.trim();
    if (text.length < 20) return toast('Indsæt mindst et par sætninger.', 'error');
    const row = addRow(titleInput.value || 'Indsat tekst', `${text.length.toLocaleString('da-DK')} tegn`, '', 'text');
    await withBusy(textForm.querySelector('button'), async () => {
      try {
        const r = await post('sources.text', { subject_id: await getSubjectId(), title: titleInput.value, text });
        row.done();
        textInput.value = '';
        titleInput.value = '';
        successCount++;
        opts.onAdded?.(r.source);
        idle();
      } catch (ex) { row.fail(ex.message); }
    });
  } },
  h('div.field', titleInput), h('div.field', textInput), h('button.btn.primary', { type: 'submit' }, icon('plus'), 'Tilføj tekst'));

  const panes = { files: dz, link: urlForm, text: textForm };
  const body = h('div', dz);
  const seg = h('div.seg', { style: { marginBottom: '14px' } },
    [['files', 'Filer'], ['link', 'Link eller YouTube'], ['text', 'Indsæt tekst']].map(([k, label], i) =>
      h('button' + (i === 0 ? '.on' : ''), { type: 'button', onclick: e => { seg.querySelectorAll('button').forEach(b => b.classList.remove('on')); e.currentTarget.classList.add('on'); clear(body, panes[k]); } }, label)));

  return h('div', seg, body, listCard);
}
