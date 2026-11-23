// Shared UI + API helpers for StudyAI (no framework, no build step).

export const BOOT = window.__BOOT__ || {};
export const state = { user: BOOT.user, csrf: BOOT.csrf, subjects: [], aiStatus: null };

// ------------------------------------------------------------------ device id (for bans – never the IP)

function store(key, value) {
  try { if (value === undefined) return localStorage.getItem(key); localStorage.setItem(key, value); } catch { /* blocked storage */ }
  return value;
}
// The server's cookie id and this stored copy restore each other; the stored one wins if both exist.
const deviceId = (() => {
  const saved = store('sp_did');
  if (saved && /^[a-f0-9]{32}$/.test(saved)) return saved;
  if (BOOT.device) store('sp_did', BOOT.device);
  return BOOT.device || '';
})();

function hash53(str, seed = 0) {
  let h1 = 0xdeadbeef ^ seed, h2 = 0x41c6ce57 ^ seed;
  for (let i = 0; i < str.length; i++) { const ch = str.charCodeAt(i); h1 = Math.imul(h1 ^ ch, 2654435761); h2 = Math.imul(h2 ^ ch, 1597334677); }
  h1 = Math.imul(h1 ^ (h1 >>> 16), 2246822507) ^ Math.imul(h2 ^ (h2 >>> 13), 3266489909);
  h2 = Math.imul(h2 ^ (h2 >>> 16), 2246822507) ^ Math.imul(h1 ^ (h1 >>> 13), 3266489909);
  return (h2 >>> 0).toString(16).padStart(8, '0') + (h1 >>> 0).toString(16).padStart(8, '0');
}
/** Browser fingerprint: graphics card, canvas rendering, screen, time zone, languages, CPU/memory. */
const deviceFp = (() => {
  try {
    const c = document.createElement('canvas'); c.width = 240; c.height = 60;
    const x = c.getContext('2d');
    x.textBaseline = 'top'; x.font = "16px 'Arial'"; x.fillStyle = '#f60'; x.fillRect(100, 1, 62, 20);
    x.fillStyle = '#069'; x.fillText('Studiepilot ✓ 123 æøå', 2, 15); x.fillStyle = 'rgba(102,204,0,0.7)'; x.fillText('Studiepilot ✓ 123 æøå', 4, 17);
    let gl = '';
    try { const g = document.createElement('canvas').getContext('webgl'); const d = g && g.getExtension('WEBGL_debug_renderer_info'); gl = d ? g.getParameter(d.UNMASKED_RENDERER_WEBGL) + '|' + g.getParameter(d.UNMASKED_VENDOR_WEBGL) : ''; } catch { /* no webgl */ }
    const parts = [navigator.platform, (navigator.languages || [navigator.language]).join(','), `${screen.width}x${screen.height}x${screen.colorDepth}`, devicePixelRatio,
      Intl.DateTimeFormat().resolvedOptions().timeZone, navigator.hardwareConcurrency, navigator.deviceMemory, gl, c.toDataURL()];
    const s = parts.join('##');
    return hash53(s, 1) + hash53(s, 2);
  } catch { return ''; }
})();
export function deviceHeaders() {
  return { 'X-Device-Id': deviceId, 'X-Device-Fp': deviceFp };
}

/** Full-screen "you are blocked" page (account + device ban). */
export function showBlocked(message) {
  state.user = null;
  state.blocked = message || true;
  const root = document.getElementById('app') || document.body;
  root.replaceChildren(h('div.blocked-screen',
    h('div.blocked-card',
      h('div.blocked-icon', icon('lock')),
      h('h1', 'Du er blokeret'),
      h('p', message || 'Din adgang til siden er blokeret.'),
      h('p.small.faint', 'Blokeringen gælder din konto og denne enhed.'))));
}

// ------------------------------------------------------------------ DOM

