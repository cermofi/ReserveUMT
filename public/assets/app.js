(() => {
  const body = document.body;
  const page = body.dataset.page || 'public';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const weekStartStr = body.dataset.weekStart;
  const gridStart = body.dataset.gridStart || '06:00';
  const gridEnd = body.dataset.gridEnd || '23:00';
  const stepMin = parseInt(body.dataset.stepMin || '30', 10);
  const spaceLabels = {
    WHOLE: 'Celá UMT',
    HALF_A: body.dataset.spaceLabelA || 'Pùlka A',
    HALF_B: body.dataset.spaceLabelB || 'Pùlka B'
  };

  const toastEl = document.getElementById('toast');
  const showToast = (msg) => {
    if (!toastEl) return;
    toastEl.textContent = msg;
    toastEl.classList.add('show');
    setTimeout(() => toastEl.classList.remove('show'), 3200);
  };

  const parseYmd = (ymd) => {
    const [y, m, d] = ymd.split('-').map(Number);
    return new Date(y, m - 1, d);
  };

  const formatYmd = (date) => {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, '0');
    const d = String(date.getDate()).padStart(2, '0');
    return `${y}-${m}-${d}`;
  };

  const formatTime = (date) => {
    const h = String(date.getHours()).padStart(2, '0');
    const m = String(date.getMinutes()).padStart(2, '0');
    return `${h}:${m}`;
  };

  const minutesToTime = (min) => {
    const h = String(Math.floor(min / 60)).padStart(2, '0');
    const m = String(min % 60).padStart(2, '0');
    return `${h}:${m}`;
  };

  const parseHm = (str) => {
    const [h, m] = str.split(':').map(Number);
    return h * 60 + m;
  };

  const weekStart = weekStartStr ? parseYmd(weekStartStr) : new Date();
  const totalMinutes = parseHm(gridEnd) - parseHm(gridStart);

  const categoryIndex = (category) => {
    let hash = 0;
    for (let i = 0; i < category.length; i++) {
      hash = (hash * 31 + category.charCodeAt(i)) | 0;
    }
    return Math.abs(hash) % 8;
  };

  const openModal = (id) => {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.setAttribute('aria-hidden', 'false');
  };

  const closeModal = (id) => {
    const modal = document.getElementById(id);
    if (!modal) return;
    modal.setAttribute('aria-hidden', 'true');
  };

  document.addEventListener('click', (e) => {
    const target = e.target;
    if (!(target instanceof HTMLElement)) return;
    const closeId = target.dataset.close;
    if (closeId) {
      closeModal(closeId);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal').forEach((m) => m.setAttribute('aria-hidden', 'true'));
    }
  });

  const fetchJson = async (url, options = {}) => {
    const res = await fetch(url, options);
    const data = await res.json();
    if (!data.ok) {
      throw new Error(data.error || 'Chyba');
    }
    return data;
  };

  const setLoading = (btn, loading) => {
    if (!btn) return;
    if (loading) {
      btn.classList.add('loading');
      btn.disabled = true;
    } else {
      btn.classList.remove('loading');
      btn.disabled = false;
    }
  };

  const renderCalendar = (bookings, recurring) => {
    const calendar = document.getElementById('calendar');
    if (!calendar) return;
    calendar.innerHTML = '';

    const timeCol = document.createElement('div');
    timeCol.className = 'time-col';
    const timeHeader = document.createElement('div');
    timeHeader.className = 'time-header';
    const timeLabels = document.createElement('div');
    timeLabels.className = 'time-labels';

    const startMin = parseHm(gridStart);
    for (let m = startMin; m <= startMin + totalMinutes; m += 60) {
      const label = document.createElement('div');
      label.className = 'time-label';
      const h = String(Math.floor(m / 60)).padStart(2, '0');
      label.textContent = `${h}:00`;
      timeLabels.appendChild(label);
    }
    timeCol.appendChild(timeHeader);
    timeCol.appendChild(timeLabels);

    const dayCols = document.createElement('div');
    dayCols.className = 'day-cols';

    const all = [...bookings, ...recurring];
    for (let i = 0; i < 7; i++) {
      const dayDate = new Date(weekStart);
      dayDate.setDate(weekStart.getDate() + i);
      const ymd = formatYmd(dayDate);
      const col = document.createElement('div');
      col.className = 'day-col';
      const header = document.createElement('div');
      header.className = 'day-header';
      header.textContent = dayDate.toLocaleDateString('cs-CZ', { weekday: 'short', day: '2-digit', month: '2-digit' });
      const track = document.createElement('div');
      track.className = 'day-track';
      track.style.setProperty('--total-minutes', totalMinutes);
      track.dataset.date = ymd;

      track.addEventListener('click', (e) => {
        if (page !== 'public') return;
        const rect = track.getBoundingClientRect();
        const pxPerMin = parseFloat(getComputedStyle(track).getPropertyValue('--px-per-min')) || 1;
        const offset = e.clientY - rect.top;
        const minutes = Math.max(0, Math.min(totalMinutes - stepMin, Math.round(offset / pxPerMin / stepMin) * stepMin));
        const start = startMin + minutes;
        const end = start + stepMin;
        openReservationModal(ymd, start, end);
      });

      all.filter(b => {
        const d = new Date(b.start_ts * 1000);
        return formatYmd(d) === ymd;
      }).forEach((b) => {
        const item = document.createElement('div');
        item.className = `booking ${b.space === 'WHOLE' ? 'whole' : (b.space === 'HALF_A' ? 'half-a' : 'half-b')}`;
        const startDate = new Date(b.start_ts * 1000);
        const endDate = new Date(b.end_ts * 1000);
        const minutesFromStart = (startDate.getHours() * 60 + startDate.getMinutes()) - startMin;
        const duration = (endDate - startDate) / 60000;
        item.style.top = `calc(${minutesFromStart} * var(--px-per-min))`;
        item.style.height = `calc(${duration} * var(--px-per-min))`;
        const idx = categoryIndex(b.category || '');
        item.classList.add(`cat-${idx}`);
        item.innerHTML = `
          <div class="booking-title">${b.name}</div>
          <div class="chips">
            <span class="chip">${b.category}</span>
            <span class="chip">${spaceLabels[b.space] || b.space}</span>
          </div>
        `;
        item.addEventListener('click', (ev) => {
          ev.stopPropagation();
          showToast(`${b.name} · ${b.category} · ${spaceLabels[b.space] || b.space}`);
        });
        track.appendChild(item);
      });

      col.appendChild(header);
      col.appendChild(track);
      dayCols.appendChild(col);
    }

    calendar.appendChild(timeCol);
    calendar.appendChild(dayCols);
  };

  const renderAgenda = (bookings, recurring) => {
    const agenda = document.getElementById('agenda');
    if (!agenda) return;
    agenda.innerHTML = '';
    const all = [...bookings, ...recurring].sort((a, b) => a.start_ts - b.start_ts);
    if (!all.length) {
      agenda.innerHTML = '<div class="panel">Žádné rezervace v tomto týdnu.</div>';
      return;
    }
    all.forEach((b) => {
      const item = document.createElement('div');
      item.className = 'agenda-item';
      const start = new Date(b.start_ts * 1000);
      const end = new Date(b.end_ts * 1000);
      item.innerHTML = `
        <div class="booking-title">${b.name}</div>
        <div class="meta">${start.toLocaleDateString('cs-CZ')} ${formatTime(start)}–${formatTime(end)}</div>
        <div class="chips">
          <span class="chip">${b.category}</span>
          <span class="chip">${spaceLabels[b.space] || b.space}</span>
        </div>
      `;
      agenda.appendChild(item);
    });
  };

  const updateWeekLabel = () => {
    const label = document.getElementById('week-label');
    if (!label) return;
    const end = new Date(weekStart);
    end.setDate(weekStart.getDate() + 6);
    label.textContent = `${weekStart.toLocaleDateString('cs-CZ')} – ${end.toLocaleDateString('cs-CZ')}`;
  };

  const loadWeek = async () => {
    updateWeekLabel();
    const from = Math.floor(weekStart.getTime() / 1000);
    const to = Math.floor((weekStart.getTime() + 7 * 86400000) / 1000);
    if (page === 'admin') {
      const res = await fetchJson('/admin.php', {
        method: 'POST',
        headers: { 'X-CSRF-Token': csrf },
        body: new URLSearchParams({ action: 'list_admin', csrf, from: String(from), to: String(to) })
      });
      renderCalendar(res.bookings, res.recurring);
      renderAgenda(res.bookings, res.recurring);
      renderAdminTables(res.bookings, res.recurring, res.rules, res.audit);
      return;
    }
    const res = await fetchJson(`/api.php?action=list&from=${from}&to=${to}`);
    renderCalendar(res.bookings, res.recurring);
    renderAgenda(res.bookings, res.recurring);
  };

  const wireWeekButtons = () => {
    const prev = document.getElementById('week-prev');
    const next = document.getElementById('week-next');
    if (prev) prev.addEventListener('click', () => {
      weekStart.setDate(weekStart.getDate() - 7);
      loadWeek();
    });
    if (next) next.addEventListener('click', () => {
      weekStart.setDate(weekStart.getDate() + 7);
      loadWeek();
    });
  };

  const openReservationModal = (dateStr, startMin, endMin) => {
    const form = document.getElementById('form-reserve');
    if (!form) return;
    form.date.value = dateStr;
    const baseDate = parseYmd(dateStr);
    const start = new Date(baseDate);
    start.setHours(0, 0, 0, 0);
    start.setMinutes(startMin);
    const end = new Date(baseDate);
    end.setHours(0, 0, 0, 0);
    end.setMinutes(endMin);
    form.start.value = formatTime(start);
    form.end.value = formatTime(end);
    openModal('modal-reserve');
  };

  const initPublicForms = () => {
    const btnNew = document.getElementById('btn-new');
    if (btnNew) {
      btnNew.addEventListener('click', () => openReservationModal(formatYmd(weekStart), parseHm(gridStart), parseHm(gridStart) + stepMin));
    }

    const formReserve = document.getElementById('form-reserve');
    const formVerify = document.getElementById('form-verify');
    let verifyTimer = null;

    if (formReserve) {
      formReserve.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = formReserve.querySelector('button[type="submit"]');
        setLoading(btn, true);
        try {
          const data = new FormData(formReserve);
          const res = await fetchJson('/api.php?action=request_booking', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
            body: data
          });
          closeModal('modal-reserve');
          const verifyForm = document.getElementById('form-verify');
          verifyForm.pending_id.value = res.pending_id;
          openModal('modal-verify');
          startCountdown(res.expires_ts);
          showToast('Kód byl odeslán na e-mail.');
        } catch (err) {
          showToast(err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }

    const startCountdown = (expiresTs) => {
      const label = document.getElementById('verify-countdown');
      const tick = () => {
        const remaining = Math.max(0, expiresTs - Math.floor(Date.now() / 1000));
        const m = String(Math.floor(remaining / 60)).padStart(2, '0');
        const s = String(remaining % 60).padStart(2, '0');
        if (label) label.textContent = `${m}:${s}`;
        if (remaining <= 0) {
          clearInterval(verifyTimer);
          showToast('Kód vypršel.');
        }
      };
      if (verifyTimer) clearInterval(verifyTimer);
      tick();
      verifyTimer = setInterval(tick, 1000);
    };

    if (formVerify) {
      formVerify.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = formVerify.querySelector('button[type="submit"]');
        setLoading(btn, true);
        try {
          const data = new FormData(formVerify);
          await fetchJson('/api.php?action=verify_code', {
            method: 'POST',
            headers: { 'X-CSRF-Token': csrf },
            body: data
          });
          closeModal('modal-verify');
          showToast('Rezervace potvrzena.');
          await loadWeek();
        } catch (err) {
          showToast(err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }
  };

  const renderAdminTables = (bookings, recurring, rules, audit) => {
    const bookingsEl = document.getElementById('admin-bookings');
    if (bookingsEl) {
      bookingsEl.innerHTML = '';
      const all = [...bookings, ...recurring].sort((a, b) => a.start_ts - b.start_ts);
      all.forEach((b) => {
        const row = document.createElement('div');
        row.className = 'table-row';
        const start = new Date(b.start_ts * 1000);
        const end = new Date(b.end_ts * 1000);
        row.innerHTML = `
          <div>
            <div><strong>${b.name}</strong> (${b.category})</div>
            <div class="meta">${start.toLocaleDateString('cs-CZ')} ${formatTime(start)}–${formatTime(end)} · ${spaceLabels[b.space] || b.space}</div>
          </div>
        `;
        const actions = document.createElement('div');
        if (String(b.id).startsWith('R')) {
          const btn = document.createElement('button');
          btn.className = 'btn ghost';
          btn.textContent = 'Smazat výskyt';
          btn.addEventListener('click', () => deleteOccurrence(b.rule_id, b.date_ts));
          actions.appendChild(btn);
        } else {
          const btn = document.createElement('button');
          btn.className = 'btn ghost';
          btn.textContent = 'Smazat';
          btn.addEventListener('click', () => deleteBooking(b.id));
          actions.appendChild(btn);
        }
        row.appendChild(actions);
        bookingsEl.appendChild(row);
      });
      if (!all.length) {
        bookingsEl.innerHTML = '<div class="hint">Žádné rezervace v týdnu.</div>';
      }
    }

    const rulesEl = document.getElementById('admin-rules');
    if (rulesEl) {
      rulesEl.innerHTML = '';
      (rules || []).forEach((r) => {
        const row = document.createElement('div');
        row.className = 'table-row';
        row.innerHTML = `
          <div>
            <div><strong>${r.title}</strong> (${r.category})</div>
            <div class="meta">${r.dow}. den · ${spaceLabels[r.space] || r.space} · ${minutesToTime(r.start_min)}–${minutesToTime(r.end_min)}</div>
          </div>
        `;
        const btn = document.createElement('button');
        btn.className = 'btn ghost';
        btn.textContent = 'Smazat sérii';
        btn.addEventListener('click', () => deleteRecurring(r.id));
        row.appendChild(btn);
        rulesEl.appendChild(row);
      });
      if (!rules || !rules.length) {
        rulesEl.innerHTML = '<div class="hint">Žádná opakování.</div>';
      }
    }

    const auditEl = document.getElementById('admin-audit');
    if (auditEl) {
      auditEl.innerHTML = '';
      (audit || []).forEach((a) => {
        const row = document.createElement('div');
        row.className = 'table-row';
        const dt = new Date(a.ts * 1000);
        row.innerHTML = `
          <div>
            <div><strong>${a.action}</strong> (${a.actor})</div>
            <div class="meta">${dt.toLocaleString('cs-CZ')}</div>
          </div>
        `;
        auditEl.appendChild(row);
      });
    }
  };

  const adminPost = async (data) => {
    return fetchJson('/admin.php', {
      method: 'POST',
      headers: { 'X-CSRF-Token': csrf },
      body: new URLSearchParams({ ...data, csrf })
    });
  };

  const deleteBooking = async (id) => {
    try {
      await adminPost({ action: 'delete_booking', id: String(id) });
      showToast('Rezervace smazána.');
      loadWeek();
    } catch (err) {
      showToast(err.message);
    }
  };

  const deleteOccurrence = async (ruleId, dateTs) => {
    try {
      await adminPost({ action: 'delete_occurrence', rule_id: String(ruleId), date_ts: String(dateTs) });
      showToast('Výskyt smazán.');
      loadWeek();
    } catch (err) {
      showToast(err.message);
    }
  };

  const deleteRecurring = async (ruleId) => {
    try {
      await adminPost({ action: 'delete_recurring', rule_id: String(ruleId) });
      showToast('Série smazána.');
      loadWeek();
    } catch (err) {
      showToast(err.message);
    }
  };

  const initAdminForms = () => {
    const login = document.getElementById('form-login');
    if (login) {
      login.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = login.querySelector('button[type="submit"]');
        setLoading(btn, true);
        try {
          const data = new FormData(login);
          const res = await fetchJson('/admin.php', { method: 'POST', body: data });
          if (res.ok) {
            location.reload();
          }
        } catch (err) {
          showToast(err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }

    const logout = document.getElementById('btn-logout');
    if (logout) {
      logout.addEventListener('click', async () => {
        await adminPost({ action: 'logout' });
        location.reload();
      });
    }

    const formAdminBook = document.getElementById('form-admin-book');
    if (formAdminBook) {
      formAdminBook.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = formAdminBook.querySelector('button[type="submit"]');
        setLoading(btn, true);
        try {
          const data = new URLSearchParams(new FormData(formAdminBook));
          await fetchJson('/admin.php', { method: 'POST', body: data });
          showToast('Rezervace vytvoøena.');
          formAdminBook.reset();
          loadWeek();
        } catch (err) {
          showToast(err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }

    const formRecurring = document.getElementById('form-recurring');
    if (formRecurring) {
      formRecurring.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = formRecurring.querySelector('button[type="submit"]');
        setLoading(btn, true);
        try {
          const data = new URLSearchParams(new FormData(formRecurring));
          await fetchJson('/admin.php', { method: 'POST', body: data });
          showToast('Opakování vytvoøeno.');
          formRecurring.reset();
          loadWeek();
        } catch (err) {
          showToast(err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }
  };

  wireWeekButtons();
  if (page === 'public') {
    initPublicForms();
  }
  if (page === 'admin') {
    initAdminForms();
  }
  loadWeek();
})();
