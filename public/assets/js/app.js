import { h, clear, icon, state, get, post, BOOT, initials, stopTracking, showError, menu, showBlocked } from './lib.js';
import { authView } from './views/auth.js';
import { dashboardView } from './views/dashboard.js';
import { subjectsView, newSubjectView } from './views/subjects.js';
import { workspaceView } from './views/workspace.js';
import { memoryView } from './views/memory.js';
import { searchView } from './views/search.js';
import { settingsView } from './views/settings.js';
import { onboardingView } from './views/onboarding.js';
import { updatesView } from './views/updates.js';
import { shareView } from './views/share.js';
import { adminView } from './views/admin.js';
import { essayView } from './views/essay.js';

// ------------------------------------------------------------ theme

export function applyTheme(pref) {
  const root = document.documentElement;
  root.dataset.pref = pref;
  const light = pref === 'light' || (pref === 'system' && matchMedia('(prefers-color-scheme: light)').matches);
  root.dataset.theme = light ? 'light' : 'dark';
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', light ? '#f6f7fb' : '#07090e');
}
matchMedia('(prefers-color-scheme: light)').addEventListener('change', () => applyTheme(document.documentElement.dataset.pref || 'dark'));

export async function setTheme(pref) {
  applyTheme(pref);
  if (state.user) {
    state.user.prefs = { ...(state.user.prefs || {}), theme: pref };
    post('auth.profile', { prefs: { theme: pref } }).catch(() => {});
  }
}

// ------------------------------------------------------- background jobs

const jobListeners = new Set();
const runningJobs = new Map(); // jobId -> latest job state
export function onJob(fn) { jobListeners.add(fn); return () => jobListeners.delete(fn); }
function emitJob(job, extra) { jobListeners.forEach(fn => { try { fn(job, extra); } catch (e) { console.error(e); } }); renderJobIndicator(); }

/** Drives a background job by calling jobs.run until it finishes (each call does one step on the server). */
export async function watchJob(job) {
  if (!job || runningJobs.has(job.id)) return;
  runningJobs.set(job.id, job);
  emitJob(job);
  const id = job.id;
  let failures = 0;
  while (true) {
    try {
      const startedAt = Date.now();
      const r = await post('jobs.run', { id });
      failures = 0;
      if (!r || !r.job) { job = { ...job, status: 'gone' }; break; }
      job = r.job;
      runningJobs.set(id, job);
      emitJob(job, r);
      if (!['pending', 'running'].includes(job.status)) break;
      // A background worker (or another tab) is doing the work: just check again shortly.
      if (Date.now() - startedAt < 400) await new Promise(res => setTimeout(res, 1500));
    } catch (e) {
      // Job/subject deleted, or signed out: stop following it quietly.
      if (e.status === 401 || e.status === 404) { job = { ...job, status: 'gone' }; break; }
      if (++failures >= 5) { showError(e); break; }
      await new Promise(res => setTimeout(res, 3000));
    }
  }
  runningJobs.delete(id);
  emitJob(job);
  if (job.subject_id) refreshSubjects();
}
export function jobFor(subjectId) {
  for (const j of runningJobs.values()) if (j.subject_id === subjectId) return j;
  return null;
}

function renderJobIndicator() {
  const el = document.getElementById('job-indicator');
  if (!el) return;
  const jobs = [...runningJobs.values()].filter(j => ['pending', 'running'].includes(j.status));
  if (!jobs.length) { el.replaceChildren(); el.className = ''; return; }
  const j = jobs[0];
  const subj = state.subjects.find(s => s.id === j.subject_id);
  el.className = 'job-indicator';
  clear(el,
    h('div.row', { style: { gap: '8px', marginBottom: '7px' } }, h('span.spinner', { style: { width: '13px', height: '13px' } }),
      h('span.truncate', { style: { fontWeight: 550 } }, subj ? subj.name : 'Behandler'), h('span.faint', { style: { marginLeft: 'auto' } }, j.progress + '%')),
    h('div.progress', h('i', { style: { width: j.progress + '%' } })),
    h('div.tiny.faint.truncate', { style: { marginTop: '6px' } }, j.message || 'Arbejder…'));
}

// --------------------------------------------------------------- subjects

export async function refreshSubjects() {
  try {
    const r = await get('subjects.list');
    state.subjects = r.subjects;
    renderSidebarSubjects();
    for (const s of r.subjects) if (s.job && !runningJobs.has(s.job.id)) watchJob({ ...s.job, id: +s.job.id, subject_id: s.id });
  } catch (e) { if (e.status !== 401) console.error(e); }
  return state.subjects;
}

