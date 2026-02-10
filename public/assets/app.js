(() => {
  const body = document.body;
  const page = body.dataset.page || 'public';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const appVersion = body.dataset.appVersion || '';
  const weekStartStr = body.dataset.weekStart;
  const gridStart = body.dataset.gridStart || '06:00';
  const gridEnd = body.dataset.gridEnd || '23:00';
  const stepMin = parseInt(body.dataset.stepMin || '30', 10);
  const spaceLabels = {
    WHOLE: 'Celá UMT',
    HALF_A: body.dataset.spaceLabelA || 'Půlka A',
    HALF_B: body.dataset.spaceLabelB || 'Půlka B'
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
    if (!options.credentials) {
      options.credentials = 'same-origin';
    }
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
    timeLabels.style.setProperty('--total-minutes', totalMinutes);

    const startMin = parseHm(gridStart);
    for (let m = startMin; m <= startMin + totalMinutes; m += 60) {
      const label = document.createElement('div');
      label.className = 'time-label';
      const h = String(Math.floor(m / 60)).padStart(2, '0');
      label.textContent = `${h}:00`;
      label.style.top = `calc(${m - startMin} * var(--px-per-min))`;
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
      header.textContent = dayDate.toLocaleDateString('cs-CZ', { weekday: 'short', day: '2-digit', month: '2-digit', year: 'numeric' });
      const track = document.createElement('div');
      track.className = 'day-track';
      track.style.setProperty('--total-minutes', totalMinutes);
      track.dataset.date = ymd;

      let dragActive = false;
      let dragMoved = false;
      let dragStartY = 0;
      let selectionEl = null;

      const updateSelection = (clientY) => {
        const rect = track.getBoundingClientRect();
        const pxPerMin = parseFloat(getComputedStyle(track).getPropertyValue('--px-per-min')) || 1;
        const y1 = Math.max(0, Math.min(rect.height, dragStartY));
        const y2 = Math.max(0, Math.min(rect.height, clientY - rect.top));
        const top = Math.min(y1, y2);
        const height = Math.max(6, Math.abs(y2 - y1));
        if (selectionEl) {
          selectionEl.style.top = `${top}px`;
          selectionEl.style.height = `${height}px`;
        }
        const startOffset = Math.round(top / pxPerMin / stepMin) * stepMin;
        const endOffset = Math.round((top + height) / pxPerMin / stepMin) * stepMin;
        const start = startMin + startOffset;
        const end = Math.max(start + stepMin, startMin + endOffset);
        selectionTooltip.textContent = `${minutesToTime(start)} – ${minutesToTime(end)}`;
        selectionTooltip.style.left = `${rect.right + 12}px`;
        selectionTooltip.style.top = `${Math.min(window.innerHeight - 30, rect.top + top)}px`;
        selectionTooltip.style.display = 'block';
        return { start, end };
      };

      const endDrag = (clientY) => {
        if (!dragActive) return;
        dragActive = false;
        const result = updateSelection(clientY);
        if (selectionEl) {
          selectionEl.remove();
          selectionEl = null;
        }
        selectionTooltip.style.display = 'none';
        if (page !== 'public') return;
        if (result) {
          openReservationModal(ymd, result.start, result.end);
        }
        setTimeout(() => {
          dragMoved = false;
        }, 0);
      };

      track.addEventListener('mousedown', (e) => {
        if (page !== 'public') return;
        if (e.button !== 0) return;
        if (e.target && e.target.classList.contains('booking')) return;
        e.preventDefault();
        dragActive = true;
        dragMoved = false;
        dragStartY = e.clientY - track.getBoundingClientRect().top;
        selectionEl = document.createElement('div');
        selectionEl.className = 'selection-box';
        track.appendChild(selectionEl);
        updateSelection(e.clientY);
      });

      window.addEventListener('mousemove', (e) => {
        if (!dragActive) return;
        if (Math.abs(e.clientY - (track.getBoundingClientRect().top + dragStartY)) > 4) {
          dragMoved = true;
        }
        updateSelection(e.clientY);
      });

      window.addEventListener('mouseup', (e) => {
        if (!dragActive) return;
        endDrag(e.clientY);
      });

      track.addEventListener('click', (e) => {
        if (page !== 'public') return;
        if (dragActive || dragMoved) return;
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
        const spaceClass = b.space === 'WHOLE' ? 'space-whole' : (b.space === 'HALF_A' ? 'space-a' : 'space-b');
        item.className = `booking ${spaceClass} ${b.space === 'WHOLE' ? 'whole' : (b.space === 'HALF_A' ? 'half-a' : 'half-b')}`;
        const startDate = new Date(b.start_ts * 1000);
        const endDate = new Date(b.end_ts * 1000);
        const minutesFromStart = (startDate.getHours() * 60 + startDate.getMinutes()) - startMin;
        const duration = (endDate - startDate) / 60000;
        if (duration <= 30) {
          item.classList.add('tiny');
        } else if (duration <= 45) {
          item.classList.add('small');
        }
        item.style.top = `calc(${minutesFromStart} * var(--px-per-min))`;
        item.style.height = `calc(${duration} * var(--px-per-min))`;
        const idx = categoryIndex(b.category || '');
        item.classList.add(`cat-${idx}`);
        const displayName = (b.name && b.name.trim()) ? b.name : 'Rezervace';
        const head = document.createElement('div');
        head.className = 'booking-head';
        const titleEl = document.createElement('div');
        titleEl.className = 'booking-title';
        titleEl.textContent = displayName;
        const spaceCompact = document.createElement('span');
        spaceCompact.className = 'space-compact';
        const spaceShort = b.space === 'WHOLE' ? 'UMT' : (b.space === 'HALF_A' ? 'A' : 'B');
        spaceCompact.textContent = spaceShort;
        head.appendChild(titleEl);
        head.appendChild(spaceCompact);

        const meta = document.createElement('div');
        meta.className = 'booking-meta chips';
        if (b.category) {
          const catChip = document.createElement('span');
          catChip.className = 'chip category';
          catChip.textContent = b.category;
          meta.appendChild(catChip);
        }
        const spaceChip = document.createElement('span');
        spaceChip.className = 'chip space';
        spaceChip.textContent = b.space === 'WHOLE' ? 'CELÁ' : (b.space === 'HALF_A' ? 'A' : 'B');
        meta.appendChild(spaceChip);

        const timeLabel = `${formatTime(startDate)}–${formatTime(endDate)}`;
        const notePart = b.note ? ` — ${b.note}` : '';
        item.title = `${displayName} — ${b.category || 'bez kategorie'} — ${spaceLabels[b.space] || b.space} — ${timeLabel}${notePart}`;
        item.setAttribute('tabindex', '0');

        item.appendChild(head);
        item.appendChild(meta);

        // Compact mode for short height blocks
        const applyCompact = () => {
          const h = item.getBoundingClientRect().height || duration * (parseFloat(getComputedStyle(track).getPropertyValue('--px-per-min')) || 1);
          if (h < 52) {
            item.classList.add('compact');
          } else {
            item.classList.remove('compact');
          }
        };
        // Defer to next frame after layout
        requestAnimationFrame(applyCompact);

        item.addEventListener('click', (ev) => {
          ev.stopPropagation();
          if (page === 'admin') {
            if (String(b.id).startsWith('R')) {
              if (confirm('Smazat tento výskyt opakované rezervace?')) {
                deleteOccurrence(b.rule_id, b.date_ts);
              }
              return;
            }
            openEditModal(b);
            return;
          }
          showToast(`${displayName} · ${b.category} · ${spaceLabels[b.space] || b.space}`);
        });
        item.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            item.click();
          }
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
      const title = document.createElement('div');
      title.className = 'booking-title';
      title.textContent = b.name || 'Rezervace';
      const meta = document.createElement('div');
      meta.className = 'meta';
      meta.textContent = `${start.toLocaleDateString('cs-CZ')} ${formatTime(start)}–${formatTime(end)}`;
      const chips = document.createElement('div');
      chips.className = 'chips';
      const chip = document.createElement('span');
      chip.className = 'chip';
      chip.textContent = spaceLabels[b.space] || b.space;
      chips.appendChild(chip);
      item.appendChild(title);
      item.appendChild(meta);
      item.appendChild(chips);
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
      const toggle = document.getElementById('toggle-verify');
      if (toggle) {
        toggle.checked = String(res.require_email_verification || '1') === '1';
      }
      return;
    }
    const res = await fetchJson(`/api.php?action=list&from=${from}&to=${to}`);
    renderCalendar(res.bookings, res.recurring);
    renderAgenda(res.bookings, res.recurring);
  };

  const wireWeekButtons = () => {
    const prev = document.getElementById('week-prev');
    const next = document.getElementById('week-next');
    const today = document.getElementById('week-today');
    const dateInput = document.getElementById('week-date');
    const dateTrigger = document.getElementById('week-date-trigger');

    const syncDateInput = () => {
      if (!dateInput) return;
      dateInput.value = formatYmd(weekStart);
    };

    const jumpToDate = (ymd) => {
      if (!ymd) return;
      const d = parseYmd(ymd);
      if (Number.isNaN(d.getTime())) return;
      const monday = new Date(d);
      const day = monday.getDay();
      const diff = (day === 0 ? -6 : 1 - day);
      monday.setDate(monday.getDate() + diff);
      monday.setHours(0, 0, 0, 0);
      weekStart.setTime(monday.getTime());
      loadWeek();
      syncDateInput();
    };

    syncDateInput();

    if (prev) prev.addEventListener('click', () => {
      weekStart.setDate(weekStart.getDate() - 7);
      loadWeek();
      syncDateInput();
    });
    if (today) today.addEventListener('click', () => {
      const now = new Date();
      const monday = new Date(now);
      const day = monday.getDay();
      const diff = (day === 0 ? -6 : 1 - day);
      monday.setDate(monday.getDate() + diff);
      monday.setHours(0, 0, 0, 0);
      weekStart.setTime(monday.getTime());
      loadWeek();
      syncDateInput();
    });
    if (next) next.addEventListener('click', () => {
      weekStart.setDate(weekStart.getDate() + 7);
      loadWeek();
      syncDateInput();
    });
    if (dateInput) {
      dateInput.addEventListener('change', () => jumpToDate(dateInput.value));
    }
    if (dateTrigger && dateInput) {
      const openPicker = () => {
        if (typeof dateInput.showPicker === 'function') {
          try {
            dateInput.showPicker();
            return;
          } catch (_) {
            // fall through to focus+click
          }
        }
        dateInput.focus({ preventScroll: true });
        dateInput.click();
      };
      dateTrigger.addEventListener('click', (e) => {
        e.preventDefault();
        openPicker();
      });
      dateTrigger.addEventListener('keydown', (e) => {
        if (e.key === 'Enter' || e.key === ' ') {
          e.preventDefault();
          openPicker();
        }
      });
    }
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

  const openEditModal = (booking) => {
    const form = document.getElementById('form-edit');
    if (!form) return;
    const start = new Date(booking.start_ts * 1000);
    const end = new Date(booking.end_ts * 1000);
    form.id.value = booking.id;
    form.date.value = formatYmd(start);
    form.start.value = formatTime(start);
    form.end.value = formatTime(end);
    form.name.value = booking.name || '';
    if (form.email) form.email.value = booking.email || '';
    form.category.value = booking.category || 'Jiné';
    form.space.value = booking.space || 'WHOLE';
    if (form.note) form.note.value = booking.note || '';
    openModal('modal-edit');
  };

  const selectionTooltip = (() => {
    const el = document.createElement('div');
    el.className = 'selection-tooltip';
    document.body.appendChild(el);
    return el;
  })();

  const initPublicForms = () => {
    const btnNew = document.getElementById('btn-new');
    const emailField = document.getElementById('field-email');
    const emailInput = emailField ? emailField.querySelector('input[name="email"]') : null;
    const durationWarning = document.getElementById('duration-warning');
    if (btnNew) {
      btnNew.addEventListener('click', () => openReservationModal(formatYmd(weekStart), parseHm(gridStart), parseHm(gridStart) + stepMin));
    }

    const formReserve = document.getElementById('form-reserve');
    const formVerify = document.getElementById('form-verify');
    let verifyTimer = null;

    const applyVerifySetting = (requireVerify) => {
      if (!emailField || !emailInput) return;
      if (requireVerify) {
        emailField.style.display = '';
        emailInput.required = true;
      } else {
        emailField.style.display = 'none';
        emailInput.required = false;
        emailInput.value = '';
      }
    };

    fetchJson('/api.php?action=settings')
      .then((res) => applyVerifySetting(String(res.require_email_verification || '1') === '1'))
      .catch(() => {
        applyVerifySetting(true);
      });

    const maxDurationMinutes = 120;
    const updateDurationWarning = () => {
      if (!formReserve || !durationWarning) return;
      const start = formReserve.start.value;
      const end = formReserve.end.value;
      if (!start || !end) {
        durationWarning.classList.remove('show');
        durationWarning.textContent = '';
        return;
      }
      const duration = parseHm(end) - parseHm(start);
      if (duration > maxDurationMinutes) {
        durationWarning.textContent = 'Délka rezervace přesahuje 2 hodiny.';
        durationWarning.classList.add('show');
      } else {
        durationWarning.classList.remove('show');
        durationWarning.textContent = '';
      }
    };

    if (formReserve) {
      formReserve.querySelectorAll('[data-time-adjust]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const field = btn.dataset.timeAdjust;
          const delta = parseInt(btn.dataset.delta || '0', 10);
          const input = formReserve.querySelector(`input[name="${field}"]`);
          if (!input || !input.value) return;
          const current = parseHm(input.value);
          const next = Math.max(0, Math.min(24 * 60 - stepMin, current + delta));
          input.value = minutesToTime(next);
          const event = new Event('change', { bubbles: true });
          input.dispatchEvent(event);
        });
      });

      formReserve.start.addEventListener('change', updateDurationWarning);
      formReserve.end.addEventListener('change', updateDurationWarning);
      formReserve.addEventListener('submit', async (e) => {
        e.preventDefault();
        const duration = parseHm(formReserve.end.value || '00:00') - parseHm(formReserve.start.value || '00:00');
        if (duration > maxDurationMinutes) {
          updateDurationWarning();
          showToast('Maximální délka rezervace je 2 hodiny.');
          return;
        }
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
          if (res.booking_id) {
            showToast('Rezervace byla uložena.');
            await loadWeek();
            return;
          }
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
          showToast('Rezervace vytvořena.');
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
          showToast('Opakování vytvořeno.');
          formRecurring.reset();
          loadWeek();
        } catch (err) {
          showToast(err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }

    const toggle = document.getElementById('toggle-verify');
    if (toggle) {
      toggle.addEventListener('change', async () => {
        try {
          await adminPost({
            action: 'set_setting',
            key: 'require_email_verification',
            value: toggle.checked ? '1' : '0'
          });
          showToast('Nastavení uloženo.');
        } catch (err) {
          showToast(err.message);
          toggle.checked = !toggle.checked;
        }
      });
    }

    const clearRate = document.getElementById('btn-clear-rate');
    if (clearRate) {
      clearRate.addEventListener('click', async () => {
        try {
          await adminPost({ action: 'clear_rate_limits' });
          showToast('Rate limit vyčištěn.');
        } catch (err) {
          showToast(err.message);
        }
      });
    }

    const formEdit = document.getElementById('form-edit');
    const btnDelete = document.getElementById('btn-delete-booking');
    if (formEdit) {
      formEdit.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = formEdit.querySelector('button[type=\"submit\"]');
        setLoading(btn, true);
        try {
          const data = new URLSearchParams(new FormData(formEdit));
          await fetchJson('/admin.php', { method: 'POST', body: data });
          showToast('Rezervace upravena.');
          closeModal('modal-edit');
          loadWeek();
        } catch (err) {
          showToast(err.message);
        } finally {
          setLoading(btn, false);
        }
      });
    }
    if (btnDelete) {
      btnDelete.addEventListener('click', async () => {
        const id = formEdit?.id?.value;
        if (!id) return;
        if (!confirm('Opravdu smazat rezervaci?')) return;
        try {
          await adminPost({ action: 'delete_booking', id: String(id) });
          showToast('Rezervace smazána.');
          closeModal('modal-edit');
          loadWeek();
        } catch (err) {
          showToast(err.message);
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

  // Copy version to clipboard on click (nice-to-have, silent fail)
  if (appVersion) {
    const el = document.querySelector('[data-role="app-version"]');
    if (el) {
      el.addEventListener('click', () => {
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(appVersion).catch(() => {});
        }
      });
      el.title = 'Kliknutím zkopírujete verzi';
    }
  }
})();