/** h('div.card.pad', {onclick}, children...) */
export function h(tag, attrs, ...children) {
  if (attrs == null || typeof attrs !== 'object' || attrs instanceof Node || Array.isArray(attrs)) {
    if (attrs !== undefined && attrs !== null) children.unshift(attrs);
    attrs = {};
  }
  const [name, ...classes] = tag.split('.');
  const el = name === 'svg' ? document.createElementNS('http://www.w3.org/2000/svg', 'svg') : document.createElement(name || 'div');
  if (classes.length) el.classList.add(...classes.filter(Boolean));
  for (const [k, v] of Object.entries(attrs)) {
    if (v === undefined || v === null || v === false) continue;
    if (k.startsWith('on') && typeof v === 'function') el.addEventListener(k.slice(2).toLowerCase(), v);
    else if (k === 'class') String(v).split(' ').filter(Boolean).forEach(c => el.classList.add(c));
    else if (k === 'style' && typeof v === 'object') Object.assign(el.style, v);
    else if (k === 'html') el.innerHTML = v;
    else if (k === 'value') el.value = v;
    else if (k === 'checked') el.checked = !!v;
    else if (k === 'ref' && typeof v === 'function') v(el);
    else el.setAttribute(k, v === true ? '' : v);
  }
  append(el, children);
  return el;
}

function append(el, children) {
  for (const c of children.flat(Infinity)) {
    if (c === null || c === undefined || c === false) continue;
    el.appendChild(c instanceof Node ? c : document.createTextNode(String(c)));
  }
}

export function clear(el, ...children) {
  el.replaceChildren();
  append(el, children);
  return el;
}

export const $ = (sel, root = document) => root.querySelector(sel);

// ---------------------------------------------------------------- icons