// -------------------------------------------------------- AI usage meter

let meterTimer = null;
export async function refreshUsage() {
  const el = document.getElementById('ai-meter');
  if (!el || !state.user) return;
  let u;
  try { u = await get('usage'); } catch { return; }
  const reset = new Date(u.reset_at * 1000).toLocaleTimeString('da-DK', { hour: '2-digit', minute: '2-digit' });
  if (u.limit === null) {
    // Paid account without a known daily cap: just show what was used.
    clear(el, h('div.job-indicator', h('div.row.between.tiny', h('span', { style: { fontWeight: 550 } }, 'AI i dag'), h('span.faint', `${u.used} forespørgsler`))));
    return;
  }
  const left = Math.max(0, u.remaining ?? 0);
  const pctLeft = Math.round((left / Math.max(1, u.limit)) * 100);
  const cls = left === 0 ? 'danger' : pctLeft < 25 ? 'warning' : 'success';
  clear(el, h('div.job-indicator', { title: `Dagens AI-kvote nulstilles kl. ${reset}. Siden har brugt ${u.used} i dag; du har brugt ${u.mine}.` },
    h('div.row.between.tiny', { style: { marginBottom: '6px' } },
      h('span', { style: { fontWeight: 550 } }, icon('zap'), ' AI i dag'),
      h('span', { style: { color: left === 0 ? 'var(--danger)' : 'var(--text-2)' } }, `${left} af ${u.limit} tilbage`)),
    h('div.progress.' + cls, h('i', { style: { width: pctLeft + '%' } })),
    h('div.tiny.faint', { style: { marginTop: '6px' } }, left === 0 ? `Brugt op – nulstilles kl. ${reset}` : `Nulstilles kl. ${reset}`),
    u.my_limit ? h('div.tiny.faint', `Din grænse: ${u.mine} af ${u.my_limit} brugt`) : null));
}

// ----------------------------------------------------------- update log

function hasNewUpdate() {
  return !!BOOT.version && (state.user?.prefs?.seen_version || '') !== BOOT.version;
}

/** One-time notice per session when a new version is out. */
function announceUpdate() {
  if (!hasNewUpdate() || sessionStorage.getItem('updateNoticeShown') === BOOT.version || (location.hash || '').startsWith('#/updates')) return;
  sessionStorage.setItem('updateNoticeShown', BOOT.version);
  const el = h('div.toast.success', { style: { cursor: 'pointer' }, onclick: () => { el.remove(); location.hash = '#/updates'; } },
    icon('sparkles'),
    h('div', h('b', `Ny opdatering: version ${BOOT.version}`), h('div.small', 'Se hvad der er nyt i Studiepilot →')));
  document.getElementById('toasts').appendChild(el);
  setTimeout(() => el.remove(), 12000);
}

// ----------------------------------------------------------------- layout

const root = document.getElementById('app');
let shell = null;
let currentCleanup = null;

function navItem(href, ic, label, extra) {
  return h('a.nav-item', { href, 'data-nav': href }, icon(ic), h('span', label), extra || null);
}

