(function () {
  var root = document.querySelector('.fvr-booking');
  if (!root || !window.fvrConfig) return;

  var form = root.querySelector('.fvr-form');
  var flightsEl = root.querySelector('.fvr-flights');
  var slotsEl = root.querySelector('.fvr-slots');
  var dateEl = root.querySelector('.fvr-date');
  var paxEl = root.querySelector('.fvr-pax');
  var summaryEl = root.querySelector('.fvr-summary');
  var errorEl = root.querySelector('.fvr-error');
  var submitBtn = root.querySelector('.fvr-submit');

  var flights = [];
  var state = { flight: null, time: null, slots: [] };

  // Fonctionne avec ou sans permaliens (…/wp-json/fvr/v1/ ou …/?rest_route=/fvr/v1/)
  function api(path, params) {
    var url = fvrConfig.api + path;
    var qs = params ? new URLSearchParams(params).toString() : '';
    if (qs) url += (url.indexOf('?') === -1 ? '?' : '&') + qs;
    return url;
  }

  function getJSON(res) {
    return res.json().then(function (data) {
      if (!res.ok) throw new Error(data.message || data.error || 'Une erreur est survenue.');
      return data;
    });
  }

  function chf(n) {
    return 'CHF ' + Math.round(Number(n)).toString().replace(/\B(?=(\d{3})+(?!\d))/g, "'");
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function showError(msg) {
    errorEl.textContent = msg;
    errorEl.hidden = !msg;
  }

  function renderFlights() {
    if (!flights.length) {
      flightsEl.innerHTML = '<p class="fvr-muted">Aucun vol disponible pour le moment.</p>';
      return;
    }
    flightsEl.innerHTML = flights.map(function (f) {
      return '<label class="fvr-card">' +
        '<input type="radio" name="flight_id" value="' + esc(f.id) + '">' +
        '<span class="fvr-card-body">' +
        '<span class="fvr-card-title">' + esc(f.name) + '</span>' +
        (f.duration ? '<span class="fvr-card-meta">' + esc(f.duration) + '</span>' : '') +
        (f.description ? '<span class="fvr-card-desc">' + esc(f.description) + '</span>' : '') +
        '<span class="fvr-card-price">' + chf(f.price) + ' <small>/ pers.</small></span>' +
        '</span></label>';
    }).join('');
  }

  function renderSlots() {
    var pax = parseInt(paxEl.value, 10);
    if (!dateEl.value) return;
    if (!state.slots.length) {
      slotsEl.innerHTML = '<p class="fvr-muted">Aucun vol possible ce jour-là. Merci de choisir une autre date.</p>';
      state.time = null;
      updateSummary();
      return;
    }
    var stillValid = false;
    slotsEl.innerHTML = state.slots.map(function (s) {
      var ok = s.remaining >= pax;
      if (ok && s.time === state.time) stillValid = true;
      var label = s.remaining === 0 ? 'complet' : s.remaining + (s.remaining > 1 ? ' places' : ' place');
      return '<label class="fvr-slot' + (ok ? '' : ' fvr-disabled') + '">' +
        '<input type="radio" name="time" value="' + esc(s.time) + '"' + (ok ? '' : ' disabled') +
        (ok && s.time === state.time ? ' checked' : '') + '>' +
        '<span><strong>' + esc(s.time) + '</strong><small>' + label + '</small></span></label>';
    }).join('');
    if (!stillValid) state.time = null;
    updateSummary();
  }

  function loadSlots() {
    state.time = null;
    updateSummary();
    if (!dateEl.value) return;
    slotsEl.innerHTML = '<p class="fvr-muted">Chargement des horaires…</p>';
    fetch(api('availability', { date: dateEl.value }), { cache: 'no-store' })
      .then(getJSON)
      .then(function (data) {
        state.slots = data.slots || [];
        renderSlots();
      })
      .catch(function (err) {
        slotsEl.innerHTML = '<p class="fvr-error">' + esc(err.message || 'Impossible de charger les horaires.') + '</p>';
      });
  }

  function updateSummary() {
    var pax = parseInt(paxEl.value, 10);
    if (!state.flight || !state.time || !dateEl.value) {
      summaryEl.hidden = true;
      return;
    }
    var d = new Date(dateEl.value + 'T12:00:00');
    var dateTxt = d.toLocaleDateString('fr-CH', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    summaryEl.innerHTML = '<strong>' + esc(state.flight.name) + '</strong> · ' + dateTxt + ' à ' + esc(state.time) +
      ' · ' + pax + ' passager' + (pax > 1 ? 's' : '') +
      '<span class="fvr-total">Total : ' + chf(state.flight.price * pax) + '</span>';
    summaryEl.hidden = false;
  }

  flightsEl.addEventListener('change', function (e) {
    if (e.target.name === 'flight_id') {
      state.flight = flights.find(function (f) { return String(f.id) === e.target.value; });
      updateSummary();
    }
  });
  slotsEl.addEventListener('change', function (e) {
    if (e.target.name === 'time') {
      state.time = e.target.value;
      updateSummary();
    }
  });
  dateEl.addEventListener('change', loadSlots);
  paxEl.addEventListener('change', renderSlots);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    showError('');
    if (!state.flight) return showError('Veuillez choisir un type de vol.');
    if (!dateEl.value) return showError('Veuillez choisir une date.');
    if (!state.time) return showError('Veuillez choisir un horaire.');
    if (!form.reportValidity()) return;

    var payload = {};
    new FormData(form).forEach(function (v, k) { payload[k] = v; });

    submitBtn.disabled = true;
    submitBtn.textContent = 'Envoi en cours…';
    fetch(api('book'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(getJSON)
      .then(function (data) {
        root.querySelector('.fvr-done-ref').textContent = data.reference;
        form.hidden = true;
        root.querySelector('.fvr-done').hidden = false;
        root.scrollIntoView({ behavior: 'smooth' });
      })
      .catch(function (err) {
        showError(err.message || 'Une erreur est survenue.');
        loadSlots();
      })
      .finally(function () {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Envoyer ma réservation';
      });
  });

  // Dates possibles : d'aujourd'hui (heure suisse du site) à +maxDays jours
  dateEl.min = fvrConfig.today;
  var max = new Date(fvrConfig.today + 'T12:00:00');
  max.setDate(max.getDate() + fvrConfig.maxDays);
  dateEl.max = max.toISOString().slice(0, 10);
  if (fvrConfig.maxDate && fvrConfig.maxDate < dateEl.max) dateEl.max = fvrConfig.maxDate;

  fetch(api('flights'))
    .then(getJSON)
    .then(function (data) {
      flights = data.flights || [];
      renderFlights();
    })
    .catch(function () {
      flightsEl.innerHTML = '<p class="fvr-error">Impossible de charger les vols.</p>';
    });
})();