const ICONS = {
  home: '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h14V9.5"/><path d="M10 20v-6h4v6"/>',
  book: '<path d="M4 19.5V5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2.5z"/><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H19"/>',
  layers: '<path d="m12 3 9 5-9 5-9-5 9-5z"/><path d="m3 13 9 5 9-5"/>',
  plus: '<path d="M12 5v14M5 12h14"/>',
  brain: '<path d="M9 4a3 3 0 0 0-3 3v.2A3 3 0 0 0 4 10a3 3 0 0 0 1 2.2A3 3 0 0 0 6 17a3 3 0 0 0 3 3V4z"/><path d="M15 4a3 3 0 0 1 3 3v.2A3 3 0 0 1 20 10a3 3 0 0 1-1 2.2A3 3 0 0 1 18 17a3 3 0 0 1-3 3V4z"/><path d="M9 4h6M9 20h6"/>',
  search: '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>',
  settings: '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
  menu: '<path d="M4 6h16M4 12h16M4 18h16"/>',
  x: '<path d="M18 6 6 18M6 6l12 12"/>',
  check: '<path d="M20 6 9 17l-5-5"/>',
  checkCircle: '<circle cx="12" cy="12" r="9"/><path d="m8.5 12 2.5 2.5 4.5-5"/>',
  xCircle: '<circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/>',
  alert: '<path d="M12 3 2 20h20L12 3z"/><path d="M12 10v4M12 17h.01"/>',
  info: '<circle cx="12" cy="12" r="9"/><path d="M12 11v5M12 8h.01"/>',
  upload: '<path d="M12 16V4M7 9l5-5 5 5"/><path d="M4 16v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
  file: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6z"/><path d="M14 3v6h6"/>',
  link: '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
  youtube: '<rect x="2.5" y="5" width="19" height="14" rx="4"/><path d="m10 9 5 3-5 3V9z"/>',
  text: '<path d="M4 6h16M4 12h16M4 18h10"/>',
  image: '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
  zip: '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9l-6-6z"/><path d="M10 6h1M10 9h1M10 12h1M10 15v2h2v-2z"/>',
  quiz: '<circle cx="12" cy="12" r="9"/><path d="M9.5 9a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.6M12 17h.01"/>',
  cards: '<rect x="3" y="6" width="14" height="14" rx="2"/><path d="M7 3h12a2 2 0 0 1 2 2v12"/>',
  exam: '<path d="M9 4h6a1 1 0 0 1 1 1v1H8V5a1 1 0 0 1 1-1z"/><path d="M16 5h2a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/>',
  chat: '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
  sparkles: '<path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1"/>',
  wand: '<path d="m15 4 5 5L9 20H4v-5L15 4z"/><path d="m12.5 6.5 5 5"/>',
  graduation: '<path d="m2 9 10-5 10 5-10 5L2 9z"/><path d="M6 11v5c3.5 2.5 8.5 2.5 12 0v-5"/><path d="M22 9v6"/>',
  target: '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
  clock: '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
  flame: '<path d="M12 22c4 0 7-3 7-7 0-5-5-7-5-12-3 2-5 5-5 8-1-1-2-2-2-4-1 2-2 4-2 7 0 5 3 8 7 8z"/>',
  trophy: '<path d="M8 21h8M12 17v4M7 4h10v5a5 5 0 0 1-10 0V4z"/><path d="M17 5h3v2a4 4 0 0 1-4 4M7 5H4v2a4 4 0 0 0 4 4"/>',
  chart: '<path d="M4 20V10M10 20V4M16 20v-8M22 20H2"/>',
  calendar: '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>',
  edit: '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
  trash: '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
  more: '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
  download: '<path d="M12 4v12M7 11l5 5 5-5"/><path d="M4 20h16"/>',
  refresh: '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
  arrowRight: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  arrowLeft: '<path d="M19 12H5M11 6l-6 6 6 6"/>',
  chevronRight: '<path d="m9 6 6 6-6 6"/>',
  chevronDown: '<path d="m6 9 6 6 6-6"/>',
  flag: '<path d="M5 21V4h11l-1.5 4L16 12H5"/>',
  eye: '<path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"/><circle cx="12" cy="12" r="3"/>',
  logout: '<path d="M15 4h3a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-3"/><path d="M10 17l-5-5 5-5M5 12h11"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
  moon: '<path d="M21 12.8A9 9 0 1 1 11.2 3 7 7 0 0 0 21 12.8z"/>',
  key: '<circle cx="8" cy="15" r="4"/><path d="m10.8 12.2 8.2-8.2M17 6l2 2M15 8l2 2"/>',
  bulb: '<path d="M9 18h6M10 21h4"/><path d="M12 3a6 6 0 0 0-4 10.5c.8.8 1 1.5 1 2.5h6c0-1 .2-1.7 1-2.5A6 6 0 0 0 12 3z"/>',
  highlight: '<path d="m9 11-6 6v3h9l3-3"/><path d="m22 12-4.6 4.6a2 2 0 0 1-2.8 0l-5.2-5.2a2 2 0 0 1 0-2.8L14 4"/>',
  shrink: '<path d="M4 14h6v6M20 10h-6V4M14 10l7-7M3 21l7-7"/>',
  expand: '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
  print: '<path d="M6 9V3h12v6"/><rect x="3" y="9" width="18" height="8" rx="2"/><path d="M6 14h12v7H6z"/>',
  play: '<path d="M7 4v16l13-8L7 4z"/>',
  send: '<path d="M22 2 11 13"/><path d="M22 2 15 22l-4-9-9-4 20-7z"/>',
  repeat: '<path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/>',
  shuffle: '<path d="M16 3h5v5M4 20 21 3M21 16v5h-5M15 15l6 6M4 4l5 5"/>',
  folder: '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/>',
  zap: '<path d="M13 2 3 14h9l-1 8 10-12h-9l1-8z"/>',
  lock: '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
  user: '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
  logo: '<path d="m2.5 9 9.5-5 9.5 5-9.5 5-9.5-5z"/><path d="M6 11v5c3.5 2.5 8.5 2.5 12 0v-5"/>',
};

export function icon(name, cls = '') {
  const el = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  el.setAttribute('viewBox', '0 0 24 24');
  el.setAttribute('fill', 'none');
  el.setAttribute('stroke', 'currentColor');
  el.setAttribute('stroke-width', '1.8');
  el.setAttribute('stroke-linecap', 'round');
  el.setAttribute('stroke-linejoin', 'round');
  el.setAttribute('aria-hidden', 'true');
  if (cls) el.setAttribute('class', cls);
  el.innerHTML = ICONS[name] || ICONS.info;
  return el;
}