function buildShell() {
  const u = state.user;
  const sidebar = h('aside.sidebar',
    h('a.brand', { href: '#/', style: { color: 'inherit', textDecoration: 'none' } }, h('span.brand-mark', icon('logo')), BOOT.appName || 'Studiepilot'),
    h('div.nav-scroll',
      h('nav.nav',
        navItem('#/', 'home', 'Oversigt'),
        navItem('#/subjects', 'layers', 'Fag'),
        navItem('#/memory', 'brain', 'Hukommelsestræning'),
        navItem('#/essay', 'edit', 'Opgavefeedback'),
        navItem('#/search', 'search', 'Søg', h('span.count.kbd', 'Ctrl K')),
        navItem('#/updates', 'sparkles', 'Nyheder', hasNewUpdate() ? h('span.badge.success.updates-badge', { style: { marginLeft: 'auto' } }, 'Ny') : null)),
      h('div.nav-label', 'Dine fag', h('a.btn.ghost.icon.sm', { href: '#/new', title: 'Nyt fag', style: { height: '22px', width: '22px' } }, icon('plus'))),
      h('nav.nav', { id: 'side-subjects' })),
    h('div', { id: 'job-indicator' }),
    h('div', { id: 'ai-meter' }),
    h('div.sidebar-foot',
      u.role === 'admin' ? navItem('#/admin', 'user', 'Administration') : null,
      navItem('#/settings', 'settings', 'Indstillinger'),
      h('button.user-chip', { onclick: e => userMenu(e.currentTarget) },
        h('span.avatar', initials(u.name)),
        h('span', { style: { minWidth: 0, flex: 1 } }, h('div.truncate', { style: { fontWeight: 550, fontSize: '13px' } }, u.name), h('div.truncate.tiny.faint', u.email)),
        icon('more'))));
  const content = h('div', { id: 'view' });
  const topbar = h('header.topbar',
    h('button.btn.ghost.icon', { onclick: () => app.classList.toggle('drawer-open'), 'aria-label': 'Menu' }, icon('menu')),
    h('a.brand', { href: '#/', style: { padding: 0, color: 'inherit' } }, h('span.brand-mark', icon('logo')), BOOT.appName || 'Studiepilot'),
    h('div.spacer'),
    h('a.btn.ghost.icon', { href: '#/search', 'aria-label': 'Søg' }, icon('search')));
  const tabbar = h('nav.tabbar',
    h('a', { href: '#/', 'data-nav': '#/' }, icon('home'), 'Hjem'),
    h('a', { href: '#/subjects', 'data-nav': '#/subjects' }, icon('layers'), 'Fag'),
    h('a', { href: '#/new', 'data-nav': '#/new' }, h('span.plus', icon('plus'))),
    h('a', { href: '#/memory', 'data-nav': '#/memory' }, icon('brain'), 'Gentag'),
    h('a', { href: '#/settings', 'data-nav': '#/settings' }, icon('settings'), 'Indstillinger'));
  const app = h('div.app', h('div.scrim', { onclick: () => app.classList.remove('drawer-open') }), sidebar, h('main.main', topbar, content), tabbar);
  return { app, content };
}

function userMenu(anchor) {
  const pref = document.documentElement.dataset.pref || 'dark';
  menu(anchor, [
    { label: 'Indstillinger', icon: 'settings', onClick: () => (location.hash = '#/settings') },
    { label: pref === 'light' ? 'Mørkt tema' : 'Lyst tema', icon: pref === 'light' ? 'moon' : 'sun', onClick: () => setTheme(pref === 'light' ? 'dark' : 'light') },
    'sep',
    { label: 'Log ud', icon: 'logout', onClick: logout },
  ]);
}

export async function logout() {
  try {
    const r = await post('auth.logout');
    state.csrf = r.csrf;
  } catch { /* ignore */ }
  state.user = null;
  shell = null;
  location.hash = '#/login';
  route();
}

function renderSidebarSubjects() {
  const nav = document.getElementById('side-subjects');
  if (!nav) return;
  clear(nav, state.subjects.length
    ? state.subjects.slice(0, 30).map(s => h('a.nav-item', { href: `#/s/${s.id}`, 'data-nav': `#/s/${s.id}` },
      h('span.dot', { style: { background: s.color } }), h('span.truncate', s.name),
      s.due_cards ? h('span.count', String(s.due_cards)) : null))
    : h('a.nav-item', { href: '#/new' }, icon('plus'), h('span', 'Opret et fag')));
  highlightNav();
}

function highlightNav() {
  const hash = location.hash || '#/';
  document.querySelectorAll('[data-nav]').forEach(a => {
    const t = a.dataset.nav;
    const on = t === '#/' ? (hash === '#/' || hash === '#' || hash === '') : hash === t || hash.startsWith(t + '/') || hash.startsWith(t + '?');
    a.classList.toggle('active', on);
  });
}

// ----------------------------------------------------------------- router

function parseHash() {
  const raw = (location.hash || '#/').slice(1);
  const [path, qs] = raw.split('?');
  const parts = path.split('/').filter(Boolean);
  const query = Object.fromEntries(new URLSearchParams(qs || ''));
  return { parts, query };
}

// ------------------------------------------------------------- page animations

let enterTimer = null;
/** Lets the new page slide in (CSS .route-enter) and counts the big numbers up. */
function playEnter(view) {
  if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  view.classList.remove('route-enter');
  void view.offsetWidth; // restart the CSS animations
  view.classList.add('route-enter');
  clearTimeout(enterTimer);
  enterTimer = setTimeout(() => view.classList.remove('route-enter'), 1300);
  view.querySelectorAll('.stat .v').forEach(el => {
    const m = el.textContent.trim().match(/^(\d{1,7})(.*)$/);
    if (!m || +m[1] < 2) return;
    const target = +m[1], suffix = m[2], t0 = performance.now(), dur = 700;
    const tick = now => {
      const p = Math.min(1, (now - t0) / dur);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))) + suffix;
      if (p < 1) requestAnimationFrame(tick);
    };
    requestAnimationFrame(tick);
  });
}

