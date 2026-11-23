import { h, icon, post, state, BOOT, withBusy, clear } from '../lib.js';

const FEATURES = [
  ['upload', 'Alt dit materiale', 'PDF’er, slides, Word, Excel, ZIP-filer, scanninger, YouTube-videoer og websider.'],
  ['book', 'Studieguide på få minutter', 'Emner, definitioner, vigtige fakta og datoer – med henvisninger til dine kilder.'],
  ['quiz', 'Quizzer der tilpasser sig', 'Spørgsmålene bliver sværere, når du bliver bedre, og fokuserer på det, du har svært ved.'],
  ['brain', 'Husk det til prøven', 'Spaced repetition planlægger gentagelser lige før du ville glemme det.'],
];

export function authView(mode, query, onAuthed) {
  const card = h('div.auth-card');
  const render = () => {
    const err = h('div');
    const setErr = (m, ok = false) => clear(err, m ? h(ok ? 'div.form-ok' : 'div.form-error', m) : null);
    const field = (label, attrs, hint) => h('div.field', h('label', { for: attrs.id }, label), h('input.input', attrs), hint ? h('div.hint', hint) : null);
    const submit = (label) => h('button.btn.primary.lg.block', { type: 'submit' }, label);

    let form;
    if (mode === 'login') {
      form = h('form', { onsubmit: async e => {
        e.preventDefault();
        setErr('');
        const btn = form.querySelector('button[type=submit]');
        await withBusy(btn, async () => {
          try {
            const r = await post('auth.login', { email: form.email.value, password: form.password.value });
            onAuthed(r, false);
          } catch (ex) { setErr(ex.message); }
        });
      } },
      h('h1', 'Velkommen tilbage'), h('p.muted', { style: { marginBottom: '24px' } }, 'Log ind for at fortsætte med at læse.'),
      err,
      field('E-mail', { id: 'email', name: 'email', type: 'email', autocomplete: 'email', required: true }),
      field('Adgangskode', { id: 'password', name: 'password', type: 'password', autocomplete: 'current-password', required: true }),
      h('div.row.between', { style: { margin: '-4px 0 18px' } }, h('span'), h('a.small', { href: '#/forgot' }, 'Glemt adgangskode?')),
      submit('Log ind'),
      h('p.muted.small', { style: { marginTop: '18px', textAlign: 'center' } }, 'Ny her? ', h('a', { href: '#/register' }, 'Opret en konto')));
    } else if (mode === 'register') {
      form = h('form', { onsubmit: async e => {
        e.preventDefault();
        setErr('');
        if (form.password.value !== form.password2.value) return setErr('Adgangskoderne er ikke ens.');
        const btn = form.querySelector('button[type=submit]');
        await withBusy(btn, async () => {
          try {
            const r = await post('auth.register', { name: form.elements['name'].value, email: form.email.value, password: form.password.value });
            onAuthed(r, true);
          } catch (ex) { setErr(ex.message); }
        });
      } },
      h('h1', 'Opret din konto'), h('p.muted', { style: { marginBottom: '24px' } }, 'Gør dit eget materiale til en personlig studieplan.'),
      err,
      field('Navn', { id: 'name', name: 'name', autocomplete: 'name', required: true, maxlength: 80 }),
      field('E-mail', { id: 'email', name: 'email', type: 'email', autocomplete: 'email', required: true }),
      field('Adgangskode', { id: 'password', name: 'password', type: 'password', autocomplete: 'new-password', required: true, minlength: 8 }, 'Mindst 8 tegn.'),
      field('Bekræft adgangskode', { id: 'password2', name: 'password2', type: 'password', autocomplete: 'new-password', required: true }),
      submit('Opret konto'),
      h('p.muted.small', { style: { marginTop: '18px', textAlign: 'center' } }, 'Har du allerede en konto? ', h('a', { href: '#/login' }, 'Log ind')));
    } else if (mode === 'forgot') {
      form = h('form', { onsubmit: async e => {
        e.preventDefault();
        setErr('');
        const btn = form.querySelector('button[type=submit]');
        await withBusy(btn, async () => {
          try {
            const r = await post('auth.forgot', { email: form.email.value });
            clear(err, h('div.form-ok', r.message), r.dev_reset_url
              ? h('div.notice.info', { style: { marginBottom: '14px' } }, icon('info'), h('div', h('div', 'Lokal tilstand: der er ingen mailserver sat op, så her er dit nulstillingslink:'), h('a', { href: r.dev_reset_url, style: { wordBreak: 'break-all' } }, 'Åbn nulstillingslink')))
              : null);
          } catch (ex) { setErr(ex.message); }
        });
      } },
      h('h1', 'Nulstil din adgangskode'), h('p.muted', { style: { marginBottom: '24px' } }, 'Skriv din e-mail, så sender vi dig et link til at nulstille den.'),
      err,
      field('E-mail', { id: 'email', name: 'email', type: 'email', autocomplete: 'email', required: true }),
      submit('Send link'),
      h('p.muted.small', { style: { marginTop: '18px', textAlign: 'center' } }, h('a', { href: '#/login' }, 'Tilbage til log ind')));
    } else {
      form = h('form', { onsubmit: async e => {
        e.preventDefault();
        setErr('');
        if (form.password.value !== form.password2.value) return setErr('Adgangskoderne er ikke ens.');
        const btn = form.querySelector('button[type=submit]');
        await withBusy(btn, async () => {
          try {
            const r = await post('auth.reset', { token: query.token || '', password: form.password.value });
            onAuthed(r, false);
          } catch (ex) { setErr(ex.message); }
        });
      } },
      h('h1', 'Vælg en ny adgangskode'), h('p.muted', { style: { marginBottom: '24px' } }, 'Du bliver logget ind bagefter.'),
      err,
      query.token ? null : h('div.form-error', 'Linket mangler sin kode. Bed om et nyt link.'),
      field('Ny adgangskode', { id: 'password', name: 'password', type: 'password', autocomplete: 'new-password', required: true, minlength: 8 }, 'Mindst 8 tegn.'),
      field('Bekræft ny adgangskode', { id: 'password2', name: 'password2', type: 'password', autocomplete: 'new-password', required: true }),
      submit('Gem ny adgangskode'));
    }
    clear(card, form);
    setTimeout(() => form.querySelector('input')?.focus(), 30);
  };
  render();

  return h('div.auth-wrap',
    h('div.auth-side',
      h('div.brand', { style: { padding: 0 } }, h('span.brand-mark', icon('logo')), BOOT.appName || 'Studiepilot'),
      h('div', { style: { maxWidth: '440px' } },
        h('h2', { style: { fontSize: '26px', lineHeight: 1.25, marginBottom: '10px', letterSpacing: '-.02em' } }, 'Lær smartere med dit eget materiale.'),
        h('p.muted', { style: { marginBottom: '32px' } }, 'Upload det, din lærer har givet dig. ' + (BOOT.appName || 'Studiepilot') + ' laver det om til en studieguide, quizzer, flashcards og en AI-lærer, der kun svarer ud fra dine kilder.'),
        FEATURES.map(([ic, t, d]) => h('div.auth-feature', h('div.ic', icon(ic)), h('div', h('div', { style: { fontWeight: 600 } }, t), h('div.muted.small', d))))),
      h('div.tiny.faint', 'Svar og spørgsmål bygger på dine uploads, med henvisning til side eller afsnit.')),
    h('div.auth-main', card));
}