// ------------------------------------------------------------------ API

export class ApiError extends Error {
  constructor(message, status, code) { super(message); this.status = status; this.code = code; }
}

export async function api(route, data, opts = {}) {
  const method = opts.method || 'GET';
  let url = 'api.php?r=' + encodeURIComponent(route);
  const init = { method, headers: { 'X-CSRF-Token': state.csrf, Accept: 'application/json', ...deviceHeaders() }, credentials: 'same-origin' };
  if (method === 'GET' && data) {
    for (const [k, v] of Object.entries(data)) {
      if (Array.isArray(v)) v.forEach(x => (url += `&${encodeURIComponent(k)}[]=${encodeURIComponent(x)}`));
      else if (v !== undefined && v !== null) url += `&${encodeURIComponent(k)}=${encodeURIComponent(v)}`;
    }
  } else if (data instanceof FormData) {
    init.body = data;
  } else if (data) {
    init.headers['Content-Type'] = 'application/json';
    init.body = JSON.stringify(data);
  }
  let res;
  try {
    res = await fetch(url, init);
  } catch {
    throw new ApiError('Kan ikke få forbindelse til serveren. Tjek at den kører, og at din internetforbindelse virker.', 0);
  }
  let body = null;
  try { body = await res.json(); } catch { /* non-JSON */ }
  if (!res.ok) {
    if (body?.code === 'banned') { showBlocked(body.error); throw new ApiError(body.error, res.status, 'banned'); }
    if (res.status === 401 && route !== 'auth.login' && !opts.noRedirect) {
      state.user = null;
      location.hash = '#/login';
    }
    throw new ApiError(body?.error || `Forespørgslen mislykkedes (HTTP ${res.status}).`, res.status, body?.code);
  }
  return body;
}

export const get = (route, params) => api(route, params, { method: 'GET' });
export const post = (route, data) => api(route, data || {}, { method: 'POST' });

/** Upload with progress (fetch has no upload progress). */
export function upload(route, formData, onProgress) {
  return new Promise((resolve, reject) => {
    const xhr = new XMLHttpRequest();
    xhr.open('POST', 'api.php?r=' + route);
    xhr.setRequestHeader('X-CSRF-Token', state.csrf);
    for (const [k, v] of Object.entries(deviceHeaders())) xhr.setRequestHeader(k, v);
    xhr.upload.onprogress = e => e.lengthComputable && onProgress?.(e.loaded / e.total);
    xhr.onload = () => {
      let body = null;
      try { body = JSON.parse(xhr.responseText); } catch { /* ignore */ }
      if (xhr.status >= 200 && xhr.status < 300) resolve(body);
      else if (body?.code === 'banned') { showBlocked(body.error); reject(new ApiError(body.error, xhr.status, 'banned')); }
      else reject(new ApiError(body?.error || (xhr.status === 413 ? 'Filen er større end serveren tillader.' : `Upload mislykkedes (HTTP ${xhr.status}).`), xhr.status));
    };
    xhr.onerror = () => reject(new ApiError('Upload mislykkedes – forbindelsen blev afbrudt.', 0));
    xhr.send(formData);
  });
}

// ---------------------------------------------------------- feedback UI

export function toast(message, type = 'info', ms = 4200) {
  const el = h('div.toast.' + type, icon(type === 'success' ? 'checkCircle' : type === 'error' ? 'alert' : 'info'), h('div', message));
  document.getElementById('toasts').appendChild(el);
  setTimeout(() => { el.style.opacity = '0'; el.style.transition = 'opacity .3s'; setTimeout(() => el.remove(), 300); }, ms);
}

export function showError(e) {
  console.error(e);
  toast(e?.message || 'Noget gik galt.', 'error', 6500);
}

