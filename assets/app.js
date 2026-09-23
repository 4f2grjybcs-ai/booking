(function () {
  var API = 'api.php';
  var form = document.getElementById('booking-form');
  var flightsEl = document.getElementById('flights');
  var slotsEl = document.getElementById('slots');
  var dateEl = document.getElementById('date');
  var paxEl = document.getElementById('passengers');
  var summaryEl = document.getElementById('summary');
  var errorEl = document.getElementById('error');
  var submitBtn = document.getElementById('submit');

  var flights = [];
  var state = { flight: null, time: null, slots: [] };

  function chf(n) {
    return 'CHF ' + Number(n).toFixed(0).replace(/\B(?=(\d{3})+(?!\d))/g, "'");
  }

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }

  function isoDate(d) {
    var m = String(d.getMonth() + 1).padStart(2, '0');
    var day = String(d.getDate()).padStart(2, '0');
    return d.getFullYear() + '-' + m + '-' + day;
  }

  function showError(msg) {
    errorEl.textContent = msg;
    errorEl.hidden = !msg;
  }

  function renderFlights() {
    if (!flights.length) {
      flightsEl.innerHTML = '<p class="muted">Aucun vol disponible pour le moment.</p>';
      return;
    }
    flightsEl.innerHTML = flights.map(function (f) {
      return '<label class="card">' +
        '<input type="radio" name="flight_id" value="' + f.id + '">' +
        '<span class="card-body">' +
        '<span class="card-title">' + esc(f.name) + '</span>' +
        (f.duration ? '<span class="card-meta">' + esc(f.duration) + '</span>' : '') +
        '<span class="card-desc">' + esc(f.description) + '</span>' +
        '<span class="card-price">' + chf(f.price) + ' <small>/ pers.</small></span>' +
        '</span></label>';
    }).join('');
  }

  function renderSlots() {
    var pax = parseInt(paxEl.value, 10);
    if (!dateEl.value) return;
    if (!state.slots.length) {
      slotsEl.innerHTML = '<p class="muted">Aucun vol possible ce jour-là. Merci de choisir une autre date.</p>';
      state.time = null;
      return;
    }
    var stillValid = false;
    slotsEl.innerHTML = state.slots.map(function (s) {
      var ok = s.remaining >= pax;
      if (ok && s.time === state.time) stillValid = true;
      var label = s.remaining === 0 ? 'complet' : s.remaining + (s.remaining > 1 ? ' places' : ' place');
      return '<label class="slot' + (ok ? '' : ' disabled') + '">' +
        '<input type="radio" name="time" value="' + s.time + '"' + (ok ? '' : ' disabled') +
        (ok && s.time === state.time ? ' checked' : '') + '>' +
        '<span><strong>' + s.time + '</strong><small>' + label + '</small></span></label>';
    }).join('');
    if (!stillValid) state.time = null;
    updateSummary();
  }

  function loadSlots() {
    state.time = null;
    if (!dateEl.value) return;
    slotsEl.innerHTML = '<p class="muted">Chargement des horaires…</p>';
    fetch(API + '?action=availability&date=' + encodeURIComponent(dateEl.value))
      .then(function (r) { return r.json(); })
      .then(function (data) {
        state.slots = data.slots || [];
        renderSlots();
      })
      .catch(function () {
        slotsEl.innerHTML = '<p class="error">Impossible de charger les horaires.</p>';
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
    summaryEl.innerHTML = '<strong>' + esc(state.flight.name) + '</strong> · ' + dateTxt + ' à ' + state.time +
      ' · ' + pax + ' passager' + (pax > 1 ? 's' : '') +
      '<span class="total">Total : ' + chf(state.flight.price * pax) + '</span>';
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

    var fd = new FormData(form);
    var payload = {};
    fd.forEach(function (v, k) { payload[k] = v; });

    submitBtn.disabled = true;
    submitBtn.textContent = 'Envoi en cours…';
    fetch(API + '?action=book', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data.ok) throw new Error(data.error || 'Erreur');
        document.getElementById('done-ref').textContent = data.reference;
        form.hidden = true;
        document.getElementById('done').hidden = false;
        window.scrollTo(0, 0);
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

  var today = new Date();
  dateEl.min = isoDate(today);
  var max = new Date();
  max.setFullYear(max.getFullYear() + 1);
  dateEl.max = isoDate(max);

  fetch(API + '?action=flights')
    .then(function (r) { return r.json(); })
    .then(function (data) {
      flights = data.flights || [];
      renderFlights();
    })
    .catch(function () {
      flightsEl.innerHTML = '<p class="error">Impossible de charger les vols.</p>';
    });
})();