export async function route() {
  if (state.blocked) { showBlocked(typeof state.blocked === 'string' ? state.blocked : ''); return; }
  const { parts, query } = parseHash();
  const first = parts[0] || '';
  if (typeof currentCleanup === 'function') { try { currentCleanup(); } catch { /* ignore */ } }
  currentCleanup = null;
  stopTracking();
  document.querySelectorAll('.menu,.popover').forEach(m => m.remove());

  if (first === 'share' && !state.user) {
    shell = null;
    const c = h('div');
    clear(root, c);
    shareView(c, parts[1] || '');
    return;
  }
  if (!state.user) {
    shell = null;
    const mode = ['login', 'register', 'forgot', 'reset'].includes(first) ? first : 'login';
    clear(root, authView(mode, query, onAuthed));
    return;
  }
  if (['login', 'register'].includes(first)) { location.hash = '#/'; return; }
  if (first === 'reset') { clear(root, authView('reset', query, onAuthed)); shell = null; return; }
  if (!state.user.onboarded && first !== 'onboarding' && first !== 'share' && !sessionStorage.getItem('skipOnboarding')) { location.hash = '#/onboarding'; return; }
  if (first === 'onboarding') {
    shell = null;
    const c = h('div');
    clear(root, c);
    currentCleanup = onboardingView(c, query);
    return;
  }
  if (!shell) {
    shell = buildShell();
    clear(root, shell.app);
    refreshSubjects();
    announceUpdate();
    clearInterval(meterTimer);
    meterTimer = setInterval(refreshUsage, 60000);
  }
  refreshUsage();
  shell.app.classList.remove('drawer-open');
  highlightNav();
  const view = shell.content;
  view.replaceChildren();
  window.scrollTo(0, 0);
  try {
    switch (first) {
      case '': currentCleanup = await dashboardView(view, query); break;
      case 'subjects': currentCleanup = await subjectsView(view, query); break;
      case 'new': currentCleanup = await newSubjectView(view, query); break;
      case 's': currentCleanup = await workspaceView(view, +parts[1], parts[2] || 'overview', query); break;
      case 'memory': currentCleanup = await memoryView(view, query); break;
      case 'search': currentCleanup = await searchView(view, query); break;
      case 'settings': currentCleanup = await settingsView(view, query); break;
      case 'updates': currentCleanup = await updatesView(view, query); break;
      case 'share': currentCleanup = await shareView(view, parts[1] || ''); break;
      case 'admin': currentCleanup = await adminView(view, query); break;
      case 'essay': currentCleanup = await essayView(view, parts[1], query); break;
      default: clear(view, h('div.page', h('div.empty', h('h3', 'Siden blev ikke fundet'), h('a.btn', { href: '#/' }, 'Gå til oversigten'))));
    }
    playEnter(view);
  } catch (e) {
    if (e.status === 401) return;
    clear(view, h('div.page', h('div.empty', h('div.icon-wrap', icon('alert')), h('h3', e.status === 404 ? 'Ikke fundet' : 'Siden kunne ikke indlæses'), h('p', e.message), h('a.btn', { href: '#/' }, 'Tilbage til oversigten'))));
  }
}

function onAuthed(r, isNew) {
  state.user = r.user;
  state.csrf = r.csrf;
  applyTheme(r.user.prefs?.theme || 'dark');
  const pending = sessionStorage.getItem('pendingShare');
  location.hash = pending ? '#/share/' + pending : isNew ? '#/onboarding' : '#/';
  route();
}

addEventListener('hashchange', route);
addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k' && state.user) {
    e.preventDefault();
    location.hash = '#/search';
  }
});

applyTheme(state.user?.prefs?.theme || document.documentElement.dataset.pref || 'dark');
if (BOOT.ban) state.blocked = `Du er blokeret fra ${BOOT.appName || 'Studiepilot'}. Årsag: ${BOOT.ban.reason}`;
route();
// The page itself may not know this device is banned yet (the stored device id is sent with API calls).
if (!state.blocked) get('auth.me').then(r => { if (r.ban) showBlocked(`Du er blokeret fra ${BOOT.appName || 'Studiepilot'}. Årsag: ${r.ban.reason}`); }).catch(() => {});