export function modal({ title, body, footer, size = '', onClose } = {}) {
  const close = () => { back.remove(); document.removeEventListener('keydown', onKey); onClose?.(); };
  const onKey = e => e.key === 'Escape' && close();
  const back = h('div.modal-back', { onmousedown: e => e.target === back && close() },
    h('div.modal' + (size ? '.' + size : ''), { role: 'dialog', 'aria-modal': 'true' },
      h('div.modal-head', h('h2', title), h('button.btn.ghost.icon.sm', { onclick: close, 'aria-label': 'Luk' }, icon('x'))),
      h('div.modal-body', body),
      footer ? h('div.modal-foot', footer) : null));
  document.body.appendChild(back);
  document.addEventListener('keydown', onKey);
  setTimeout(() => back.querySelector('input,textarea,select')?.focus(), 30);
  return { close, el: back };
}

export function confirmDialog({ title, message, confirm = 'Bekræft', danger = false }) {
  return new Promise(resolve => {
    let done = false;
    const finish = v => { if (!done) { done = true; m.close(); resolve(v); } };
    const m = modal({
      title,
      body: h('p.muted', message),
      footer: [
        h('button.btn', { onclick: () => finish(false) }, 'Annuller'),
        h('button.btn' + (danger ? '.danger.solid' : '.primary'), { onclick: () => finish(true) }, confirm),
      ],
      onClose: () => { if (!done) { done = true; resolve(false); } },
    });
  });
}

export function promptDialog({ title, label, value = '', placeholder = '', confirm = 'Gem', multiline = false }) {
  return new Promise(resolve => {
    let done = false;
    const input = multiline
      ? h('textarea.textarea', { value, placeholder, rows: 6 })
      : h('input.input', { value, placeholder, onkeydown: e => e.key === 'Enter' && finish(input.value) });
    const finish = v => { if (!done) { done = true; m.close(); resolve(v); } };
    const m = modal({
      title,
      body: h('div.field', label ? h('label', label) : null, input),
      footer: [h('button.btn', { onclick: () => finish(null) }, 'Annuller'), h('button.btn.primary', { onclick: () => finish(input.value) }, confirm)],
      onClose: () => { if (!done) { done = true; resolve(null); } },
    });
  });
}

/** Dropdown menu anchored to a button. items: [{label, icon, onClick, danger} | 'sep'] */
export function menu(anchor, items) {
  document.querySelectorAll('.menu').forEach(m => m.remove());
  const r = anchor.getBoundingClientRect();
  const el = h('div.menu', items.map(it => it === 'sep' ? h('div.sep')
    : h('button' + (it.danger ? '.danger' : ''), { onclick: () => { el.remove(); it.onClick(); } }, it.icon ? icon(it.icon) : null, it.label)));
  document.body.appendChild(el);
  const w = el.offsetWidth, hgt = el.offsetHeight;
  el.style.left = Math.max(8, Math.min(r.right - w, innerWidth - w - 8)) + 'px';
  el.style.top = (r.bottom + 6 + hgt > innerHeight ? Math.max(8, r.top - hgt - 6) : r.bottom + 6) + 'px';
  const off = e => { if (!el.contains(e.target) && e.target !== anchor) { el.remove(); document.removeEventListener('mousedown', off); } };
  setTimeout(() => document.addEventListener('mousedown', off), 0);
}

export async function withBusy(btn, fn) {
  const orig = [...btn.childNodes];
  btn.disabled = true;
  clear(btn, h('span.spinner'), btn.dataset.busy || orig.filter(n => n.nodeType === 3).map(n => n.textContent).join('').trim() || '');
  try { return await fn(); } finally { btn.disabled = false; clear(btn, ...orig); }
}

// ------------------------------------------------------------ markdown

let refStore = {};
export function addRefs(refs) { Object.assign(refStore, refs || {}); }

