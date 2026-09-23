/* Agenda des réservations – interface inspirée de Google Agenda.
 * Pilotes : consultation seule (lien secret). Administrateur connecté : création / modification. */
(function () {
  var C = window.fvrCalendar;
  var root = document.getElementById('fvr-calendar');
  if (!root || !C) return;

  var DURATION = 60; // durée affichée d'un vol, en minutes
  var DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  var DAYS_LONG = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  var MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
    'septembre', 'octobre', 'novembre', 'décembre'];
  var VIEWS = { day: 'Jour', four: '4 jours', week: 'Semaine', month: 'Mois', list: 'Planning' };
  var VIEW_KEYS = { day: 'J', four: '4', week: 'S', month: 'M', list: 'P' };
  var ICON = {
    menu: '<path d="M3 6h18M3 12h18M3 18h18"/>',
    prev: '<path d="M15 5l-7 7 7 7"/>',
    next: '<path d="M9 5l7 7-7 7"/>',
    search: '<circle cx="11" cy="11" r="6.5"/><path d="M16 16l5 5"/>',
    close: '<path d="M6 6l12 12M18 6L6 18"/>',
    back: '<path d="M20 12H5M11 5l-7 7 7 7"/>',
    clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    people: '<circle cx="9" cy="8" r="3.2"/><path d="M3 19c.6-3.4 3-5 6-5s5.4 1.6 6 5"/><circle cx="17" cy="9" r="2.5"/><path d="M16 14c2.6 0 4.4 1.4 5 4"/>',
    plane: '<path d="M3 13l18-7-6 15-3.5-6.5z"/><path d="M11.5 14.5L21 6"/>',
    phone: '<path d="M6.6 3.5l2.6.3 1.3 4-2 1.6a12 12 0 0 0 6.1 6.1l1.6-2 4 1.3.3 2.6c0 1.2-1 2.1-2.2 2.1C10.5 19.5 4.5 13.5 4.5 5.7c0-1.2.9-2.2 2.1-2.2z"/>',
    mail: '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>',
    scale: '<path d="M5 20h14l-2-11H7z"/><circle cx="12" cy="6" r="2.2"/>',
    note: '<path d="M5 4h10l4 4v12H5z"/><path d="M8 11h8M8 15h6"/>',
    chat: '<path d="M4 5h16v11H9l-5 4z"/>',
    money: '<rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
    tag: '<path d="M3 12V4h8l10 10-8 8z"/><circle cx="7.5" cy="8" r="1.3"/>',
    trash: '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
    plus: '<path d="M12 5v14M5 12h14"/>',
    chevron: '<path d="M7 10l5 5 5-5"/>',
    more: '<circle cx="12" cy="5.5" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="12" cy="18.5" r="1.3"/>',
    pilot: '<circle cx="12" cy="7.5" r="3.5"/><path d="M5 20c.8-4 3.6-6 7-6s6.2 2 7 6"/>',
    block: '<circle cx="12" cy="12" r="8.5"/><path d="M6 6l12 12"/>',
    bell: '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
    belloff: '<path d="M6 16V11a6 6 0 0 1 12 0v5l1.5 2h-15z"/><path d="M10 20a2 2 0 0 0 4 0"/><path d="M4 4l16 16"/>',
    cal: '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    sms: '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 10.5h.01M12 10.5h.01M16 10.5h.01"/>',
    wa: '<path d="M4 20l1.3-4A8 8 0 1 1 8 18.7z"/><path d="M9 9.5c.3 2.3 2.2 4.2 4.5 4.5l1-1.2 2 .8-.3 1.6c-3.6.4-7.4-3.4-7-7L10.8 8l.8 2z"/>'
  };
  function icon(name, cls) {
    return '<svg class="g-ic' + (cls ? ' ' + cls : '') + '" viewBox="0 0 24 24" aria-hidden="true">' + ICON[name] + '</svg>';
  }

  function store(key, value) {
    try {
      if (value === undefined) return localStorage.getItem('fvr_cal_' + key);
      localStorage.setItem('fvr_cal_' + key, value);
    } catch (e) { return null; }
  }

  var mq = window.matchMedia('(max-width: 760px)');
  var isPilot = !!C.me && !C.canEdit; // lien personnel d'un pilote
  var state = {
    view: store('view') || (mq.matches ? (C.canEdit ? 'list' : 'day') : 'week'),
    mine: !!C.me && store('mine') !== '0',
    anchor: C.start || C.today,
    pushOn: false,
    showCancelled: store('cancelled') === '1',
    sidebar: !mq.matches && store('sidebar') !== '0',
    data: null,
    sheet: null,
    dragging: false,
    drag: null
  };
  if (!VIEWS[state.view]) state.view = 'week';

  // ---------- Dates (chaînes AAAA-MM-JJ) ----------
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function toDate(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }
  function toStr(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function addDays(s, n) { var d = toDate(s); d.setDate(d.getDate() + n); return toStr(d); }
  function startOfWeek(s) { var d = toDate(s); return addDays(s, -((d.getDay() + 6) % 7)); }
  function monthGrid(s) {
    var d = toDate(s);
    var first = toStr(new Date(d.getFullYear(), d.getMonth(), 1, 12));
    var last = toStr(new Date(d.getFullYear(), d.getMonth() + 1, 0, 12));
    return [startOfWeek(first), addDays(startOfWeek(last), 6)];
  }
  function longDate(s) { var d = toDate(s); return DAYS_LONG[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()]; }
  function mins(t) { var p = String(t).split(':'); return (+p[0]) * 60 + (+p[1] || 0); }
  function hhmm(m) { return pad(Math.floor(m / 60)) + ':' + pad(m % 60); }
  function nowMinutes() { var n = new Date(); return n.getHours() * 60 + n.getMinutes(); }
  function todayStr() { return toStr(new Date()); }

  function range() {
    if (state.view === 'day') return [state.anchor, state.anchor];
    if (state.view === 'week') { var s = startOfWeek(state.anchor); return [s, addDays(s, 6)]; }
    if (state.view === 'four') return [state.anchor, addDays(state.anchor, 3)];
    if (state.view === 'list') return [state.anchor, addDays(state.anchor, 30)];
    return monthGrid(state.anchor);
  }

  function title() {
    var r = range(), a = toDate(r[0]), b = toDate(r[1]), m = toDate(state.anchor);
    if (state.view === 'month' || state.view === 'list') return MONTHS[m.getMonth()] + ' ' + m.getFullYear();
    if (state.view === 'day') {
      if (mq.matches) return DAYS[m.getDay()] + ' ' + m.getDate() + ' ' + (MONTHS[m.getMonth()].length > 5 ? MONTHS[m.getMonth()].slice(0, 4) + '.' : MONTHS[m.getMonth()]);
      return DAYS_LONG[m.getDay()] + ' ' + m.getDate() + ' ' + MONTHS[m.getMonth()] + ' ' + m.getFullYear();
    }
    if (a.getMonth() === b.getMonth()) return MONTHS[a.getMonth()] + ' ' + a.getFullYear();
    return MONTHS[a.getMonth()].slice(0, 4) + '. – ' + MONTHS[b.getMonth()].slice(0, 4) + '. ' + b.getFullYear();
  }

  // ---------- Utilitaires ----------
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function chf(n) { return 'CHF ' + Math.round(Number(n) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, "'"); }
  // Numéro international : 079… → +4179…, 0033… → +33…
  function intl(p) {
    var d = String(p || '').replace(/[^0-9+]/g, '');
    if (d.indexOf('00') === 0) return '+' + d.slice(2);
    if (d.charAt(0) === '0') return '+41' + d.slice(1);
    return d;
  }
  function shortName(n) {
    var parts = String(n || '').trim().split(/\s+/);
    return parts.length > 1 && parts[0].length > 2 ? parts[0] + ' ' + parts[parts.length - 1].charAt(0) + '.' : n;
  }

  function api(path, params, options) {
    var url = C.api + path;
    params = params || {};
    if (C.token) params.token = C.token;
    var qs = new URLSearchParams(params).toString();
    if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
    options = options || {};
    options.headers = options.headers || {};
    options.credentials = 'same-origin';
    if (C.nonce) options.headers['X-WP-Nonce'] = C.nonce;
    return fetch(url, options).then(function (res) {
      return res.json().catch(function () { return {}; }).then(function (data) {
        if (!res.ok) throw new Error(data.message || 'Erreur ' + res.status);
        return data;
      });
    });
  }

  function toast(msg, actionLabel, action) {
    document.querySelectorAll('.g-toast').forEach(function (x) { x.parentNode.removeChild(x); });
    var t = document.createElement('div');
    t.className = 'g-toast';
    t.textContent = msg;
    if (actionLabel) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = actionLabel;
      btn.addEventListener('click', function () { t.parentNode && t.parentNode.removeChild(t); action(); });
      t.appendChild(btn);
    }
    document.body.appendChild(t);
    var life = actionLabel ? 6000 : 2600;
    setTimeout(function () { t.classList.add('out'); }, life);
    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, life + 400);
  }

  // ---------- Données : cache par jour, affichage immédiat, mise à jour en arrière-plan ----------
  var db = { days: {}, bookings: {}, events: {}, blocked: {}, slots: [], pilots: [], flights: [] };
  var inflight = {};
  var loadingCount = 0;

  function values(o) { return Object.keys(o).map(function (k) { return o[k]; }); }
  function rebuild() {
    state.data = {
      bookings: values(db.bookings), events: values(db.events),
      blocked: Object.keys(db.blocked).map(function (d) { return { date: d, reason: db.blocked[d] }; }),
      slots: db.slots, pilots: db.pilots, flights: db.flights
    };
  }
  function merge(data) {
    for (var d = data.from; d <= data.to; d = addDays(d, 1)) { db.days[d] = true; delete db.blocked[d]; }
    [db.bookings, db.events].forEach(function (coll) {
      Object.keys(coll).forEach(function (k) { if (coll[k].date >= data.from && coll[k].date <= data.to) delete coll[k]; });
    });
    data.bookings.forEach(function (b) { db.bookings[b.id] = b; });
    (data.events || []).forEach(function (e) { db.events[e.id] = e; });
    data.blocked.forEach(function (x) { db.blocked[x.date] = x.reason || 'Fermé'; });
    db.slots = data.slots;
    db.pilots = data.pilots || [];
    if (data.flights) db.flights = data.flights;
    rebuild();
  }
  function neededRange() {
    var r = range(), from = r[0], to = r[1];
    // Le mini-calendrier affiche le mois : on charge aussi ce mois pour ses indicateurs
    if (state.sidebar) {
      var g = monthGrid(state.anchor);
      if (g[0] < from) from = g[0];
      if (g[1] > to) to = g[1];
    }
    return [from, to];
  }
  function covered(from, to) {
    for (var d = from; d <= to; d = addDays(d, 1)) if (!db.days[d]) return false;
    return true;
  }
  function setLoading(delta) {
    loadingCount = Math.max(0, loadingCount + delta);
    root.classList.toggle('is-loading', loadingCount > 0);
  }
  function fetchRange(from, to, silent) {
    var key = from + '|' + to;
    if (inflight[key]) return inflight[key];
    if (!silent) setLoading(1);
    inflight[key] = api('calendar', { from: from, to: to }).then(function (data) {
      var before = state.data ? JSON.stringify(sliceOf(from, to)) : null;
      merge(data);
      // Ne redessine que si la période affichée a réellement changé
      var r = neededRange();
      if (!(to < r[0] || from > r[1]) && JSON.stringify(sliceOf(from, to)) !== before) render();
      return data;
    }).finally(function () {
      delete inflight[key];
      if (!silent) setLoading(-1);
    });
    return inflight[key];
  }
  function sliceOf(from, to) {
    var inR = function (x) { return x.date >= from && x.date <= to; };
    return [state.data.bookings.filter(inR), state.data.events.filter(inR), state.data.blocked.filter(inR), state.data.slots, state.data.pilots];
  }
  function prefetch() {
    // Précharge la période précédente et suivante pour une navigation instantanée
    [-1, 1].forEach(function (n) {
      var saved = state.anchor;
      state.anchor = shiftedAnchor(n);
      var r = neededRange();
      state.anchor = saved;
      if (!covered(r[0], r[1])) fetchRange(r[0], r[1], true).catch(function () {});
    });
  }
  function load() {
    var r = neededRange();
    if (state.data && covered(r[0], r[1])) {
      render();
      fetchRange(r[0], r[1], true).catch(function () {});
      prefetch();
      return Promise.resolve();
    }
    if (state.data) render(); // affiche déjà la nouvelle période (vide) avec la barre de chargement
    return fetchRange(r[0], r[1], false).then(function () { render(); prefetch(); }).catch(function (err) {
      if (!state.data) root.innerHTML = '<p class="g-error">Impossible de charger l\'agenda : ' + esc(err.message) + '</p>';
      else toast('Connexion impossible : ' + err.message);
    });
  }
  // Recharge silencieusement des jours précis (après une modification)
  function refreshDays(dates) {
    dates = dates.filter(Boolean).sort();
    if (!dates.length) return Promise.resolve();
    return fetchRange(dates[0], dates[dates.length - 1], true).then(function () { render(); });
  }

  function isMine(b) { return !!C.me && (b.pilots || []).indexOf(C.me.id) !== -1; }
  function visible() {
    return state.data.bookings.filter(function (b) {
      return (state.showCancelled || b.status !== 'cancelled') && (!state.mine || isMine(b));
    });
  }
  function pilotsById() {
    var m = {};
    (state.data.pilots || []).forEach(function (p) { m[p.id] = p; });
    return m;
  }
  // « Tristan, Maël, à définir » ; « Toi » pour le pilote qui consulte son lien
  function pilotLabel(b) {
    var byId = pilotsById();
    var seats = b.pilots && b.pilots.length ? b.pilots : [];
    if (!seats.length || !(state.data.pilots || []).length) return '';
    return seats.map(function (id) {
      if (!id || !byId[id]) return '<em class="g-tbd">à définir</em>';
      return C.me && id === C.me.id ? '<strong>Toi</strong>' : esc(byId[id].name);
    }).join(', ');
  }
  function missingPilots(b) { return (b.pilots || []).some(function (id) { return !id; }) && (state.data.pilots || []).length > 0; }
  function byDate() {
    var m = {};
    visible().forEach(function (b) { (m[b.date] = m[b.date] || []).push(b); });
    return m;
  }
  function blockedMap() {
    var m = {};
    state.data.blocked.forEach(function (x) { m[x.date] = x.reason || 'Fermé'; });
    return m;
  }
  function activeSlots() { return state.data.slots.filter(function (s) { return s.active; }); }
  function paxAt(list, time) {
    return (list || []).reduce(function (n, b) { return n + (b.time === time && b.status !== 'cancelled' ? b.passengers : 0); }, 0);
  }
  function findBooking(id) { return state.data.bookings.filter(function (b) { return b.id === id; })[0]; }

  // Plage horaire affichée : autour des créneaux et des réservations
  function hourRange() {
    var ms = activeSlots().map(function (s) { return mins(s.time); });
    visible().forEach(function (b) { ms.push(mins(b.time)); });
    var lo = Math.min.apply(null, ms.concat([8 * 60])), hi = Math.max.apply(null, ms.map(function (m) { return m + DURATION; }).concat([18 * 60]));
    return [Math.max(0, Math.floor(lo / 60)), Math.min(24, Math.ceil(hi / 60))];
  }

  // ---------- Rendu général ----------
  function render() {
    var scrollTop = null;
    var body = root.querySelector('.g-body');
    if (body) scrollTop = body.scrollTop;

    root.className = 'fvr-cal g-app' + (state.sidebar ? ' with-sidebar' : '') + (C.canEdit ? ' can-edit' : ' read-only') +
      (loadingCount > 0 ? ' is-loading' : '');
    root.innerHTML = topbar() +
      '<div class="g-main">' + sidebar() +
      '<div class="g-content' + (state.anim ? ' g-anim-' + state.anim : '') + '">' + filterBar() + content() + '</div></div>' +
      (C.canEdit ? '<button type="button" class="g-fab" data-create aria-label="Créer">' + icon('plus') + '</button>' : '') +
      (isPilot ? '<button type="button" class="g-fab g-fab-abs" data-absence>' + icon('block') + '<span>Absence</span></button>' : '');

    state.anim = '';
    var nb = root.querySelector('.g-body');
    if (nb) {
      // Aligne les en-têtes de jours avec la grille quand une barre de défilement est visible
      root.querySelector('.g-grid').style.setProperty('--sbw', (nb.offsetWidth - nb.clientWidth) + 'px');
      if (scrollTop !== null) nb.scrollTop = scrollTop;
      else {
        var hr = hourRange(), first = activeSlots()[0];
        var target = first ? mins(first.time) / 60 : hr[0] + 1;
        nb.scrollTop = Math.max(0, (target - hr[0] - 0.5) * hourHeight());
      }
    }
  }

  function compactDay() { return mq.matches && state.view === 'day'; }

  function viewMenu(iconOnly) {
    return '<div class="g-menu-wrap"><button type="button" class="' + (iconOnly ? 'g-icon-btn' : 'g-view-btn') + '" data-menu aria-label="Affichage">' +
      (iconOnly ? icon('more') : VIEWS[state.view] + icon('chevron')) + '</button>' +
      '<div class="g-menu" hidden>' +
      Object.keys(VIEWS).map(function (v) {
        return '<button type="button" data-view="' + v + '"' + (v === state.view ? ' class="on"' : '') + '>' + VIEWS[v] + '<kbd>' + VIEW_KEYS[v] + '</kbd></button>';
      }).join('') +
      '<hr><label><input type="checkbox" data-cancelled' + (state.showCancelled ? ' checked' : '') + '> Afficher les annulées</label>' +
      (C.icsUrl ? '<a href="' + esc(C.icsUrl.replace(/^https?:/, 'webcal:')) + '">' + icon('cal') + ' Ajouter à mon agenda</a>' : '') +
      '</div></div>';
  }

  function topbar() {
    if (compactDay()) {
      return '<header class="g-top g-top-day"><div class="g-progress"></div>' +
        '<button type="button" class="g-icon-btn" data-nav="-1" aria-label="Jour précédent">' + icon('prev') + '</button>' +
        '<label class="g-datepick' + (state.anchor === C.today ? ' is-today' : '') + '"><span>' + esc(title()) + '</span>' +
        '<input type="date" data-datepick value="' + esc(state.anchor) + '" aria-label="Choisir une date"></label>' +
        '<button type="button" class="g-icon-btn" data-nav="1" aria-label="Jour suivant">' + icon('next') + '</button>' +
        '<span class="g-spacer"></span>' +
        (state.anchor !== C.today ? '<button type="button" class="g-today" data-nav="0" aria-label="Aujourd\'hui"><span class="g-today-short">' + toDate(C.today).getDate() + '</span></button>' : '') +
        (C.canEdit ? '<button type="button" class="g-icon-btn" data-search aria-label="Rechercher">' + icon('search') + '</button>' : '') +
        (C.push ? '<button type="button" class="g-icon-btn g-bell' + (state.pushOn ? ' on' : '') + '" data-push aria-label="Notifications">' + icon(state.pushOn ? 'bell' : 'belloff') + '</button>' : '') +
        viewMenu(true) + '</header>';
    }
    return '<header class="g-top"><div class="g-progress"></div>' +
      '<button type="button" class="g-icon-btn" data-toggle-sidebar aria-label="Menu">' + icon('menu') + '</button>' +
      '<div class="g-brand">' + icon('cal', 'g-brand-ic') + '<span>' + esc(C.site || 'Planning') + '</span></div>' +
      '<button type="button" class="g-today" data-nav="0" aria-label="Aujourd\'hui"><span class="g-today-full">Aujourd\'hui</span><span class="g-today-short">' + toDate(C.today).getDate() + '</span></button>' +
      '<div class="g-arrows"><button type="button" class="g-icon-btn" data-nav="-1" aria-label="Précédent">' + icon('prev') + '</button>' +
      '<button type="button" class="g-icon-btn" data-nav="1" aria-label="Suivant">' + icon('next') + '</button></div>' +
      '<h1 class="g-title">' + esc(title()) + '</h1>' +
      '<span class="g-spacer"></span>' +
      (C.canEdit ? '' : '<span class="g-ro">Lecture seule</span>') +
      (C.canEdit || !mq.matches ? '<button type="button" class="g-icon-btn" data-search aria-label="Rechercher">' + icon('search') + '</button>' : '') +
      (C.push ? '<button type="button" class="g-icon-btn g-bell' + (state.pushOn ? ' on' : '') + '" data-push aria-label="Notifications">' + icon(state.pushOn ? 'bell' : 'belloff') + '</button>' : '') +
      viewMenu(false) + '</header>';
  }

  function sidebar() {
    return '<aside class="g-side">' +
      (C.canEdit ? '<button type="button" class="g-create" data-create>' + icon('plus') + '<span>Créer</span></button>' : '') +
      (isPilot ? '<button type="button" class="g-create" data-absence>' + icon('block') + '<span>Déclarer une absence</span></button>' : '') +
      miniMonth() +
      '<div class="g-legend"><h3>Statuts</h3>' + Object.keys(C.statuses).map(function (k) {
        return '<div><span class="g-dot st-' + k + '"></span>' + esc(C.statuses[k]) + '</div>';
      }).join('') + '</div>' +
      '<label class="g-side-check"><input type="checkbox" data-cancelled' + (state.showCancelled ? ' checked' : '') + '> Afficher les annulées</label>' +
      (C.icsUrl ? '<a class="g-side-link" href="' + esc(C.icsUrl.replace(/^https?:/, 'webcal:')) + '">' + icon('cal') + ' Ajouter à mon agenda</a>' : '') +
      '</aside><div class="g-scrim" data-toggle-sidebar></div>';
  }

  function miniMonth() {
    var g = monthGrid(state.anchor), m = toDate(state.anchor), per = byDate();
    var r = range();
    var h = '<div class="g-mini"><div class="g-mini-head"><span>' + MONTHS[m.getMonth()] + ' ' + m.getFullYear() + '</span>' +
      '<button type="button" class="g-icon-btn sm" data-mini="-1" aria-label="Mois précédent">' + icon('prev') + '</button>' +
      '<button type="button" class="g-icon-btn sm" data-mini="1" aria-label="Mois suivant">' + icon('next') + '</button></div>' +
      '<div class="g-mini-grid">' + ['L', 'M', 'M', 'J', 'V', 'S', 'D'].map(function (d) { return '<span class="g-mini-dow">' + d + '</span>'; }).join('');
    for (var d = g[0]; d <= g[1]; d = addDays(d, 1)) {
      var dt = toDate(d);
      var cls = (dt.getMonth() !== m.getMonth() ? ' other' : '') + (d === C.today ? ' today' : '') +
        (d === state.anchor ? ' sel' : '') + (d >= r[0] && d <= r[1] && state.view !== 'month' ? ' inrange' : '') + (per[d] ? ' has' : '');
      h += '<button type="button" class="g-mini-day' + cls + '" data-goto="' + d + '">' + dt.getDate() + '</button>';
    }
    return h + '</div></div>';
  }

  function filterBar() {
    if (!C.me) return '';
    return '<div class="g-filter" role="tablist">' +
      '<button type="button" data-mine="1"' + (state.mine ? ' class="on"' : '') + '>' + icon('pilot') + 'Mes vols</button>' +
      '<button type="button" data-mine="0"' + (!state.mine ? ' class="on"' : '') + '>Tous les vols</button></div>';
  }

  function content() {
    if (state.view === 'month') return renderMonth();
    if (state.view === 'list') return renderList();
    var r = range(), days = [];
    for (var d = r[0]; d <= r[1]; d = addDays(d, 1)) days.push(d);
    return renderGrid(days);
  }

  function hourHeight() { return state.view === 'four' ? 88 : state.view === 'day' ? 64 : 52; }

  // ---------- Vue jour / semaine : grille horaire ----------
  // ---------- Événements et places libres ----------
  function eventsOn(ds, date) {
    return (ds.events || []).filter(function (e) { return e.date === date; })
      .sort(function (a, b) { return b.all_day - a.all_day || (a.start < b.start ? -1 : a.start > b.start ? 1 : 0); });
  }
  // Places bloquées par les événements sur un créneau (Infinity = tout est bloqué)
  function eventBlocked(ds, date, time) {
    var s = mins(time), e = s + DURATION, total = 0, evs = eventsOn(ds, date);
    for (var i = 0; i < evs.length; i++) {
      var ev = evs[i];
      if (!ev.all_day && !(mins(ev.start) < e && mins(ev.end) > s)) continue;
      if (ev.blocks <= 0) return Infinity;
      total += ev.blocks;
    }
    return total;
  }
  function slotFree(ds, date, time, cap, excludeId) {
    var taken = ds.bookings.reduce(function (n, x) {
      return n + (x.date === date && x.time === time && x.status !== 'cancelled' && x.id !== excludeId ? x.passengers : 0);
    }, 0);
    var blocked = eventBlocked(ds, date, time);
    return { taken: taken, blocked: blocked, free: blocked === Infinity ? 0 : Math.max(0, cap - taken - blocked) };
  }
  function isFuture(date, time) { return date > C.today || (date === C.today && mins(time) > nowMinutes()); }
  // Créneau ouvert à la réservation ce jour-là (date limite du créneau et période de réservation en ligne)
  function slotOpen(s, date) { return (!s.until || date <= s.until) && (!C.lastDay || date <= C.lastDay); }
  // Créneaux encore réservables d'un jour : [{time, free}]
  function freeSlots(date) {
    if (blockedMap()[date]) return [];
    return activeSlots().filter(function (s) { return isFuture(date, s.time) && slotOpen(s, date); }).map(function (s) {
      return { time: s.time, free: slotFree(state.data, date, s.time, s.capacity).free };
    }).filter(function (x) { return x.free > 0; });
  }
  function blocksLabel(ev) { return ev.blocks > 0 ? ev.blocks + ' place' + (ev.blocks > 1 ? 's' : '') + ' bloquée' + (ev.blocks > 1 ? 's' : '') : 'toutes les places bloquées'; }
  function eventTime(ev) { return ev.all_day ? 'Toute la journée' : ev.start + ' – ' + ev.end; }
  function findEvent(id) { return state.data.events.filter(function (e) { return e.id === id; })[0]; }

  // ---------- Vue jour / semaine : grille horaire ----------
  function layout(items) {
    // Répartit en colonnes les éléments qui se chevauchent (comme Google Agenda)
    var evs = items.slice().sort(function (x, y) { return x.s - y.s || x.order - y.order; });
    var clusters = [], cur = null;
    evs.forEach(function (ev) {
      if (!cur || ev.s >= cur.end) { cur = { items: [], end: 0, cols: [] }; clusters.push(cur); }
      var col = 0;
      while (cur.cols[col] !== undefined && cur.cols[col] > ev.s) col++;
      cur.cols[col] = ev.e;
      ev.col = col;
      cur.items.push(ev);
      cur.end = Math.max(cur.end, ev.e);
    });
    clusters.forEach(function (c) { c.items.forEach(function (ev) { ev.n = c.cols.length; }); });
    return evs;
  }

  function renderGrid(days) {
    var hr = hourRange(), H = hourHeight(), per = byDate(), blocked = blockedMap(), slots = activeSlots();
    var h = '<div class="g-grid' + (days.length === 1 ? ' g-oneday' : '') + (state.view === 'four' ? ' g-four' : '') + '" style="--hour-h:' + H + 'px;--days:' + days.length + '">';
    h += '<div class="g-grid-head"><div class="g-gutter"></div>';
    days.forEach(function (d) {
      var dt = toDate(d), list = per[d] || [];
      var pax = list.reduce(function (n, b) { return n + (b.status === 'cancelled' ? 0 : b.passengers); }, 0);
      h += '<div class="g-dayhead' + (d === C.today ? ' today' : '') + (d < C.today ? ' past' : '') + '">' +
        '<span class="g-dow">' + DAYS[dt.getDay()].replace('.', '') + '</span>' +
        '<button type="button" class="g-dnum" data-goto="' + d + '" data-to-day>' + dt.getDate() + '</button>' +
        (blocked[d] ? '<span class="g-closed">' + esc(blocked[d]) + '</span>' : pax ? '<span class="g-daypax">' + pax + ' pax</span>' : '<span class="g-daypax">&nbsp;</span>') +
        '</div>';
    });
    h += '</div>';
    // Événements « toute la journée »
    var allDay = days.map(function (d) { return eventsOn(state.data, d).filter(function (e) { return e.all_day; }); });
    if (allDay.some(function (l) { return l.length; })) {
      h += '<div class="g-allday"><div class="g-gutter"></div>' + allDay.map(function (l) {
        return '<div class="g-allday-cell">' + l.map(function (e) {
          return '<div class="g-evt-chip" data-event="' + e.id + '" tabindex="0" role="button" title="' + esc(e.title + ' – ' + blocksLabel(e)) + '">⛔ ' + esc(e.title) + '</div>';
        }).join('') + '</div>';
      }).join('') + '</div>';
    }
    h += '<div class="g-body"><div class="g-body-in" style="height:' + ((hr[1] - hr[0]) * H) + 'px">';
    h += '<div class="g-gutter">';
    for (var i = hr[0]; i < hr[1]; i++) h += '<span' + (i === hr[0] ? ' class="first"' : '') + ' style="top:' + ((i - hr[0]) * H) + 'px">' + pad(i) + ':00</span>';
    h += '</div><div class="g-cols">';
    days.forEach(function (d) {
      var list = per[d] || [];
      h += '<div class="g-col' + (blocked[d] ? ' blocked' : '') + (d < C.today ? ' past' : '') + '" data-date="' + d + '">';
      var items = list.map(function (b) { return { kind: 'b', b: b, s: mins(b.time), e: mins(b.time) + DURATION, order: 1 - b.passengers / 100 }; });
      eventsOn(state.data, d).filter(function (e) { return !e.all_day; }).forEach(function (e) {
        items.push({ kind: 'e', ev: e, s: mins(e.start), e: Math.max(mins(e.end), mins(e.start) + 15), order: 0 });
      });
      slots.forEach(function (s) {
        var st = slotFree(state.data, d, s.time, s.capacity);
        // Places libres (administrateur) : bloc cliquable pour réserver
        if (C.canEdit && st.free > 0 && !blocked[d] && isFuture(d, s.time) && slotOpen(s, d)) {
          items.push({ kind: 'f', time: s.time, free: st.free, s: mins(s.time), e: mins(s.time) + DURATION, order: 2 });
        }
        if (C.canEdit && st.blocked !== Infinity && st.taken > s.capacity) {
          h += '<div class="g-slot over" style="top:' + ((mins(s.time) / 60 - hr[0]) * H) + 'px;height:' + (DURATION / 60 * H) + 'px"><span>' + st.taken + '/' + s.capacity + '</span></div>';
        }
      });
      // Places libres : colonne entière si le créneau est vide, sinon pastille étroite à droite
      var main = items.filter(function (it) { return it.kind !== 'f'; });
      var ghosts = items.filter(function (it) { return it.kind === 'f'; });
      layout(main);
      var narrow = state.view === 'four' && mq.matches;
      ghosts = ghosts.filter(function (g) {
        g.side = main.some(function (m) { return m.s < g.e && m.e > g.s; });
        return !(narrow && g.side); // 4 jours sur téléphone : pas de pastille latérale, place aux vols
      });
      ghosts.forEach(function (g) {
        if (g.side) main.forEach(function (m) { if (m.s < g.e && m.e > g.s) m.reserve = true; });
      });
      main.concat(ghosts).forEach(function (it) {
        var top = (it.s / 60 - hr[0]) * H, height = Math.max(22, (it.e - it.s) / 60 * H - 3);
        var pos;
        if (it.kind === 'f') {
          pos = ' style="top:' + top + 'px;height:' + height + 'px;' + (it.side ? 'right:2px;width:34px' : 'left:1px;width:calc(100% - 4px)') + '"';
          h += '<button type="button" class="g-ghost' + (it.side ? ' side' : '') + '" data-free-date="' + d + '" data-free-time="' + it.time + '"' + pos +
            ' title="' + it.free + ' place' + (it.free > 1 ? 's' : '') + ' libre' + (it.free > 1 ? 's' : '') + ' à ' + it.time + ' – réserver">' +
            '<b>+' + (it.side ? '' : ' ') + it.free + '</b>' + (it.side ? '' : '<span>libre' + (it.free > 1 ? 's' : '') + '</span>') + '</button>';
          return;
        }
        var R = !it.reserve ? '0px' : state.view === 'four' ? (mq.matches ? '30px' : '48px') : '38px', w = 1 / it.n;
        pos = ' style="top:' + top + 'px;height:' + height + 'px;left:calc((100% - ' + R + ') * ' + (it.col * w) + ' + 1px);width:calc((100% - ' + R + ') * ' + w + ' - 4px)"';
        if (it.kind === 'e') {
          var e = it.ev;
          h += '<div class="g-evt' + (e.pilot_id ? ' abs' : '') + (C.me && e.pilot_id === C.me.id ? ' mine' : '') + '" data-event="' + e.id + '" tabindex="0" role="button"' + pos + '>' +
            '<b>⛔ ' + esc(e.title) + '</b><span>' + esc(eventTime(e)) + ' · ' + blocksLabel(e) + '</span></div>';
          return;
        }
        var b = it.b;
        h += '<div class="g-ev st-' + esc(b.status) + (height < 40 ? ' short' : '') + (C.me && !isMine(b) ? ' other' : '') + (C.me && isMine(b) ? ' mine' : '') + '" data-id="' + b.id + '"' +
          (C.canEdit ? ' draggable="true"' : '') + ' tabindex="0" role="button"' + pos + '>' +
          '<b>' + esc(b.name) + '</b>' +
          '<span>' + esc(b.time) + ' · ' + b.passengers + ' pax' + (b.weights ? ' · ' + esc(b.weights) + ' kg' : '') + '</span>' +
          (pilotLabel(b) ? '<span class="g-ev-pilots">' + icon('pilot') + pilotLabel(b) + '</span>' : '') +
          '<span class="g-ev-flight">' + esc(b.flight_name) + '</span></div>';
      });
      if (d === todayStr()) {
        var nm = nowMinutes();
        if (nm / 60 > hr[0] && nm / 60 < hr[1]) h += '<div class="g-now" style="top:' + ((nm / 60 - hr[0]) * H) + 'px"></div>';
      }
      h += '</div>';
    });
    return h + '</div></div></div></div>';
  }

  // ---------- Vue mois ----------
  function renderMonth() {
    var g = monthGrid(state.anchor), m = toDate(state.anchor).getMonth(), per = byDate(), blocked = blockedMap();
    var weeks = 0;
    var h = '<div class="g-month"><div class="g-month-head">' + ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'].map(function (d) {
      return '<span>' + d + '</span>';
    }).join('') + '</div><div class="g-month-grid">';
    for (var d = g[0]; d <= g[1]; d = addDays(d, 1)) {
      var dt = toDate(d), list = (per[d] || []).slice().sort(function (a, b) { return a.time < b.time ? -1 : 1; });
      var evs = eventsOn(state.data, d);
      var free = C.canEdit ? freeSlots(d).reduce(function (n, x) { return n + x.free; }, 0) : 0;
      var room = Math.max(1, 3 - evs.length);
      if (dt.getDay() === 1) weeks++;
      h += '<div class="g-mday' + (dt.getMonth() !== m ? ' other' : '') + (blocked[d] ? ' blocked' : '') + '" data-date="' + d + '">' +
        '<button type="button" class="g-mnum' + (d === C.today ? ' today' : '') + '" data-goto="' + d + '" data-to-day>' + dt.getDate() + '</button>' +
        (blocked[d] ? '<span class="g-closed">' + esc(blocked[d]) + '</span>' : '') +
        evs.map(function (e) {
          return '<div class="g-mevt" data-event="' + e.id + '" tabindex="0" role="button">⛔ ' + (e.all_day ? '' : esc(e.start) + ' ') + esc(e.title) + '</div>';
        }).join('') +
        list.slice(0, room).map(function (b) {
          return '<div class="g-mev st-' + esc(b.status) + '" data-id="' + b.id + '" tabindex="0" role="button"><i></i>' +
            '<span class="t">' + esc(b.time) + '</span> <span class="n">' + esc(shortName(b.name)) + '</span> <span class="p">' + b.passengers + '</span></div>';
        }).join('') +
        (list.length > room ? '<button type="button" class="g-more" data-goto="' + d + '" data-to-day>' + (list.length - room) + ' autre' + (list.length - room > 1 ? 's' : '') + '</button>' : '') +
        (free ? '<span class="g-mfree">' + free + ' libre' + (free > 1 ? 's' : '') + '</span>' : '') +
        '</div>';
    }
    return h.replace('g-month-grid">', 'g-month-grid" style="--weeks:' + weeks + '">') + '</div></div>';
  }

  // ---------- Vue planning (liste) ----------
  function renderList() {
    var per = byDate(), blocked = blockedMap(), r = range(), any = false;
    var h = '<div class="g-list">';
    for (var d = r[0]; d <= r[1]; d = addDays(d, 1)) {
      var list = (per[d] || []).slice().sort(function (a, b) { return a.time < b.time ? -1 : a.time > b.time ? 1 : a.id - b.id; });
      var evs = eventsOn(state.data, d), free = C.canEdit ? freeSlots(d) : [];
      if (!list.length && !blocked[d] && !evs.length && !free.length && d !== C.today) continue;
      any = true;
      var dt = toDate(d);
      h += '<section class="g-lday' + (d === C.today ? ' today' : '') + '"><button type="button" class="g-ldate" data-goto="' + d + '" data-to-day>' +
        '<b>' + dt.getDate() + '</b><span>' + MONTHS[dt.getMonth()].slice(0, 4) + '., ' + DAYS[dt.getDay()] + '</span></button><div class="g-litems">';
      if (blocked[d]) h += '<div class="g-litem closed">' + (blocked[d] === 'Fermé' ? 'Fermé' : 'Fermé · ' + esc(blocked[d])) + '</div>';
      evs.forEach(function (e) {
        h += '<div class="g-litem g-levt" data-event="' + e.id + '" tabindex="0" role="button"><span class="g-ltime">' + (e.all_day ? 'Jour' : esc(e.start)) + '</span>' +
          '<span class="g-lmain"><b>⛔ ' + esc(e.title) + '</b><small>' + esc(eventTime(e)) + ' · ' + blocksLabel(e) + '</small></span></div>';
      });
      if (!list.length && !blocked[d] && !evs.length && !free.length) h += '<div class="g-litem empty">Aucun vol aujourd\'hui</div>';
      list.forEach(function (b) {
        h += '<div class="g-litem st-' + esc(b.status) + '" data-id="' + b.id + '" tabindex="0" role="button">' +
          '<i class="g-dot st-' + esc(b.status) + '"></i><span class="g-ltime">' + esc(b.time) + '</span>' +
          '<span class="g-lmain"><b>' + esc(b.name) + '</b><small>' + b.passengers + ' pax · ' + esc(b.flight_name) +
          (b.weights ? ' · ' + esc(b.weights) + ' kg' : '') + (b.status !== 'confirmed' ? ' · ' + esc(C.statuses[b.status] || b.status) : '') + '</small>' +
          (pilotLabel(b) ? '<small class="g-lpilots">' + icon('pilot') + pilotLabel(b) + '</small>' : '') + '</span>' +
          (b.phone ? '<a class="g-lcall" href="tel:' + esc(intl(b.phone)) + '" aria-label="Appeler ' + esc(b.name) + '">' + icon('phone') + '</a>' : '') +
          '</div>';
      });
      // Places encore libres : un tap pour réserver
      if (free.length) {
        h += '<div class="g-lfree"><span>Libre</span>' + free.map(function (x) {
          return '<button type="button" data-free-date="' + d + '" data-free-time="' + x.time + '">' + x.time + ' <b>' + x.free + '</b></button>';
        }).join('') + '</div>';
      }
      h += '</div></section>';
    }
    if (!any) h += '<p class="g-empty">Aucune réservation sur les 30 prochains jours.</p>';
    return h + '<button type="button" class="g-list-more" data-list-more>Afficher les 30 jours suivants</button></div>';
  }

  // ---------- Feuille (détail / modification) ----------
  function openSheet(html, cls) {
    closeSheet(true);
    var bg = document.createElement('div');
    bg.className = 'g-sheet-bg fvr-cal';
    bg.innerHTML = '<div class="g-sheet ' + (cls || '') + '" role="dialog" aria-modal="true">' + html + '</div>';
    document.body.appendChild(bg);
    document.documentElement.classList.add('g-noscroll');
    state.sheet = { el: bg, dirty: false };
    bg.addEventListener('click', function (e) {
      if (e.target === bg || e.target.closest('[data-close]')) closeSheet();
    });
    requestAnimationFrame(function () { bg.classList.add('in'); });
    return bg;
  }

  function closeSheet(force) {
    if (!state.sheet) return;
    if (!force && state.sheet.dirty && !confirm('Fermer sans enregistrer les modifications ?')) return;
    var el = state.sheet.el;
    el.parentNode.removeChild(el);
    state.sheet = null;
    document.documentElement.classList.remove('g-noscroll');
  }

  function row(ic, html) { return html ? '<div class="g-row">' + icon(ic) + '<div>' + html + '</div></div>' : ''; }

  function contactButtons(phone, email) {
    var p = intl(phone), h = '';
    if (phone) {
      h += '<a class="g-chipbtn" href="tel:' + esc(p) + '">' + icon('phone') + 'Appeler</a>' +
        '<a class="g-chipbtn" href="sms:' + esc(p) + '">' + icon('sms') + 'SMS</a>' +
        '<a class="g-chipbtn" href="https://wa.me/' + esc(p.replace(/\D/g, '')) + '" target="_blank" rel="noopener">' + icon('wa') + 'WhatsApp</a>';
    }
    if (email) h += '<a class="g-chipbtn" href="mailto:' + esc(email) + '">' + icon('mail') + 'E-mail</a>';
    return h ? '<div class="g-contact">' + h + '</div>' : '';
  }

  function showDetails(b) {
    openSheet('<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button></div>' +
      '<div class="g-sheet-body g-details">' +
      '<div class="g-dtitle"><span class="g-square st-' + esc(b.status) + '"></span><div><h2>' + esc(b.name) + '</h2>' +
      '<p>' + esc(longDate(b.date)) + ' · ' + esc(b.time) + ' – ' + hhmm(mins(b.time) + DURATION) + '</p></div></div>' +
      row('tag', esc(C.statuses[b.status] || b.status) + ' · réf. ' + esc(b.reference)) +
      row('people', b.passengers + ' passager' + (b.passengers > 1 ? 's' : '') + (b.weights ? ' · ' + esc(b.weights) + ' kg' : '')) +
      row('plane', esc(b.flight_name)) +
      row('pilot', pilotLabel(b)) +
      row('phone', b.phone ? '<a href="tel:' + esc(intl(b.phone)) + '">' + esc(b.phone) + '</a>' : '') +
      row('chat', esc(b.message)) +
      row('note', esc(b.admin_notes)) +
      contactButtons(b.phone, '') + '</div>', 'g-sheet-details');
  }

  // Horaires proposés : de 8h à 18h, tous les quarts d'heure (+ l'heure actuelle si elle sort de cette plage)
  function timeOptions(current) {
    var list = [];
    for (var m = 8 * 60; m <= 18 * 60; m += 15) list.push(hhmm(m));
    if (current && list.indexOf(current) === -1) { list.push(current); list.sort(); }
    return list.map(function (t) { return '<option' + (t === current ? ' selected' : '') + '>' + t + '</option>'; }).join('');
  }

  function editBooking(b) {
    var isNew = !b.id;
    var flights = state.data.flights || [];
    if (isNew && !b.flight_id && flights.length) {
      var def = flights.filter(function (f) { return f.active; })[0] || flights[0];
      b.flight_id = def.id;
      b.price = def.price * (b.passengers || 1);
    }
    var flightOptions = flights.map(function (f) {
      return '<option value="' + f.id + '" data-price="' + f.price + '"' + (f.id === b.flight_id ? ' selected' : '') + '>' +
        esc(f.name) + ' · ' + chf(f.price) + (f.active ? '' : ' (inactif)') + '</option>';
    }).join('');
    if (!flights.some(function (f) { return f.id === b.flight_id; })) {
      flightOptions = '<option value="0" selected>' + esc(b.flight_name || 'Vol') + '</option>' + flightOptions;
    }

    var bg = openSheet(
      '<form class="g-form" novalidate>' +
      '<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button>' +
      '<span class="g-sheet-title">' + (isNew ? 'Nouvelle réservation' : 'Réservation ' + esc(b.reference)) + '</span>' +
      '<button type="submit" class="g-save">Enregistrer</button></div>' +
      '<div class="g-sheet-body">' +
      '<input class="g-name" name="name" placeholder="Nom du client" autocomplete="off" value="' + esc(b.name) + '" required>' +
      '<div class="g-status">' + Object.keys(C.statuses).map(function (k) {
        return '<label class="st-' + k + '"><input type="radio" name="status" value="' + k + '"' + (k === (b.status || 'confirmed') ? ' checked' : '') + '><span>' + esc(C.statuses[k]) + '</span></label>';
      }).join('') + '</div>' +

      '<div class="g-frow">' + icon('clock') + '<div class="g-fcol">' +
      '<div class="g-inline"><input type="date" name="date" value="' + esc(b.date) + '" required>' +
      '<select name="time" class="g-timesel" required>' + timeOptions(b.time) + '</select></div>' +
      '<div class="g-slots" aria-label="Horaires"></div></div></div>' +

      '<div class="g-frow">' + icon('people') + '<div class="g-fcol g-inline">' +
      '<div class="g-stepper"><button type="button" data-step="-1" aria-label="Moins">−</button>' +
      '<input type="number" name="passengers" min="1" max="30" value="' + (b.passengers || 1) + '" inputmode="numeric">' +
      '<button type="button" data-step="1" aria-label="Plus">+</button></div><span class="g-muted">passager(s)</span></div></div>' +

      ((state.data.pilots || []).length ? '<div class="g-frow">' + icon('pilot') + '<div class="g-fcol g-seats"></div></div>' : '') +
      '<div class="g-frow">' + icon('plane') + '<div class="g-fcol"><select name="flight_id">' + flightOptions + '</select></div></div>' +
      '<div class="g-frow">' + icon('money') + '<div class="g-fcol g-inline"><input type="number" name="price" step="0.01" inputmode="decimal" value="' + (b.price || 0) + '"><span class="g-muted">CHF total</span></div></div>' +

      '<div class="g-frow">' + icon('phone') + '<div class="g-fcol"><input type="tel" name="phone" placeholder="Téléphone" value="' + esc(b.phone) + '"></div></div>' +
      '<div class="g-frow">' + icon('mail') + '<div class="g-fcol"><input type="email" name="email" placeholder="E-mail" value="' + esc(b.email) + '"></div></div>' +
      '<div class="g-contact-slot"></div>' +
      '<div class="g-frow">' + icon('scale') + '<div class="g-fcol"><input type="text" name="weights" placeholder="Poids des passagers (kg)" value="' + esc(b.weights) + '"></div></div>' +
      '<div class="g-frow">' + icon('chat') + '<div class="g-fcol"><textarea name="message" rows="2" placeholder="Message du client">' + esc(b.message) + '</textarea></div></div>' +
      '<div class="g-frow">' + icon('note') + '<div class="g-fcol"><textarea name="admin_notes" rows="2" placeholder="Notes internes (paiement, matériel…) – visibles par les pilotes">' + esc(b.admin_notes) + '</textarea></div></div>' +
      '<p class="g-error" hidden></p>' +
      (isNew ? '' : '<button type="button" class="g-delete" data-delete>' + icon('trash') + 'Supprimer la réservation</button>') +
      '</div></form>', 'g-sheet-edit');

    var form = bg.querySelector('form');
    var errEl = form.querySelector('.g-error');
    var slotsEl = form.querySelector('.g-slots');
    var contactEl = form.querySelector('.g-contact-slot');

    function markDirty() { if (state.sheet) state.sheet.dirty = true; }
    function recalc() {
      var opt = form.flight_id.selectedOptions[0];
      if (opt && opt.dataset.price) form.price.value = (parseFloat(opt.dataset.price) || 0) * (parseInt(form.passengers.value, 10) || 0);
    }
    function refreshContact() { contactEl.innerHTML = contactButtons(form.phone.value, form.email.value); }

    // Horaires du jour choisi avec places libres (en excluant cette réservation)
    // ----- Pilotes : un sélecteur par passager, proposition automatique selon l'ordre par défaut
    var seatsEl = form.querySelector('.g-seats');
    var seats = (b.pilots || []).slice();
    var manual = [];                       // places choisies à la main (non recalculées)
    var initialSeats = isNew ? 0 : seats.length;
    var dayData = null;
    function busyPilots() {
      var t = form.time.value, busy = {};
      if (!dayData) return busy;
      dayData.bookings.forEach(function (x) {
        if (x.time === t && x.status !== 'cancelled' && x.id !== b.id) (x.pilots || []).forEach(function (id) { if (id) busy[id] = 'en vol avec ' + x.name; });
      });
      var s0 = mins(t), e0 = s0 + DURATION;
      (dayData.events || []).forEach(function (ev) {
        if (ev.pilot_id && (ev.all_day || (mins(ev.start) < e0 && mins(ev.end) > s0))) busy[ev.pilot_id] = 'absent';
      });
      return busy;
    }
    function suggestSeats() {
      var pax = Math.max(1, parseInt(form.passengers.value, 10) || 1), busy = busyPilots();
      seats.length = Math.min(seats.length, pax);
      var defaults = (state.data.pilots || []).filter(function (p) { return p.active && p.rank > 0; })
        .sort(function (a, c) { return a.rank - c.rank; });
      for (var i = 0; i < pax; i++) {
        var auto = !manual[i] && (isNew || i >= initialSeats);
        if (!auto) { if (seats[i] === undefined) seats[i] = 0; continue; }
        seats[i] = 0;
      }
      for (i = 0; i < pax; i++) {
        if (manual[i] || !(isNew || i >= initialSeats)) continue;
        for (var k = 0; k < defaults.length; k++) {
          var id = defaults[k].id;
          if (!busy[id] && seats.indexOf(id) === -1) { seats[i] = id; break; }
        }
      }
      renderSeats();
    }
    function renderSeats() {
      if (!seatsEl) return;
      var busy = busyPilots(), pilots = state.data.pilots || [];
      seatsEl.innerHTML = seats.map(function (sel, i) {
        return '<label class="g-seat"><span>' + (seats.length > 1 ? 'Pilote ' + (i + 1) : 'Pilote') + '</span><select data-seat="' + i + '"' + (sel ? '' : ' class="tbd"') + '>' +
          '<option value="0">— À définir —</option>' +
          pilots.filter(function (p) { return p.active || p.id === sel; }).map(function (p) {
            var other = seats.indexOf(p.id) !== -1 && p.id !== sel;
            return '<option value="' + p.id + '"' + (p.id === sel ? ' selected' : '') + (other ? ' disabled' : '') + '>' + esc(p.name) +
              (p.rank ? ' (n°' + p.rank + ')' : '') + (busy[p.id] ? ' · ' + esc(busy[p.id]) : '') + '</option>';
          }).join('') + '</select></label>';
      }).join('');
    }
    if (seatsEl) seatsEl.addEventListener('change', function (e) {
      var i = +e.target.getAttribute('data-seat');
      seats[i] = +e.target.value;
      manual[i] = true;
      renderSeats();
    });

    var slotReq = 0;
    function refreshSlots() {
      var date = form.date.value, req = ++slotReq;
      if (!date) { slotsEl.innerHTML = ''; return; }
      slotsEl.innerHTML = '<span class="g-muted">Chargement des horaires…</span>';
      api('calendar', { from: date, to: date }).then(function (data) {
        if (req !== slotReq) return;
        var pax = parseInt(form.passengers.value, 10) || 1;
        var closed = (data.blocked.length ? '<div class="g-warn">Jour fermé : ' + esc(data.blocked[0].reason || 'fermé') + '</div>' : '') +
          (data.events || []).map(function (ev) { return '<div class="g-warn">⛔ ' + esc(ev.title) + ' · ' + esc(eventTime(ev)) + ' · ' + blocksLabel(ev) + '</div>'; }).join('');
        var chips = data.slots.filter(function (s) { return s.active && (!s.until || date <= s.until); }).map(function (s) {
          var st = slotFree(data, date, s.time, s.capacity, b.id);
          var free = st.free;
          var cls = free < pax ? ' full' : '';
          return '<button type="button" class="g-slotchip' + cls + (s.time === form.time.value ? ' on' : '') + '" data-time="' + s.time + '">' +
            '<b>' + s.time + '</b><small>' + (free <= 0 ? 'complet' : free + ' libre' + (free > 1 ? 's' : '')) + '</small></button>';
        }).join('');
        slotsEl.innerHTML = closed + chips;
        dayData = data;
        suggestSeats();
      }).catch(function () { slotsEl.innerHTML = ''; });
    }
    function highlightSlot() {
      slotsEl.querySelectorAll('.g-slotchip').forEach(function (c) { c.classList.toggle('on', c.getAttribute('data-time') === form.time.value); });
      suggestSeats();
    }

    form.addEventListener('input', markDirty);
    form.addEventListener('change', markDirty);
    form.flight_id.addEventListener('change', recalc);
    form.passengers.addEventListener('input', function () { recalc(); refreshSlots(); });
    form.date.addEventListener('change', refreshSlots);
    form.time.addEventListener('change', highlightSlot);
    form.phone.addEventListener('input', refreshContact);
    form.email.addEventListener('input', refreshContact);
    form.addEventListener('click', function (e) {
      var step = e.target.closest('[data-step]');
      if (step) {
        form.passengers.value = Math.max(1, (parseInt(form.passengers.value, 10) || 1) + (+step.getAttribute('data-step')));
        recalc(); refreshSlots(); markDirty();
      }
      var chip = e.target.closest('.g-slotchip');
      if (chip) { form.time.value = chip.getAttribute('data-time'); highlightSlot(); markDirty(); }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      errEl.hidden = true;
      if (!form.name.value.trim()) { errEl.textContent = 'Indiquez le nom du client.'; errEl.hidden = false; form.name.focus(); return; }
      var payload = { id: b.id || 0, flight_name: b.flight_name || '' };
      new FormData(form).forEach(function (v, k) { payload[k] = v; });
      if (seatsEl) payload.pilots = seats.slice(0, Math.max(1, parseInt(form.passengers.value, 10) || 1));
      var btn = form.querySelector('.g-save');
      btn.disabled = true;
      btn.textContent = 'Enregistrement…';
      save(payload).then(function () {
        closeSheet(true);
        toast(isNew ? 'Réservation créée' : 'Réservation enregistrée');
        if (payload.date < range()[0] || payload.date > range()[1]) { state.anchor = payload.date; load(); }
        refreshDays([b.date, payload.date]);
      }).catch(function (err) { errEl.textContent = err.message; errEl.hidden = false; btn.disabled = false; btn.textContent = 'Enregistrer'; });
    });
    var del = form.querySelector('[data-delete]');
    if (del) del.addEventListener('click', function () {
      if (!confirm('Supprimer définitivement la réservation de ' + b.name + ' ?')) return;
      api('admin/booking/' + b.id, null, { method: 'DELETE' }).then(function () {
        closeSheet(true);
        delete db.bookings[b.id]; rebuild(); render();
        toast('Réservation supprimée');
        refreshDays([b.date]);
      }).catch(function (err) { errEl.textContent = err.message; errEl.hidden = false; });
    });

    refreshContact();
    refreshSlots();
    if (isNew) setTimeout(function () { form.name.focus(); }, 50);
  }

  function save(payload) {
    return api('admin/booking', null, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
  }

  function openBooking(b) {
    if (!b) return;
    if (C.canEdit) editBooking(Object.assign({}, b)); else showDetails(b);
  }

  function newBooking(date, time) {
    var s = activeSlots()[0];
    editBooking({ date: date || (state.view === 'day' ? state.anchor : C.today), time: time || (s ? s.time : '10:00'), status: 'confirmed', passengers: 1 });
  }

  // ---------- Événements : détail, création, modification ----------
  function openCreate(date, time) {
    var bg = openSheet('<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button>' +
      '<span class="g-sheet-title">Créer</span></div><div class="g-sheet-body g-choices">' +
      '<button type="button" data-choice="booking">' + icon('people') + '<span><b>Réservation</b><small>Un client, un ou plusieurs passagers</small></span></button>' +
      '<button type="button" data-choice="event">' + icon('block') + '<span><b>Événement / blocage</b><small>Météo, compétition, pilote absent, groupe privé… Bloque les places libres</small></span></button>' +
      '</div>', 'g-sheet-choice');
    bg.addEventListener('click', function (e) {
      var c = e.target.closest('[data-choice]');
      if (!c) return;
      closeSheet(true);
      if (c.getAttribute('data-choice') === 'booking') newBooking(date, time); else newEvent(date, time);
    });
  }

  function newEvent(date, time) {
    var start = time || '08:00';
    var end = hhmm(Math.min(18 * 60, mins(start) + 120));
    if (end <= start) end = hhmm(mins(start) + 15);
    editEvent({ date: date || (state.view === 'day' ? state.anchor : C.today), all_day: time ? 0 : 1, start: start, end: end, title: '', note: '', blocks: 0 });
  }

  function showEvent(ev) {
    openSheet('<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button></div>' +
      '<div class="g-sheet-body g-details"><div class="g-dtitle"><span class="g-square g-square-evt"></span><div><h2>' + esc(ev.title) + '</h2>' +
      '<p>' + esc(longDate(ev.date)) + ' · ' + esc(eventTime(ev)) + '</p></div></div>' +
      row('block', esc(blocksLabel(ev))) + row('note', esc(ev.note)) + '</div>', 'g-sheet-details');
  }

  function openEvent(ev) {
    if (!ev) return;
    if (C.canEdit) editEvent(Object.assign({}, ev));
    else if (isPilot && ev.pilot_id === C.me.id) showMyAbsence(ev);
    else showEvent(ev);
  }

  // ---------- Absences (lien personnel d'un pilote) ----------
  function showMyAbsence(ev) {
    var bg = openSheet('<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button></div>' +
      '<div class="g-sheet-body g-details"><div class="g-dtitle"><span class="g-square g-square-abs"></span><div><h2>Ton absence</h2>' +
      '<p>' + esc(longDate(ev.date)) + ' · ' + esc(eventTime(ev)) + '</p></div></div>' +
      row('note', esc(ev.note)) +
      '<p class="g-error" hidden></p>' +
      '<button type="button" class="g-delete" data-del-abs>' + icon('trash') + 'Supprimer cette absence</button></div>', 'g-sheet-details');
    bg.querySelector('[data-del-abs]').addEventListener('click', function () {
      if (!confirm('Supprimer ton absence du ' + longDate(ev.date) + ' ?')) return;
      api('pilot/absence/' + ev.id, null, { method: 'DELETE' }).then(function () {
        closeSheet(true); delete db.events[ev.id]; rebuild(); render();
        toast('Absence supprimée');
        refreshDays([ev.date]);
      }).catch(function (err) { var e = bg.querySelector('.g-error'); e.textContent = err.message; e.hidden = false; });
    });
  }

  function declareAbsence(date) {
    date = date && date >= C.today ? date : (state.anchor >= C.today ? state.anchor : C.today);
    var bg = openSheet(
      '<form class="g-form" novalidate>' +
      '<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button>' +
      '<span class="g-sheet-title">Déclarer une absence</span>' +
      '<button type="submit" class="g-save">Enregistrer</button></div>' +
      '<div class="g-sheet-body">' +
      '<p class="g-abs-intro">' + icon('block') + '<span>Tu ne seras plus proposé sur des vols pendant ton absence, et l\'administrateur est prévenu automatiquement.</span></p>' +
      '<div class="g-frow">' + icon('cal') + '<div class="g-fcol g-inline"><label class="g-lbl">Du<input type="date" name="date" min="' + C.today + '" value="' + date + '" required></label>' +
      '<label class="g-lbl">Au<input type="date" name="to" min="' + C.today + '" value="' + date + '" required></label></div></div>' +
      '<div class="g-frow">' + icon('clock') + '<div class="g-fcol">' +
      '<label class="g-switch"><input type="checkbox" name="all_day" checked><span>Toute la journée</span></label>' +
      '<div class="g-inline g-times" hidden><select name="start" class="g-timesel">' + timeOptions('08:00') + '</select>' +
      '<span class="g-muted">à</span><select name="end" class="g-timesel">' + timeOptions('12:00') + '</select></div></div></div>' +
      '<div class="g-frow">' + icon('note') + '<div class="g-fcol"><textarea name="note" rows="2" placeholder="Motif (facultatif)"></textarea></div></div>' +
      '<p class="g-error" hidden></p></div></form>', 'g-sheet-edit g-sheet-abs');
    var form = bg.querySelector('form'), errEl = form.querySelector('.g-error');
    form.addEventListener('change', function (e) {
      if (state.sheet) state.sheet.dirty = true;
      form.querySelector('.g-times').hidden = form.all_day.checked;
      if (e.target.name === 'date' && form.to.value < form.date.value) form.to.value = form.date.value;
      if (e.target.name === 'start' && form.end.value <= form.start.value) form.end.value = hhmm(Math.min(18 * 60, mins(form.start.value) + 60));
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      errEl.hidden = true;
      var payload = { date: form.date.value, to: form.to.value || form.date.value, all_day: form.all_day.checked ? 1 : 0,
        start: form.start.value, end: form.end.value, note: form.note.value };
      if (!payload.date) { errEl.textContent = 'Choisis une date.'; errEl.hidden = false; return; }
      if (payload.to < payload.date) { errEl.textContent = 'La date de fin doit être après la date de début.'; errEl.hidden = false; return; }
      if (!payload.all_day && payload.end <= payload.start) { errEl.textContent = "L'heure de fin doit être après l'heure de début."; errEl.hidden = false; return; }
      var btn = form.querySelector('.g-save');
      btn.disabled = true; btn.textContent = 'Envoi…';
      api('pilot/absence', null, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }).then(function (res) {
        closeSheet(true);
        refreshDays([payload.date, payload.to]);
        if (res.conflicts && res.conflicts.length) {
          openSheet('<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button>' +
            '<span class="g-sheet-title">Absence enregistrée</span></div><div class="g-sheet-body"><div class="g-kept"><b>Attention, tu es prévu sur ' +
            (res.conflicts.length > 1 ? 'ces vols' : 'ce vol') + ' :</b>' + res.conflicts.map(function (c) {
              return '<span>' + esc(longDate(c.date)) + ' à ' + esc(c.time) + ' · ' + esc(c.name) + ' (' + c.passengers + ' pax)</span>';
            }).join('') + '<small>L\'administrateur a été prévenu et va te remplacer.</small></div></div>', 'g-sheet-details');
        } else {
          toast('Absence enregistrée — l\'administrateur est prévenu');
        }
      }).catch(function (err) { errEl.textContent = err.message; errEl.hidden = false; btn.disabled = false; btn.textContent = 'Enregistrer'; });
    });
  }

  var PRESETS = ['Météo', 'Compétition', 'Pilote absent', 'Groupe privé', 'Fermeture'];

  function editEvent(ev) {
    var isNew = !ev.id;
    var bg = openSheet(
      '<form class="g-form" novalidate>' +
      '<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button>' +
      '<span class="g-sheet-title">' + (isNew ? 'Nouvel événement' : 'Événement') + '</span>' +
      '<button type="submit" class="g-save">Enregistrer</button></div>' +
      '<div class="g-sheet-body">' +
      '<input class="g-name" name="title" placeholder="Titre (ex. Météo, Compétition)" autocomplete="off" value="' + esc(ev.title) + '" required>' +
      '<div class="g-presets">' + PRESETS.map(function (t) { return '<button type="button" data-preset="' + esc(t) + '">' + esc(t) + '</button>'; }).join('') + '</div>' +
      '<div class="g-frow">' + icon('clock') + '<div class="g-fcol">' +
      '<div class="g-inline"><input type="date" name="date" value="' + esc(ev.date) + '" required>' +
      '<label class="g-switch"><input type="checkbox" name="all_day"' + (ev.all_day ? ' checked' : '') + '><span>Toute la journée</span></label></div>' +
      '<div class="g-inline g-times"' + (ev.all_day ? ' hidden' : '') + '><select name="start" class="g-timesel">' + timeOptions(ev.start) + '</select>' +
      '<span class="g-muted">à</span><select name="end" class="g-timesel">' + timeOptions(ev.end) + '</select></div></div></div>' +
      '<div class="g-frow">' + icon('block') + '<div class="g-fcol">' +
      '<div class="g-blockmode"><label><input type="radio" name="mode" value="all"' + (ev.blocks > 0 ? '' : ' checked') + '><span>Toutes les places libres</span></label>' +
      '<label><input type="radio" name="mode" value="some"' + (ev.blocks > 0 ? ' checked' : '') + '><span>Un nombre de places</span></label></div>' +
      '<div class="g-inline g-blockcount"' + (ev.blocks > 0 ? '' : ' hidden') + '><div class="g-stepper"><button type="button" data-bstep="-1" aria-label="Moins">−</button>' +
      '<input type="number" name="blocks" min="1" max="30" value="' + (ev.blocks > 0 ? ev.blocks : 1) + '" inputmode="numeric">' +
      '<button type="button" data-bstep="1" aria-label="Plus">+</button></div><span class="g-muted">place(s) par créneau (ex. pilotes absents)</span></div></div></div>' +
      ((state.data.pilots || []).length ? '<div class="g-frow">' + icon('pilot') + '<div class="g-fcol"><select name="pilot_id"><option value="0">— Aucun pilote (événement général)</option>' +
        state.data.pilots.map(function (p) {
          return '<option value="' + p.id + '"' + (p.id === ev.pilot_id ? ' selected' : '') + '>Absence de ' + esc(p.name) + '</option>';
        }).join('') + '</select></div></div>' : '') +
      '<div class="g-frow">' + icon('note') + '<div class="g-fcol"><textarea name="note" rows="2" placeholder="Note (visible par les pilotes)">' + esc(ev.note) + '</textarea></div></div>' +
      '<div class="g-impact"></div>' +
      '<p class="g-error" hidden></p>' +
      (isNew ? '' : '<button type="button" class="g-delete" data-delete>' + icon('trash') + 'Supprimer l\'événement</button>') +
      '</div></form>', 'g-sheet-edit');

    var form = bg.querySelector('form'), errEl = form.querySelector('.g-error'), impactEl = form.querySelector('.g-impact');
    var dayData = null, req = 0;
    function current() {
      var some = form.querySelector('input[name=mode]:checked').value === 'some';
      return {
        id: ev.id || 0, title: form.title.value.trim(), date: form.date.value, all_day: form.all_day.checked ? 1 : 0,
        start: form.start.value, end: form.end.value, note: form.note.value, blocks: some ? Math.max(1, parseInt(form.blocks.value, 10) || 1) : 0,
        pilot_id: form.pilot_id ? +form.pilot_id.value : (ev.pilot_id || 0)
      };
    }
    // Aperçu : créneaux concernés et réservations maintenues
    function impact() {
      if (!dayData) { impactEl.innerHTML = ''; return; }
      var cur = current();
      var others = Object.assign({}, dayData, { events: (dayData.events || []).filter(function (x) { return x.id !== ev.id; }) });
      var withThis = Object.assign({}, others, { events: others.events.concat([cur]) });
      var rows = [], kept = [];
      dayData.slots.filter(function (s) { return s.active; }).forEach(function (s) {
        var before = slotFree(others, cur.date, s.time, s.capacity), after = slotFree(withThis, cur.date, s.time, s.capacity);
        if (after.free !== before.free || after.blocked !== before.blocked) {
          rows.push('<li><b>' + s.time + '</b> ' + before.free + ' libre' + (before.free > 1 ? 's' : '') + ' → <b>' + after.free + '</b></li>');
          dayData.bookings.forEach(function (x) { if (x.time === s.time && x.status !== 'cancelled') kept.push(x); });
        }
      });
      impactEl.innerHTML = rows.length ? '<h4>Effet sur les réservations en ligne</h4><ul>' + rows.join('') + '</ul>' +
        (kept.length ? '<div class="g-kept"><b>Réservations déjà prises, maintenues :</b>' + kept.map(function (x) {
          return '<button type="button" data-open-booking="' + x.id + '">' + esc(x.time) + ' · ' + esc(x.name) + ' · ' + x.passengers + ' pax</button>';
        }).join('') + '<small>À déplacer ou annuler si nécessaire.</small></div>' : '') : '<p class="g-muted">Aucun créneau réservable concerné.</p>';
    }
    function loadDay() {
      var date = form.date.value, my = ++req;
      if (!date) return;
      api('calendar', { from: date, to: date }).then(function (data) { if (my === req) { dayData = data; impact(); } }).catch(function () {});
    }
    function markDirty() { if (state.sheet) state.sheet.dirty = true; }
    form.addEventListener('input', function () { markDirty(); impact(); });
    form.addEventListener('change', function (e) {
      markDirty();
      if (e.target.name === 'date') loadDay();
      form.querySelector('.g-times').hidden = form.all_day.checked;
      form.querySelector('.g-blockcount').hidden = form.querySelector('input[name=mode]:checked').value !== 'some';
      if (e.target.name === 'start' && form.end.value <= form.start.value) form.end.value = hhmm(Math.min(18 * 60, mins(form.start.value) + 60));
      impact();
    });
    form.addEventListener('click', function (e) {
      var pr = e.target.closest('[data-preset]');
      if (pr) { form.title.value = pr.getAttribute('data-preset'); markDirty(); }
      var st = e.target.closest('[data-bstep]');
      if (st) { form.blocks.value = Math.max(1, (parseInt(form.blocks.value, 10) || 1) + (+st.getAttribute('data-bstep'))); markDirty(); impact(); }
      var ob = e.target.closest('[data-open-booking]');
      if (ob && dayData) {
        var bk = dayData.bookings.filter(function (x) { return x.id === +ob.getAttribute('data-open-booking'); })[0];
        if (bk && (!state.sheet.dirty || confirm('Quitter sans enregistrer l\'événement ?'))) { closeSheet(true); openBooking(bk); }
      }
    });
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var cur = current();
      errEl.hidden = true;
      if (!cur.title) { errEl.textContent = 'Indiquez un titre.'; errEl.hidden = false; form.title.focus(); return; }
      if (!cur.all_day && cur.end <= cur.start) { errEl.textContent = "L'heure de fin doit être après l'heure de début."; errEl.hidden = false; return; }
      var btn = form.querySelector('.g-save');
      btn.disabled = true; btn.textContent = 'Enregistrement…';
      api('admin/event', null, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(cur) }).then(function () {
        closeSheet(true);
        toast(isNew ? 'Événement créé — places bloquées' : 'Événement enregistré');
        if (cur.date < range()[0] || cur.date > range()[1]) { state.anchor = cur.date; load(); }
        refreshDays([ev.date, cur.date]);
      }).catch(function (err) { errEl.textContent = err.message; errEl.hidden = false; btn.disabled = false; btn.textContent = 'Enregistrer'; });
    });
    var del = form.querySelector('[data-delete]');
    if (del) del.addEventListener('click', function () {
      if (!confirm('Supprimer l\'événement « ' + ev.title + ' » ? Les places redeviennent réservables.')) return;
      api('admin/event/' + ev.id, null, { method: 'DELETE' }).then(function () {
        closeSheet(true); delete db.events[ev.id]; rebuild(); render();
        toast('Événement supprimé — places à nouveau libres');
        refreshDays([ev.date]);
      }).catch(function (err) { errEl.textContent = err.message; errEl.hidden = false; });
    });
    loadDay();
    if (isNew) setTimeout(function () { form.title.focus(); }, 50);
  }

  // ---------- Notifications sur le téléphone (pilotes) ----------
  function b64ToBytes(b64) {
    var s = atob(b64.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((b64.length + 3) % 4));
    var out = new Uint8Array(s.length);
    for (var i = 0; i < s.length; i++) out[i] = s.charCodeAt(i);
    return out;
  }
  function pushSupported() { return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window; }
  function isIOS() { return /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1); }
  function isStandalone() { return window.matchMedia('(display-mode: standalone)').matches || navigator.standalone === true; }
  function getRegistration() {
    return navigator.serviceWorker.getRegistration(C.push.scope).then(function (reg) {
      return reg || navigator.serviceWorker.register(C.push.sw, { scope: C.push.scope });
    });
  }
  function activeRegistration() {
    return getRegistration().then(function (reg) {
      if (reg.active) return reg;
      return new Promise(function (resolve) {
        var w = reg.installing || reg.waiting;
        if (!w) return resolve(reg);
        w.addEventListener('statechange', function () { if (w.state === 'activated') resolve(reg); });
      });
    });
  }
  function currentSubscription() {
    if (!C.push || !pushSupported()) return Promise.resolve(null);
    return navigator.serviceWorker.getRegistration(C.push.scope).then(function (reg) {
      return reg ? reg.pushManager.getSubscription() : null;
    }).catch(function () { return null; });
  }
  function subscribePush() {
    return Notification.requestPermission().then(function (perm) {
      if (perm !== 'granted') throw new Error('Autorisation refusée. Active les notifications pour ce site dans les réglages du téléphone.');
      return activeRegistration();
    }).then(function (reg) {
      return reg.pushManager.getSubscription().then(function (sub) {
        return sub || reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: b64ToBytes(C.push.key) });
      });
    }).then(function (sub) {
      return api('pilot/push', null, { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(sub.toJSON()) });
    });
  }
  function unsubscribePush() {
    return currentSubscription().then(function (sub) {
      if (!sub) return;
      var endpoint = sub.endpoint;
      return sub.unsubscribe().then(function () {
        return api('pilot/push', null, { method: 'DELETE', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ endpoint: endpoint }) });
      });
    });
  }

  function openPush() {
    var head = '<div class="g-sheet-bar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('close') + '</button>' +
      '<span class="g-sheet-title">Notifications</span></div><div class="g-sheet-body g-push">';
    var what = '<p class="g-muted">Tu reçois une notification sur ce téléphone quand un vol t\'est <b>attribué</b>, <b>retiré</b>, <b>déplacé</b> ou <b>annulé</b>.</p>';
    if (!pushSupported()) {
      var help = isIOS() && !isStandalone()
        ? '<ol class="g-steps"><li>Touche le bouton <b>Partager</b> (carré avec une flèche) de Safari.</li><li>Choisis <b>« Sur l\'écran d\'accueil »</b>.</li>' +
          '<li>Ouvre le planning depuis la nouvelle icône, puis touche 🔔 à nouveau.</li></ol><p class="g-muted">Sur iPhone, les notifications fonctionnent quand le planning est ajouté à l\'écran d\'accueil (iOS 16.4 ou plus récent).</p>'
        : '<p>Ce navigateur ne permet pas les notifications. Utilise Chrome, Firefox, Edge ou Safari à jour.</p>';
      return openSheet(head + what + help + '</div>', 'g-sheet-details');
    }
    var bg = openSheet(head + what + '<div class="g-push-state"><p class="g-muted">Vérification…</p></div><p class="g-error" hidden></p></div>', 'g-sheet-details');
    var box = bg.querySelector('.g-push-state'), errEl = bg.querySelector('.g-error');
    function fail(err) { errEl.textContent = err.message || String(err); errEl.hidden = false; }
    function show() {
      currentSubscription().then(function (sub) {
        state.pushOn = !!sub;
        render();
        if (Notification.permission === 'denied') {
          box.innerHTML = '<p>Les notifications sont <b>bloquées</b> pour ce site. Réactive-les dans les réglages du navigateur ou du téléphone, puis reviens ici.</p>';
        } else if (sub) {
          box.innerHTML = '<p class="g-push-ok">' + icon('bell') + ' Activées sur ce téléphone</p>' +
            '<div class="g-push-actions"><button type="button" class="g-save" data-push-test>Envoyer un test</button>' +
            '<button type="button" class="g-chipbtn" data-push-off>Désactiver</button></div>';
        } else {
          box.innerHTML = '<button type="button" class="g-save g-push-on" data-push-on>' + icon('bell') + ' Activer les notifications</button>';
        }
      });
    }
    bg.addEventListener('click', function (e) {
      var b = e.target.closest('[data-push-on], [data-push-off], [data-push-test]');
      if (!b) return;
      errEl.hidden = true;
      b.disabled = true;
      if (b.hasAttribute('data-push-on')) {
        subscribePush().then(function () {
          show();
          return api('pilot/push-test', null, { method: 'POST' }).catch(function () {});
        }).catch(function (err) { b.disabled = false; fail(err); });
      } else if (b.hasAttribute('data-push-off')) {
        unsubscribePush().then(function () { show(); toast('Notifications désactivées'); }).catch(function (err) { b.disabled = false; fail(err); });
      } else {
        api('pilot/push-test', null, { method: 'POST' }).then(function () { b.disabled = false; toast('Notification de test envoyée'); })
          .catch(function (err) { b.disabled = false; fail(err); });
      }
    });
    show();
  }

  // ---------- Recherche ----------
  function openSearch() {
    var bg = openSheet('<div class="g-sheet-bar g-searchbar"><button type="button" class="g-icon-btn" data-close aria-label="Fermer">' + icon('back') + '</button>' +
      '<input type="search" class="g-search-input" placeholder="Nom, téléphone ou référence" autocomplete="off" enterkeyhint="search"></div>' +
      '<div class="g-sheet-body g-results"><p class="g-muted">Tapez au moins 2 caractères.</p></div>', 'g-sheet-search');
    var input = bg.querySelector('input'), out = bg.querySelector('.g-results'), timer, req = 0;
    input.focus();
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(function () {
        var q = input.value.trim(), my = ++req;
        if (q.length < 2) { out.innerHTML = '<p class="g-muted">Tapez au moins 2 caractères.</p>'; return; }
        api('search', { q: q }).then(function (data) {
          if (my !== req) return;
          if (!data.bookings.length) { out.innerHTML = '<p class="g-muted">Aucune réservation trouvée.</p>'; return; }
          state.searchResults = data.bookings;
          out.innerHTML = data.bookings.map(function (b, i) {
            return '<button type="button" class="g-result" data-result="' + i + '"><i class="g-dot st-' + esc(b.status) + '"></i>' +
              '<span><b>' + esc(b.name) + '</b><small>' + esc(longDate(b.date)) + ' · ' + esc(b.time) + ' · ' + b.passengers + ' pax · ' + esc(b.phone) + '</small></span></button>';
          }).join('');
        }).catch(function (err) { out.innerHTML = '<p class="g-error">' + esc(err.message) + '</p>'; });
      }, 250);
    });
    out.addEventListener('click', function (e) {
      var r = e.target.closest('[data-result]');
      if (!r) return;
      var b = state.searchResults[+r.getAttribute('data-result')];
      closeSheet(true);
      state.anchor = b.date;
      load().then(function () { openBooking(findBooking(b.id) || b); });
    });
  }

  // ---------- Navigation ----------
  function shiftedAnchor(n) {
    if (n === 0) return C.today;
    if (state.view === 'day') return addDays(state.anchor, n);
    if (state.view === 'week') return addDays(state.anchor, 7 * n);
    if (state.view === 'four') return addDays(state.anchor, 4 * n);
    if (state.view === 'list') return addDays(state.anchor, 30 * n);
    var d = toDate(state.anchor);
    return toStr(new Date(d.getFullYear(), d.getMonth() + n, 1, 12));
  }
  function shift(n) {
    var next = shiftedAnchor(n);
    state.anim = next > state.anchor ? 'next' : next < state.anchor ? 'prev' : '';
    state.anchor = next;
    load();
  }
  function setView(v) { state.view = v; store('view', v); load(); }
  function closeMenus() { root.querySelectorAll('.g-menu').forEach(function (m) { m.hidden = true; }); }

  root.addEventListener('click', function (e) {
    var t = e.target;
    if (!t.closest('.g-menu-wrap')) closeMenus();
    var el;
    if ((el = t.closest('[data-menu]'))) { var m = el.nextElementSibling; m.hidden = !m.hidden; return; }
    if ((el = t.closest('[data-view]'))) { closeMenus(); return setView(el.getAttribute('data-view')); }
    if ((el = t.closest('[data-nav]'))) return shift(+el.getAttribute('data-nav'));
    if ((el = t.closest('[data-mine]'))) { state.mine = el.getAttribute('data-mine') === '1'; store('mine', state.mine ? '1' : '0'); return render(); }
    if ((el = t.closest('[data-mini]'))) {
      var d = toDate(state.anchor);
      state.anchor = toStr(new Date(d.getFullYear(), d.getMonth() + (+el.getAttribute('data-mini')), 1, 12));
      return load();
    }
    if (t.closest('[data-toggle-sidebar]')) {
      state.sidebar = !state.sidebar;
      if (!mq.matches) store('sidebar', state.sidebar ? '1' : '0');
      return state.sidebar ? load() : render();
    }
    if (t.closest('[data-search]')) return openSearch();
    if (t.closest('[data-new]')) return newBooking();
    if (t.closest('[data-create]')) return openCreate();
    if (t.closest('[data-push]')) return openPush();
    if (t.closest('[data-absence]')) return declareAbsence(state.view === 'day' || state.view === 'four' ? state.anchor : C.today);
    if ((el = t.closest('[data-free-time]'))) return newBooking(el.getAttribute('data-free-date'), el.getAttribute('data-free-time'));
    if ((el = t.closest('[data-event]'))) return openEvent(findEvent(+el.getAttribute('data-event')));
    if (t.closest('[data-list-more]')) { state.anchor = addDays(state.anchor, 30); return load(); }
    if ((el = t.closest('[data-goto]'))) {
      state.anchor = el.getAttribute('data-goto');
      if (el.hasAttribute('data-to-day')) { state.view = 'day'; store('view', 'day'); }
      if (mq.matches) state.sidebar = false;
      return load();
    }
    if ((el = t.closest('[data-id]')) && !t.closest('a')) return openBooking(findBooking(+el.getAttribute('data-id')));
    // Clic dans une zone vide de la grille : nouvelle réservation à cette heure
    if (C.canEdit && (el = t.closest('.g-col'))) return newBooking(el.getAttribute('data-date'), timeAt(el, e.clientY));
    if ((el = t.closest('.g-mday'))) {
      if (C.canEdit) return newBooking(el.getAttribute('data-date'));
      state.anchor = el.getAttribute('data-date'); state.view = 'day'; store('view', 'day'); return load();
    }
  });

  root.addEventListener('keydown', function (e) {
    var el = e.target.closest && e.target.closest('[data-id], [data-event]');
    if (el && (e.key === 'Enter' || e.key === ' ')) {
      e.preventDefault();
      if (el.hasAttribute('data-event')) openEvent(findEvent(+el.getAttribute('data-event')));
      else openBooking(findBooking(+el.getAttribute('data-id')));
    }
  });

  root.addEventListener('change', function (e) {
    if (e.target.hasAttribute('data-datepick') && e.target.value) { state.anchor = e.target.value; return load(); }
    if (e.target.hasAttribute('data-cancelled')) {
      state.showCancelled = e.target.checked;
      store('cancelled', state.showCancelled ? '1' : '0');
      render();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && state.sheet) return closeSheet();
    if (state.sheet || /INPUT|TEXTAREA|SELECT/.test((e.target.tagName || '')) || e.metaKey || e.ctrlKey || e.altKey) return;
    var k = e.key.toLowerCase();
    if (k === 'j') setView('day');
    else if (k === '4' || k === 'x') setView('four');
    else if (k === 's') setView('week');
    else if (k === 'm') setView('month');
    else if (k === 'p') setView('list');
    else if (k === 't') shift(0);
    else if (k === 'arrowleft') shift(-1);
    else if (k === 'arrowright') shift(1);
    else if (k === '/') { e.preventDefault(); openSearch(); }
    else if (k === 'c' && C.canEdit) newBooking();
    else if (k === 'e' && C.canEdit) newEvent();
  });

  // Déplacement immédiat à l'écran, enregistrement en arrière-plan, avec « Annuler »
  function moveBooking(b, date, time) {
    var old = { date: b.date, time: b.time };
    b.date = date; b.time = time; render();
    save(Object.assign({}, b)).then(function () {
      toast(b.name + ' → ' + longDate(date) + ' à ' + time, 'Annuler', function () {
        b.date = old.date; b.time = old.time; render();
        save(Object.assign({}, b)).then(function () { refreshDays([old.date, date]); });
      });
      refreshDays([old.date, date]);
    }).catch(function (err) {
      b.date = old.date; b.time = old.time; render();
      toast('Déplacement impossible : ' + err.message);
    });
  }

  // Heure correspondant à une position verticale, arrondie au créneau le plus proche
  function timeAt(col, clientY, offset) {
    var hr = hourRange(), H = hourHeight();
    var y = clientY - col.getBoundingClientRect().top - (offset || 0);
    var m = Math.round((hr[0] + y / H) * 60);
    var slots = activeSlots();
    if (slots.length) {
      var best = slots[0].time;
      slots.forEach(function (s) { if (Math.abs(mins(s.time) - m) < Math.abs(mins(best) - m)) best = s.time; });
      return best;
    }
    return hhmm(Math.max(0, Math.min(23 * 60 + 45, Math.round(m / 15) * 15)));
  }

  // ---------- Glisser-déposer (administrateur, ordinateur) ----------
  if (C.canEdit) {
    root.addEventListener('dragstart', function (e) {
      var el = e.target.closest && e.target.closest('.g-ev[draggable]');
      if (!el) return;
      state.dragging = true;
      state.drag = { id: +el.getAttribute('data-id'), offset: e.clientY - el.getBoundingClientRect().top };
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', el.getAttribute('data-id'));
      setTimeout(function () { el.classList.add('dragging'); }, 0);
    });
    root.addEventListener('dragend', function () {
      state.dragging = false;
      root.querySelectorAll('.dragging, .drop-hint').forEach(function (x) {
        if (x.classList.contains('drop-hint')) x.parentNode.removeChild(x); else x.classList.remove('dragging');
      });
    });
    root.addEventListener('dragover', function (e) {
      var col = e.target.closest && e.target.closest('.g-col');
      if (!col || !state.drag) return;
      e.preventDefault();
      var time = timeAt(col, e.clientY, state.drag.offset), hr = hourRange();
      var hint = root.querySelector('.drop-hint');
      if (!hint) { hint = document.createElement('div'); hint.className = 'drop-hint'; }
      if (hint.parentNode !== col) col.appendChild(hint);
      hint.style.top = ((mins(time) / 60 - hr[0]) * hourHeight()) + 'px';
      hint.style.height = (DURATION / 60 * hourHeight() - 3) + 'px';
      hint.textContent = time;
    });
    root.addEventListener('drop', function (e) {
      var col = e.target.closest && e.target.closest('.g-col');
      if (!col || !state.drag) return;
      e.preventDefault();
      var b = findBooking(state.drag.id), date = col.getAttribute('data-date'), time = timeAt(col, e.clientY, state.drag.offset);
      state.dragging = false;
      state.drag = null;
      if (!b || (b.date === date && b.time === time)) return render();
      moveBooking(b, date, time);
    });
  }

  // Balayage horizontal (téléphone) : jour / semaine / mois précédent ou suivant
  var touch = null;
  root.addEventListener('touchstart', function (e) {
    if (e.touches.length !== 1 || !e.target.closest('.g-content')) { touch = null; return; }
    touch = { x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now() };
  }, { passive: true });
  root.addEventListener('touchend', function (e) {
    if (!touch || state.sheet) return;
    var dx = e.changedTouches[0].clientX - touch.x, dy = e.changedTouches[0].clientY - touch.y;
    var fast = Date.now() - touch.t < 600;
    touch = null;
    if (fast && Math.abs(dx) > 60 && Math.abs(dx) > Math.abs(dy) * 1.5 && state.view !== 'list') shift(dx < 0 ? 1 : -1);
  }, { passive: true });

  // Ligne « maintenant » et nouvelles réservations : actualisation régulière
  setInterval(function () {
    if (state.sheet || state.dragging || document.hidden || !state.data) return;
    var r = neededRange();
    fetchRange(r[0], r[1], true).catch(function () {});
  }, 45000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && state.data && !state.sheet) { var r = neededRange(); fetchRange(r[0], r[1], true).catch(function () {}); }
  });
  mq.addEventListener && mq.addEventListener('change', function () { state.sidebar = !mq.matches && store('sidebar') !== '0'; render(); });

  load();
  if (C.push) currentSubscription().then(function (sub) { if (!!sub !== state.pushOn) { state.pushOn = !!sub; if (state.data) render(); } });
})();
