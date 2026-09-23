(function () {
  var C = window.fvrCalendar;
  var root = document.getElementById('fvr-calendar');
  if (!root || !C) return;

  var DAYS = ['dim.', 'lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.'];
  var DAYS_LONG = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
  var MONTHS = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août',
    'septembre', 'octobre', 'novembre', 'décembre'];

  function store(key, value) {
    try {
      if (value === undefined) return localStorage.getItem('fvr_cal_' + key);
      localStorage.setItem('fvr_cal_' + key, value);
    } catch (e) { return null; }
  }

  var state = {
    view: store('view') || (window.innerWidth < 760 ? 'day' : 'week'),
    anchor: C.today,
    showCancelled: store('cancelled') === '1',
    data: null,
    modalOpen: false,
    dragging: false
  };

  // ---------- Dates (chaînes AAAA-MM-JJ, sans décalage horaire) ----------
  function pad(n) { return (n < 10 ? '0' : '') + n; }
  function toDate(s) { var p = s.split('-'); return new Date(+p[0], +p[1] - 1, +p[2], 12); }
  function toStr(d) { return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function addDays(s, n) { var d = toDate(s); d.setDate(d.getDate() + n); return toStr(d); }
  function startOfWeek(s) { var d = toDate(s); return addDays(s, -((d.getDay() + 6) % 7)); }
  function longDate(s) { var d = toDate(s); return DAYS_LONG[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS[d.getMonth()] + ' ' + d.getFullYear(); }

  function range() {
    if (state.view === 'day') return [state.anchor, state.anchor];
    if (state.view === 'week') { var s = startOfWeek(state.anchor); return [s, addDays(s, 6)]; }
    var d = toDate(state.anchor);
    var first = toStr(new Date(d.getFullYear(), d.getMonth(), 1, 12));
    var last = toStr(new Date(d.getFullYear(), d.getMonth() + 1, 0, 12));
    return [startOfWeek(first), addDays(startOfWeek(last), 6)];
  }

  function title() {
    var r = range(), a = toDate(r[0]), b = toDate(r[1]);
    if (state.view === 'day') return longDate(state.anchor);
    if (state.view === 'month') { var m = toDate(state.anchor); return MONTHS[m.getMonth()] + ' ' + m.getFullYear(); }
    if (a.getMonth() === b.getMonth()) return a.getDate() + ' – ' + b.getDate() + ' ' + MONTHS[b.getMonth()] + ' ' + b.getFullYear();
    return a.getDate() + ' ' + MONTHS[a.getMonth()] + ' – ' + b.getDate() + ' ' + MONTHS[b.getMonth()] + ' ' + b.getFullYear();
  }

  // ---------- Utilitaires ----------
  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function chf(n) { return 'CHF ' + Math.round(Number(n) || 0).toString().replace(/\B(?=(\d{3})+(?!\d))/g, "'"); }
  function tel(p) { return String(p || '').replace(/[^0-9+]/g, ''); }

  function api(path, params, options) {
    var url = C.api + path;
    var qs = params ? new URLSearchParams(params).toString() : '';
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

  // ---------- Chargement ----------
  function load() {
    var r = range();
    var params = { from: r[0], to: r[1] };
    if (C.token) params.token = C.token;
    return api('calendar', params).then(function (data) {
      state.data = data;
      render();
    }).catch(function (err) {
      root.innerHTML = '<p class="fvr-cal-error">Impossible de charger le calendrier : ' + esc(err.message) + '</p>';
    });
  }

  function visibleBookings() {
    return state.data.bookings.filter(function (b) { return state.showCancelled || b.status !== 'cancelled'; });
  }

  function byDateTime() {
    var map = {};
    visibleBookings().forEach(function (b) {
      var k = b.date + ' ' + b.time;
      (map[k] = map[k] || []).push(b);
    });
    return map;
  }

  function blockedMap() {
    var m = {};
    state.data.blocked.forEach(function (x) { m[x.date] = x.reason || 'Fermé'; });
    return m;
  }

  function capacityMap() {
    var m = {};
    state.data.slots.forEach(function (s) { if (s.active) m[s.time] = s.capacity; });
    return m;
  }

  // Horaires affichés : créneaux actifs + heures des réservations de la période
  function times() {
    var set = {};
    state.data.slots.forEach(function (s) { if (s.active) set[s.time] = 1; });
    visibleBookings().forEach(function (b) { set[b.time] = 1; });
    return Object.keys(set).sort();
  }

  function paxOf(list) {
    return (list || []).reduce(function (n, b) { return n + (b.status === 'cancelled' ? 0 : b.passengers); }, 0);
  }

  function loadBadge(pax, cap) {
    if (cap == null) return pax ? '<span class="fvr-load">' + pax + ' pax</span>' : '';
    var cls = pax > cap ? ' over' : pax === cap ? ' full' : pax ? ' some' : '';
    return '<span class="fvr-load' + cls + '">' + pax + '/' + cap + '</span>';
  }

  // ---------- Rendu ----------
  function render() {
    var r = range();
    var html = '<div class="fvr-cal-toolbar">' +
      '<div class="fvr-cal-nav">' +
      '<button type="button" data-nav="-1" aria-label="Précédent">‹</button>' +
      '<button type="button" data-nav="0">Aujourd\'hui</button>' +
      '<button type="button" data-nav="1" aria-label="Suivant">›</button>' +
      '<h2>' + esc(title()) + '</h2></div>' +
      '<div class="fvr-cal-actions">' +
      '<div class="fvr-cal-views">' +
      ['day', 'week', 'month'].map(function (v) {
        return '<button type="button" data-view="' + v + '"' + (state.view === v ? ' class="active"' : '') + '>' +
          { day: 'Jour', week: 'Semaine', month: 'Mois' }[v] + '</button>';
      }).join('') + '</div>' +
      '<label class="fvr-cal-toggle"><input type="checkbox" data-cancelled' + (state.showCancelled ? ' checked' : '') + '> Annulées</label>' +
      (C.canEdit ? '<button type="button" class="fvr-cal-primary" data-new>+ Réservation</button>' : '') +
      (C.icsUrl ? '<a class="fvr-cal-link" href="' + esc(C.icsUrl.replace(/^https?:/, 'webcal:')) + '" title="Abonner votre agenda (téléphone, Google, Outlook) à ce planning">📅 Ajouter à mon agenda</a>' : '') +
      '</div></div>';

    html += '<div class="fvr-cal-legend">' + Object.keys(C.statuses).map(function (k) {
      return '<span class="fvr-chip-dot st-' + k + '"></span>' + esc(C.statuses[k]);
    }).join(' ') + (C.canEdit ? '<span class="fvr-cal-hint">Cliquez sur une réservation pour la modifier, glissez-la pour la déplacer.</span>' : '') + '</div>';

    if (state.view === 'week') html += renderWeek(r[0]);
    else if (state.view === 'day') html += renderDay(state.anchor);
    else html += renderMonth(r[0], r[1]);

    root.innerHTML = html;
  }

  function chip(b) {
    return '<div class="fvr-chip st-' + esc(b.status) + '" data-id="' + b.id + '"' +
      (C.canEdit ? ' draggable="true"' : '') + ' tabindex="0" role="button" title="' +
      esc(b.name + ' – ' + b.passengers + ' pax – ' + b.flight_name + (b.weights ? ' – ' + b.weights + ' kg' : '')) + '">' +
      '<strong>' + esc(b.name) + '</strong> <span>' + b.passengers + ' pax</span>' +
      '<small>' + esc(b.flight_name) + (b.weights ? ' · ' + esc(b.weights) + ' kg' : '') + '</small></div>';
  }

  function renderWeek(start) {
    var days = [];
    for (var i = 0; i < 7; i++) days.push(addDays(start, i));
    var map = byDateTime(), blocked = blockedMap(), caps = capacityMap(), ts = times();

    var h = '<div class="fvr-cal-scroll"><table class="fvr-cal-week"><thead><tr><th></th>';
    days.forEach(function (d) {
      var dt = toDate(d), dayPax = 0;
      ts.forEach(function (t) { dayPax += paxOf(map[d + ' ' + t]); });
      h += '<th class="' + (d === C.today ? 'today' : '') + (blocked[d] ? ' blocked' : '') + '">' +
        '<a href="#" data-goto="' + d + '">' + DAYS[dt.getDay()] + ' ' + dt.getDate() + '</a>' +
        (blocked[d] ? '<small class="fvr-closed">' + esc(blocked[d]) + '</small>' : dayPax ? '<small>' + dayPax + ' pax</small>' : '') +
        '</th>';
    });
    h += '</tr></thead><tbody>';
    if (!ts.length) h += '<tr><td colspan="8" class="fvr-empty">Aucun créneau défini.</td></tr>';
    ts.forEach(function (t) {
      h += '<tr><th class="fvr-time">' + esc(t) + '</th>';
      days.forEach(function (d) {
        var list = map[d + ' ' + t] || [];
        h += '<td class="fvr-cell' + (blocked[d] ? ' blocked' : '') + (d < C.today ? ' past' : '') + (d === C.today ? ' today' : '') +
          '" data-date="' + d + '" data-time="' + esc(t) + '">' +
          '<div class="fvr-cell-head">' + loadBadge(paxOf(list), caps[t]) +
          (C.canEdit ? '<button type="button" class="fvr-add" data-add aria-label="Ajouter">+</button>' : '') + '</div>' +
          list.map(chip).join('') + '</td>';
      });
      h += '</tr>';
    });
    return h + '</tbody></table></div>';
  }

  function renderDay(d) {
    var map = byDateTime(), blocked = blockedMap(), caps = capacityMap(), ts = times();
    var h = '<div class="fvr-cal-day">';
    if (blocked[d]) h += '<p class="fvr-closed-banner">Journée fermée : ' + esc(blocked[d]) + '</p>';
    var total = 0;
    ts.forEach(function (t) {
      var list = map[d + ' ' + t] || [];
      total += list.length;
      if (!list.length && !C.canEdit) return;
      h += '<section class="fvr-day-slot fvr-cell" data-date="' + d + '" data-time="' + esc(t) + '">' +
        '<h3><span>' + esc(t) + '</span>' + loadBadge(paxOf(list), caps[t]) +
        (C.canEdit ? '<button type="button" class="fvr-add" data-add aria-label="Ajouter">+</button>' : '') + '</h3>';
      if (!list.length) h += '<p class="fvr-empty">Libre</p>';
      list.forEach(function (b) {
        h += '<article class="fvr-card st-' + esc(b.status) + '" data-id="' + b.id + '"' + (C.canEdit ? ' draggable="true"' : '') + ' tabindex="0" role="button">' +
          '<div class="fvr-card-top"><strong>' + esc(b.name) + '</strong><span class="fvr-badge st-' + esc(b.status) + '">' + esc(C.statuses[b.status] || b.status) + '</span></div>' +
          '<div>' + b.passengers + ' passager' + (b.passengers > 1 ? 's' : '') + ' · ' + esc(b.flight_name) + (b.weights ? ' · ' + esc(b.weights) + ' kg' : '') + '</div>' +
          (b.phone ? '<div><a href="tel:' + esc(tel(b.phone)) + '">📞 ' + esc(b.phone) + '</a></div>' : '') +
          (b.message ? '<div class="fvr-note">💬 ' + esc(b.message) + '</div>' : '') +
          (b.admin_notes ? '<div class="fvr-note">📝 ' + esc(b.admin_notes) + '</div>' : '') +
          '</article>';
      });
      h += '</section>';
    });
    if (!total && !C.canEdit) h += '<p class="fvr-empty">Aucun vol prévu ce jour-là.</p>';
    return h + '</div>';
  }

  function renderMonth(from, to) {
    var blocked = blockedMap(), month = toDate(state.anchor).getMonth();
    var perDay = {};
    visibleBookings().forEach(function (b) { (perDay[b.date] = perDay[b.date] || []).push(b); });
    var h = '<div class="fvr-cal-month">' + ['lun.', 'mar.', 'mer.', 'jeu.', 'ven.', 'sam.', 'dim.'].map(function (d) {
      return '<div class="fvr-month-head">' + d + '</div>';
    }).join('');
    for (var d = from; d <= to; d = addDays(d, 1)) {
      var dt = toDate(d), list = perDay[d] || [];
      h += '<div class="fvr-month-day' + (dt.getMonth() !== month ? ' other' : '') + (d === C.today ? ' today' : '') +
        (blocked[d] ? ' blocked' : '') + '" data-goto="' + d + '">' +
        '<div class="fvr-month-num"><span>' + dt.getDate() + '</span>' + (paxOf(list) ? '<small>' + paxOf(list) + ' pax</small>' : '') + '</div>' +
        (blocked[d] ? '<small class="fvr-closed">' + esc(blocked[d]) + '</small>' : '') +
        list.slice(0, 4).map(function (b) {
          return '<div class="fvr-month-item st-' + esc(b.status) + '" data-id="' + b.id + '">' + esc(b.time) + ' ' + esc(b.name) + ' (' + b.passengers + ')</div>';
        }).join('') +
        (list.length > 4 ? '<div class="fvr-month-more">+ ' + (list.length - 4) + ' autre(s)</div>' : '') +
        '</div>';
    }
    return h + '</div>';
  }

  // ---------- Fenêtre de détail / modification ----------
  function findBooking(id) {
    return state.data.bookings.filter(function (b) { return b.id === id; })[0];
  }

  function closeModal() {
    var m = document.querySelector('.fvr-modal-bg');
    if (m) m.parentNode.removeChild(m);
    state.modalOpen = false;
  }

  function openModal(inner) {
    closeModal();
    var bg = document.createElement('div');
    bg.className = 'fvr-modal-bg';
    bg.innerHTML = '<div class="fvr-modal" role="dialog" aria-modal="true"><button type="button" class="fvr-modal-close" aria-label="Fermer">×</button>' + inner + '</div>';
    bg.addEventListener('click', function (e) {
      if (e.target === bg || e.target.classList.contains('fvr-modal-close') || e.target.hasAttribute('data-close')) closeModal();
    });
    document.body.appendChild(bg);
    state.modalOpen = true;
    var first = bg.querySelector('input, select, textarea, button');
    if (first) first.focus();
    return bg;
  }

  function showDetails(b) {
    var row = function (label, value) { return value ? '<dt>' + label + '</dt><dd>' + value + '</dd>' : ''; };
    openModal('<h2>' + esc(b.name) + '</h2>' +
      '<p class="fvr-badge st-' + esc(b.status) + '">' + esc(C.statuses[b.status] || b.status) + '</p><dl>' +
      row('Date', esc(longDate(b.date)) + ' à ' + esc(b.time)) +
      row('Vol', esc(b.flight_name)) +
      row('Passagers', b.passengers) +
      row('Poids', b.weights ? esc(b.weights) + ' kg' : '') +
      row('Téléphone', b.phone ? '<a href="tel:' + esc(tel(b.phone)) + '">' + esc(b.phone) + '</a>' : '') +
      row('Message', esc(b.message)) +
      row('Notes', esc(b.admin_notes)) +
      row('Référence', esc(b.reference)) + '</dl>');
  }

  function editForm(b) {
    var isNew = !b.id;
    var flights = state.data.flights || [];
    var ts = state.data.slots.map(function (s) { return s.time; });
    if (b.time && ts.indexOf(b.time) === -1) ts.push(b.time);
    ts.sort();
    if (isNew && !b.flight_id && flights.length) {
      var def = flights.filter(function (f) { return f.active; })[0] || flights[0];
      b.flight_id = def.id;
      b.price = def.price * (b.passengers || 1);
    }
    var flightOptions = flights.map(function (f) {
      return '<option value="' + f.id + '" data-price="' + f.price + '"' + (f.id === b.flight_id ? ' selected' : '') + '>' +
        esc(f.name) + ' (' + chf(f.price) + ')' + (f.active ? '' : ' – inactif') + '</option>';
    }).join('');
    if (b.flight_id && !flights.some(function (f) { return f.id === b.flight_id; }) || (!b.flight_id && b.flight_name)) {
      flightOptions = '<option value="0" selected>' + esc(b.flight_name) + '</option>' + flightOptions;
    }

    var bg = openModal('<h2>' + (isNew ? 'Nouvelle réservation' : 'Réservation ' + esc(b.reference)) + '</h2>' +
      '<form class="fvr-form">' +
      '<div class="fvr-grid">' +
      '<label>Date<input type="date" name="date" required value="' + esc(b.date) + '"></label>' +
      '<label>Heure<select name="time">' + ts.map(function (t) {
        return '<option' + (t === b.time ? ' selected' : '') + '>' + esc(t) + '</option>';
      }).join('') + '</select></label>' +
      '<label>Statut<select name="status">' + Object.keys(C.statuses).map(function (k) {
        return '<option value="' + k + '"' + (k === b.status ? ' selected' : '') + '>' + esc(C.statuses[k]) + '</option>';
      }).join('') + '</select></label>' +
      '<label>Vol<select name="flight_id">' + flightOptions + '</select></label>' +
      '<label>Passagers<input type="number" name="passengers" min="1" value="' + (b.passengers || 1) + '"></label>' +
      '<label>Prix total CHF<input type="number" name="price" step="0.01" value="' + (b.price || 0) + '"></label>' +
      '<label>Nom<input type="text" name="name" required value="' + esc(b.name) + '"></label>' +
      '<label>Téléphone<input type="tel" name="phone" value="' + esc(b.phone) + '"></label>' +
      '<label>E-mail<input type="email" name="email" value="' + esc(b.email) + '"></label>' +
      '<label>Poids (kg)<input type="text" name="weights" value="' + esc(b.weights) + '"></label>' +
      '</div>' +
      '<label>Message du client<textarea name="message" rows="2">' + esc(b.message) + '</textarea></label>' +
      '<label>Notes internes (pilote, paiement…) — visibles par les pilotes<textarea name="admin_notes" rows="2">' + esc(b.admin_notes) + '</textarea></label>' +
      '<p class="fvr-cal-error" hidden></p>' +
      '<div class="fvr-modal-actions">' +
      '<button type="submit" class="fvr-cal-primary">Enregistrer</button>' +
      '<button type="button" data-close>Annuler</button>' +
      (isNew ? '' : '<button type="button" class="fvr-danger" data-delete>Supprimer</button>') +
      (!isNew && b.phone ? '<a href="tel:' + esc(tel(b.phone)) + '">Appeler</a>' : '') +
      (!isNew && b.email ? '<a href="mailto:' + esc(b.email) + '">E-mail</a>' : '') +
      '</div></form>');

    var form = bg.querySelector('form');
    var errEl = form.querySelector('.fvr-cal-error');
    function recalc() {
      var opt = form.flight_id.selectedOptions[0];
      if (opt && opt.dataset.price) form.price.value = (parseFloat(opt.dataset.price) || 0) * (parseInt(form.passengers.value, 10) || 0);
    }
    form.flight_id.addEventListener('change', recalc);
    form.passengers.addEventListener('input', recalc);

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var payload = { id: b.id || 0, flight_name: b.flight_name || '' };
      new FormData(form).forEach(function (v, k) { payload[k] = v; });
      save(payload, errEl);
    });
    var del = form.querySelector('[data-delete]');
    if (del) del.addEventListener('click', function () {
      if (!confirm('Supprimer définitivement la réservation de ' + b.name + ' ?')) return;
      api('admin/booking/' + b.id, null, { method: 'DELETE' }).then(function () { closeModal(); load(); })
        .catch(function (err) { errEl.textContent = err.message; errEl.hidden = false; });
    });
  }

  function save(payload, errEl) {
    return api('admin/booking', null, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    }).then(function () { closeModal(); return load(); })
      .catch(function (err) {
        if (errEl) { errEl.textContent = err.message; errEl.hidden = false; } else alert(err.message);
      });
  }

  function openBooking(id) {
    var b = findBooking(id);
    if (!b) return;
    if (C.canEdit) editForm(Object.assign({}, b)); else showDetails(b);
  }

  // ---------- Événements ----------
  root.addEventListener('click', function (e) {
    var t = e.target;
    var nav = t.closest('[data-nav]');
    if (nav) {
      var n = +nav.getAttribute('data-nav');
      if (n === 0) state.anchor = C.today;
      else if (state.view === 'day') state.anchor = addDays(state.anchor, n);
      else if (state.view === 'week') state.anchor = addDays(state.anchor, 7 * n);
      else { var d = toDate(state.anchor); state.anchor = toStr(new Date(d.getFullYear(), d.getMonth() + n, 1, 12)); }
      return load();
    }
    var view = t.closest('[data-view]');
    if (view) { state.view = view.getAttribute('data-view'); store('view', state.view); return load(); }
    if (t.closest('[data-new]')) {
      var s = state.data.slots.filter(function (x) { return x.active; })[0];
      return editForm({ date: state.view === 'day' ? state.anchor : C.today, time: s ? s.time : '10:00', status: 'confirmed', passengers: 1 });
    }
    if (t.closest('[data-add]')) {
      var cell = t.closest('[data-date]');
      return editForm({ date: cell.getAttribute('data-date'), time: cell.getAttribute('data-time'), status: 'confirmed', passengers: 1 });
    }
    var item = t.closest('[data-id]');
    if (item && !t.closest('a')) { e.preventDefault(); return openBooking(+item.getAttribute('data-id')); }
    var go = t.closest('[data-goto]');
    if (go) { e.preventDefault(); state.anchor = go.getAttribute('data-goto'); state.view = 'day'; store('view', 'day'); return load(); }
  });

  root.addEventListener('keydown', function (e) {
    var item = e.target.closest && e.target.closest('[data-id]');
    if (item && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openBooking(+item.getAttribute('data-id')); }
  });

  root.addEventListener('change', function (e) {
    if (e.target.hasAttribute('data-cancelled')) {
      state.showCancelled = e.target.checked;
      store('cancelled', state.showCancelled ? '1' : '0');
      render();
    }
  });

  document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && state.modalOpen) closeModal(); });

  // Glisser-déposer (administrateur) : déplacer une réservation sur un autre jour / horaire
  if (C.canEdit) {
    root.addEventListener('dragstart', function (e) {
      var item = e.target.closest && e.target.closest('[data-id][draggable]');
      if (!item) return;
      state.dragging = true;
      e.dataTransfer.effectAllowed = 'move';
      e.dataTransfer.setData('text/plain', item.getAttribute('data-id'));
    });
    root.addEventListener('dragend', function () {
      state.dragging = false;
      root.querySelectorAll('.drop-target').forEach(function (el) { el.classList.remove('drop-target'); });
    });
    root.addEventListener('dragover', function (e) {
      var cell = e.target.closest && e.target.closest('.fvr-cell');
      if (!cell) return;
      e.preventDefault();
      root.querySelectorAll('.drop-target').forEach(function (el) { if (el !== cell) el.classList.remove('drop-target'); });
      cell.classList.add('drop-target');
    });
    root.addEventListener('drop', function (e) {
      var cell = e.target.closest && e.target.closest('.fvr-cell');
      if (!cell) return;
      e.preventDefault();
      state.dragging = false;
      var b = findBooking(+e.dataTransfer.getData('text/plain'));
      var date = cell.getAttribute('data-date'), time = cell.getAttribute('data-time');
      if (!b || (b.date === date && b.time === time)) return render();
      if (!confirm('Déplacer ' + b.name + ' (' + b.passengers + ' pax) au ' + longDate(date) + ' à ' + time + ' ?')) return render();
      save(Object.assign({}, b, { date: date, time: time }));
    });
  }

  // Actualisation automatique (nouvelles réservations) toutes les 60 secondes
  setInterval(function () {
    if (!state.modalOpen && !state.dragging && !document.hidden) load();
  }, 60000);

  load();
})();