function escapeHtml(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
export { escapeHtml };

function citeLabel(id) {
  const r = refStore[id];
  if (!r) return 'kilde';
  const t = r.source_title.replace(/\.[a-z0-9]{2,5}$/i, '');
  return r.label ? (t.length > 16 ? t.slice(0, 15) + '…' : t) + ' · ' + r.label : (t.length > 22 ? t.slice(0, 21) + '…' : t);
}

/** Markdown → sanitized element; [C12] become clickable source chips, ==x== becomes a highlight. */
export function md(text, cls = 'prose') {
  let src = String(text ?? '');
  // Malformed citations like "[<C125]" would be read as an HTML tag and swallow the next </details>.
  src = src.replace(/[\[【]\s*<?\s*[Cc]\s*(\d+)\s*>?\s*[\]】]/g, '[C$1]').replace(/<\s*[Cc](\d+)\s*>?\]?/g, '[C$1]');
  src = src.replace(/<summary>\s*Show answer\s*<\/summary>/gi, '<summary>Vis svar</summary>');
  src = src.replace(/==([^=\n][^\n]*?)==/g, '<mark>$1</mark>');
  let html = window.marked ? window.marked.parse(src, { breaks: false, gfm: true }) : escapeHtml(src).replace(/\n/g, '<br>');
  html = window.DOMPurify ? window.DOMPurify.sanitize(html, { ADD_TAGS: ['details', 'summary', 'mark'] }) : html;
  html = html.replace(/\[C(\d+)\]/g, (_, id) => `<button type="button" class="cite" data-ref="${id}" title="${escapeHtml(refStore[id] ? refStore[id].source_title + (refStore[id].label ? ' — ' + refStore[id].label : '') : 'Kilde')}">${escapeHtml(citeLabel(id))}</button>`);
  const el = h('div.' + cls, { html });
  el.querySelectorAll('a').forEach(a => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
  return el;
}

/** Inline citation chips for an array of "C12" refs. */
export function refChips(refs) {
  const ids = (refs || []).map(r => String(r).replace(/^C/, '')).filter(Boolean);
  if (!ids.length) return null;
  return h('span.refs', ids.slice(0, 4).map(id => h('button.cite', { type: 'button', 'data-ref': id, title: refStore[id]?.source_title || 'Kilde' }, citeLabel(id))));
}

// Global click handler for citation chips → popover with the source excerpt.
document.addEventListener('click', e => {
  const btn = e.target.closest?.('.cite');
  document.querySelectorAll('.popover').forEach(p => p.remove());
  if (!btn) return;
  e.preventDefault();
  const r = refStore[btn.dataset.ref];
  const pop = h('div.popover',
    r ? [
      h('div.src', icon('file'), h('span.truncate', r.source_title), r.label ? h('span.faint', '· ' + r.label) : null),
      h('blockquote', r.excerpt),
      h('div.row', { style: { marginTop: '10px' } }, h('button.btn.sm', { onclick: () => openSourceViewer(r.source_id, r.id) }, icon('eye'), 'Åbn i kilden')),
    ] : h('div.muted', 'Denne kilde findes ikke længere (den er måske slettet).'));
  document.body.appendChild(pop);
  const b = btn.getBoundingClientRect();
  const w = pop.offsetWidth, ph = pop.offsetHeight;
  pop.style.left = Math.max(12, Math.min(b.left, innerWidth - w - 12)) + 'px';
  pop.style.top = (b.bottom + 8 + ph > innerHeight ? Math.max(12, b.top - ph - 8) : b.bottom + 8) + 'px';
  pop.addEventListener('click', ev => ev.stopPropagation());
});

export async function openSourceViewer(sourceId, chunkId) {
  document.querySelectorAll('.popover').forEach(p => p.remove());
  const body = h('div', h('div.loading-block', h('div.spinner')));
  modal({ title: 'Kilde', body, size: 'lg' });
  try {
    const d = await get('sources.get', { id: sourceId });
    const target = [];
    clear(body,
      h('div.row', { style: { marginBottom: '12px' } }, sourceIcon(d.source), h('div', h('div', { style: { fontWeight: 600 } }, d.source.title), h('div.tiny.faint', kindLabel(d.source)))),
      d.source.url ? h('p.small', { style: { marginBottom: '12px' } }, h('a', { href: d.source.url, target: '_blank', rel: 'noopener noreferrer' }, d.source.url)) : null,
      d.chunks.length ? d.chunks.map(c => {
        const el = h('div.chunk-view' + (String(c.id) === String(chunkId) ? '.hl' : ''), c.label ? h('div.tiny.faint', { style: { marginBottom: '4px' } }, c.label) : null, c.content);
        if (String(c.id) === String(chunkId)) target.push(el);
        return el;
      }) : h('p.muted', 'Ingen udtrukket tekst endnu.'));
    target[0]?.scrollIntoView({ block: 'center' });
  } catch (e) { clear(body, h('div.form-error', e.message)); }
}

// ------------------------------------------------------------- format

export function fmtDuration(sec) {
  sec = Math.round(sec || 0);
  if (sec < 60) return sec + ' sek';
  const m = Math.floor(sec / 60), hrs = Math.floor(m / 60);
  return hrs ? `${hrs} t ${m % 60} min` : `${m} min`;
}
export function fmtDate(ts) {
  if (!ts) return '—';
  const d = new Date(ts * 1000);
  return d.toLocaleDateString('da-DK', { month: 'short', day: 'numeric', year: d.getFullYear() === new Date().getFullYear() ? undefined : 'numeric' });
}
export function timeAgo(ts) {
  if (!ts) return 'aldrig';
  const s = Math.floor(Date.now() / 1000 - ts);
  if (s < 60) return 'lige nu';
  if (s < 3600) return 'for ' + Math.floor(s / 60) + ' min. siden';
  if (s < 86400) return 'for ' + Math.floor(s / 3600) + ' t. siden';
  if (s < 86400 * 7) return 'for ' + Math.floor(s / 86400) + ' d. siden';
  return fmtDate(ts);
}
export function fmtBytes(b) {
  if (b < 1024) return b + ' B';
  if (b < 1048576) return (b / 1024).toFixed(0) + ' KB';
  return (b / 1048576).toFixed(1) + ' MB';
}
export function pct(x) { return Math.round((x || 0) * 100); }
export function plural(n, word, pluralWord) { return `${n} ${n === 1 ? word : (pluralWord || word + 'er')}`; }

export function progressBar(value, cls = '') {
  return h('div.progress' + (cls ? '.' + cls : ''), h('i', { style: { width: Math.max(0, Math.min(100, value)) + '%' } }));
}
export function masteryClass(m) { return m >= 0.75 ? 'success' : m >= 0.45 ? '' : m > 0 ? 'warning' : ''; }

export function kindLabel(s) {
  if (s.kind === 'youtube') return 'YouTube-transskription';
  if (s.kind === 'url') return 'Webside';
  if (s.kind === 'text') return 'Indsat tekst';
  return (s.ext || 'file').toUpperCase() + (s.size_bytes ? ' · ' + fmtBytes(s.size_bytes) : '');
}
export function sourceIcon(s) {
  const ext = (s.ext || '').toLowerCase();
  if (s.kind === 'youtube') return h('div.file-ic.youtube', icon('youtube'));
  if (s.kind === 'url') return h('div.file-ic.url', icon('link'));
  if (s.kind === 'text') return h('div.file-ic', icon('text'));
  if (['png', 'jpg', 'jpeg', 'webp', 'gif'].includes(ext)) return h('div.file-ic.img', icon('image'));
  if (ext === 'zip') return h('div.file-ic.zip', icon('zip'));
  return h('div.file-ic.' + ext, ext ? ext.slice(0, 4).toUpperCase() : icon('file'));
}

export function emptyState({ icon: ic = 'sparkles', title, text, action }) {
  return h('div.empty', h('div.icon-wrap', icon(ic)), h('h3', title), text ? h('p', text) : null, action || null);
}

export function loadingBlock(text = 'Indlæser…', sub = '') {
  return h('div.loading-block', h('div.spinner.lg'), h('div', text), sub ? h('div.steps', sub) : null);
}

export function skeleton(lines = 3) {
  return h('div.stack.sm', Array.from({ length: lines }, (_, i) => h('div.skeleton', { style: { height: i === 0 ? '22px' : '14px', width: i === 0 ? '40%' : (90 - i * 12) + '%' } })));
}

export function debounce(fn, ms) {
  let t;
  return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
}

export function initials(name) {
  return (name || '?').split(/\s+/).map(p => p[0]).join('').slice(0, 2).toUpperCase();
}

export function subjectIcon(s, size = 40) {
  return h('div.subject-icon', { style: { background: s.color || '#6d7cff', width: size + 'px', height: size + 'px', fontSize: Math.round(size * 0.4) + 'px', borderRadius: Math.round(size * 0.28) + 'px' } }, initials(s.name).slice(0, 1));
}

/** Sends study-time heartbeats while a study view is visible. */
let beat = null;
export function trackTime(activity, subjectId, topicId) {
  stopTracking();
  let last = Date.now();
  let active = Date.now();
  const mark = () => (active = Date.now());
  ['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(ev => addEventListener(ev, mark, { passive: true }));
  beat = {
    timer: setInterval(() => {
      const now = Date.now();
      const secs = Math.round((now - last) / 1000);
      last = now;
      // Only count time when the tab is visible and the user was active in the last 2 minutes.
      if (document.visibilityState === 'visible' && now - active < 120000 && secs > 0) {
        post('progress.heartbeat', { activity, subject_id: subjectId || 0, topic_id: topicId || 0, seconds: Math.min(secs, 60) }).catch(() => {});
      }
    }, 30000),
    cleanup: () => ['mousemove', 'keydown', 'scroll', 'touchstart'].forEach(ev => removeEventListener(ev, mark)),
  };
}
export function stopTracking() {
  if (beat) { clearInterval(beat.timer); beat.cleanup(); beat = null; }
}

/** <select> of AI output languages. defaultLabel adds a first "use default" option (value ''). */
export function languageSelect(value, { defaultLabel = null, style = {} } = {}) {
  const langs = BOOT.languages || { auto: 'Samme som mit materiale', da: 'Dansk', en: 'Engelsk' };
  return h('select.select', { style },
    defaultLabel ? h('option', { value: '', selected: !value }, defaultLabel) : null,
    Object.entries(langs).map(([code, label]) => h('option', { value: code, selected: value === code }, label)));
}

/**
 * POSTs to a streaming (Server-Sent Events) endpoint. Calls onEvent(event, data) for every event
 * and resolves with the data of the final "done" event; rejects on an "error" event.
 */
export async function streamPost(route, data, onEvent) {
  let res;
  try {
    res = await fetch('api.php?r=' + encodeURIComponent(route), {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf, Accept: 'text/event-stream', ...deviceHeaders() },
      body: JSON.stringify(data),
    });
  } catch {
    throw new ApiError('Kan ikke få forbindelse til serveren. Tjek at den kører, og at din internetforbindelse virker.', 0);
  }
  if (!res.ok || !(res.headers.get('content-type') || '').includes('event-stream')) {
    let body = null;
    try { body = await res.json(); } catch { /* not JSON */ }
    if (body?.code === 'banned') showBlocked(body.error);
    else if (res.status === 401) { state.user = null; location.hash = '#/login'; }
    throw new ApiError(body?.error || `Forespørgslen mislykkedes (HTTP ${res.status}).`, res.status, body?.code);
  }
  const reader = res.body.getReader();
  const decoder = new TextDecoder();
  let buf = '';
  let result = null;
  while (true) {
    const { value, done } = await reader.read();
    if (done) break;
    buf += decoder.decode(value, { stream: true });
    let idx;
    while ((idx = buf.indexOf('\n\n')) !== -1) {
      const block = buf.slice(0, idx);
      buf = buf.slice(idx + 2);
      let event = 'message';
      let payload = '';
      for (const line of block.split('\n')) {
        if (line.startsWith('event:')) event = line.slice(6).trim();
        else if (line.startsWith('data:')) payload += line.slice(5).trim();
      }
      let parsed = {};
      try { parsed = payload ? JSON.parse(payload) : {}; } catch { continue; }
      if (event === 'error') {
        if (parsed.code === 'banned') showBlocked(parsed.message);
        throw new ApiError(parsed.message || 'Der gik noget galt.', 500, parsed.code);
      }
      if (event === 'done') result = parsed;
      onEvent?.(event, parsed);
    }
  }
  if (!result) throw new ApiError('Forbindelsen blev afbrudt, før svaret var færdigt. Prøv igen.', 0);
  return result;
}
