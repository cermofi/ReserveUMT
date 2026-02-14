(() => {
  const body = document.body;
  const page = body.dataset.page || 'public';
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const appVersion = body.dataset.appVersion || '';
  const clearLegacyClientCache = () => {
    if ('serviceWorker' in navigator) {
      navigator.serviceWorker.getRegistrations()
        .then((registrations) => Promise.all(registrations.map((registration) => registration.unregister().catch(() => false))))
        .catch(() => {});
    }
    if ('caches' in window) {
      caches.keys()
        .then((keys) => Promise.all(keys.map((key) => caches.delete(key).catch(() => false))))
        .catch(() => {});
    }
  };
  clearLegacyClientCache();
  let maxAdvanceDays = 30;
  let maxEmailReservations = 0;
  let maxDurationHours = 2;
  let requireEmailVerification = true;
  const pluralCz = (n, forms) => {
    const mod100 = n % 100;
    const mod10 = n % 10;
    if (mod100 >= 11 && mod100 <= 14) return forms.many;
    if (mod10 === 1) return forms.one;
    if (mod10 >= 2 && mod10 <= 4) return forms.few;
    return forms.many;
  };
  const updateAdvanceHint = () => {
    const el = document.getElementById('max-advance-hint');
    if (!el) return;
    if (!maxAdvanceDays || maxAdvanceDays <= 0) {
      el.style.display = 'none';
      el.textContent = '';
      return;
    }
    const unit = pluralCz(maxAdvanceDays, { one: 'den', few: 'dny', many: 'dní' });
    el.textContent = `Rezervace je možné udělat ${maxAdvanceDays} ${unit} dopředu.`;
    el.style.display = 'block';
  };

  const updateEmailHint = () => {
    const el = document.getElementById('max-email-hint');
    if (!el) return;
    const requireEmail = requireEmailVerification;
    if (!requireEmail || !maxEmailReservations || maxEmailReservations <= 0) {
      el.style.display = 'none';
      el.textContent = '';
      return;
    }
    const unit = pluralCz(maxEmailReservations, { one: 'rezervaci', few: 'rezervace', many: 'rezervací' });
    el.textContent = `Na jeden e-mail lze mít maximálně ${maxEmailReservations} ${unit}.`;
    el.style.display = 'block';
  };

  const updateDurationHint = () => {
    const el = document.getElementById('max-duration-hint');
    if (!el) return;
    if (!maxDurationHours || maxDurationHours <= 0) {
      el.style.display = 'none';
      el.textContent = '';
      return;
    }
    const unit = pluralCz(maxDurationHours, { one: 'hodina', few: 'hodiny', many: 'hodin' });
    el.textContent = `Maximální délka rezervace je ${maxDurationHours} ${unit}.`;
    el.style.display = 'block';
  };
  const weekStartStr = body.dataset.weekStart;
  const gridStart = body.dataset.gridStart || '06:00';
  const gridEnd = body.dataset.gridEnd || '23:00';
  const stepMin = parseInt(body.dataset.stepMin || '30', 10);
  const spaceLabels = {
    WHOLE: 'Celá UMT',
    HALF_A: body.dataset.spaceLabelA || 'Půlka A',
    HALF_B: body.dataset.spaceLabelB || 'Půlka B'
  };
  const mobileMq = window.matchMedia('(max-width: 1023px)');
  let mobileDayIndex = 0;
  let mobileView = 'week';
  let mobileSelectedSpace = 'WHOLE';
  let mobileBookings = [];
  let mobileRecurring = [];

  const toastEl = document.getElementById('toast');
    const showToast = (msg) => {
      if (!toastEl) return;
      toastEl.textContent = msg;
      toastEl.classList.add('show');
      setTimeout(() => toastEl.classList.remove('show'), 3200);
    };

    const applyMaxLimitsToForm = () => {
      const form = document.getElementById('form-reserve');
      if (form && form.date) {
        setDateLimits(form.date);
        preventDateClear(form.date);
      }
      const weekDate = document.getElementById('week-date');
      if (weekDate) setDateLimits(weekDate);
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

  const formatDayLabel = (date) => {
    return date.toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric', year: 'numeric' });
  };

  const formatCompactDate = (date, includeYear = false) => {
    const day = date.getDate();
    const month = date.getMonth() + 1;
    if (includeYear) {
      return `${day}.${month}.${date.getFullYear()}`;
    }
    return `${day}.${month}.`;
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

  const todayYmd = () => formatYmd(new Date());

  const maxAllowedDate = () => {
    if (maxAdvanceDays === 0) return null;
    const d = new Date();
    d.setHours(0, 0, 0, 0);
    d.setDate(d.getDate() + maxAdvanceDays);
    return formatYmd(d);
  };

  const isWithinMaxLimit = (ymd) => {
    const limit = maxAllowedDate();
    if (!limit) return true;
    return ymd <= limit;
  };

  const setDateLimits = (input, { setMin = false } = {}) => {
    if (!input) return;
    const limit = maxAllowedDate();
    if (limit) input.max = limit; else input.removeAttribute('max');
    if (setMin) input.min = todayYmd(); else input.removeAttribute('min');
  };

  const formResetToDefaults = (form) => {
    if (!form) return;
    form.reset();
    applyMaxLimitsToForm();
    updateAdvanceHint();
    updateEmailHint();
    updateDurationHint();
  };

  const preventDateClear = (input) => {
    if (!input || input.dataset.noclear === '1') return;
    input.dataset.noclear = '1';
    input.dataset.prevDate = input.value || todayYmd();
    input.addEventListener('input', () => {
      if (input.value === '') {
        input.value = input.dataset.prevDate || todayYmd();
      } else {
        input.dataset.prevDate = input.value;
      }
    });
  };

  const weekStart = weekStartStr ? parseYmd(weekStartStr) : new Date();
  const totalMinutes = parseHm(gridEnd) - parseHm(gridStart);

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
      if (closeId === 'modal-reserve') {
        formResetToDefaults(document.getElementById('form-reserve'));
      }
      closeModal(closeId);
    }
  });

  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
      document.querySelectorAll('.modal').forEach((m) => {
        m.setAttribute('aria-hidden', 'true');
        if (m.id === 'modal-reserve') {
          formResetToDefaults(document.getElementById('form-reserve'));
        }
      });
    }
  });

  const fetchJson = async (url, options = {}) => {
    if (!options.credentials) {
      options.credentials = 'same-origin';
    }
    options.cache = 'no-store';
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
      const subHeader = document.createElement('div');
      subHeader.className = 'day-subheader';
      const labelA = document.createElement('span');
      labelA.textContent = 'Půlka A';
      const labelB = document.createElement('span');
      labelB.className = 'right';
      labelB.textContent = 'Půlka B';
      subHeader.appendChild(labelA);
      subHeader.appendChild(labelB);
      const track = document.createElement('div');
      track.className = 'day-track';
      track.style.setProperty('--total-minutes', totalMinutes);
      const laneWrap = document.createElement('div');
      laneWrap.className = 'lane-wrap';
      const laneA = document.createElement('div');
      laneA.className = 'day-lane lane-a';
      const laneB = document.createElement('div');
      laneB.className = 'day-lane lane-b';
      laneWrap.appendChild(laneA);
      laneWrap.appendChild(laneB);
      track.appendChild(laneWrap);
      track.dataset.date = ymd;

      let dragActive = false;
      let dragMoved = false;
      let dragStartY = 0;
      let selectionEl = null;
      let dragStartSpace = null;
      let dragCurrentSpace = null;

      const ensureSelectionElement = (space) => {
        if (!selectionEl) {
          selectionEl = document.createElement('div');
          selectionEl.className = 'selection-box';
        }
        const desiredParent = space === 'WHOLE'
          ? track
          : (space === 'HALF_A' ? laneA : laneB);
        if (selectionEl.parentElement !== desiredParent) {
          selectionEl.remove();
          desiredParent.appendChild(selectionEl);
        }
        if (space === 'WHOLE') {
          selectionEl.classList.add('selection-whole');
        } else {
          selectionEl.classList.remove('selection-whole');
        }
        selectionEl.dataset.space = space;
      };

      const updateSelection = (clientY, selectionSpace) => {
        const rect = track.getBoundingClientRect();
        const pxPerMin = parseFloat(getComputedStyle(track).getPropertyValue('--px-per-min')) || 1;
        const y1 = Math.max(0, Math.min(rect.height, dragStartY));
        const y2 = Math.max(0, Math.min(rect.height, clientY - rect.top));
        const top = Math.min(y1, y2);
        const height = Math.max(6, Math.abs(y2 - y1));
        ensureSelectionElement(selectionSpace);
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

      const getSpaceAtPoint = (clientX, clientY) => {
        const el = document.elementFromPoint(clientX, clientY);
        if (!el) return null;
        const laneEl = el.closest('.day-lane');
        if (!laneEl) return null;
        const dayEl = laneEl.closest('.day-col');
        if (dayEl !== col) return null; // only same day
        if (laneEl.classList.contains('lane-a')) return 'HALF_A';
        if (laneEl.classList.contains('lane-b')) return 'HALF_B';
        return null;
      };

      const endDrag = (clientY) => {
        if (!dragActive) return;
        dragActive = false;
        const laneSpace = selectionEl?.dataset?.space || dragCurrentSpace || dragStartSpace || null;
        const result = updateSelection(clientY, laneSpace || dragStartSpace || 'HALF_A');
        if (selectionEl) {
          selectionEl.remove();
          selectionEl = null;
        }
        selectionTooltip.style.display = 'none';
        if (page !== 'public') return;
        if (result) {
          openReservationModal(ymd, result.start, result.end, laneSpace);
        }
        setTimeout(() => {
          dragMoved = false;
        }, 0);
      };

      const attachLaneHandlers = (lane, laneSpace) => {
        lane.addEventListener('mousedown', (e) => {
          if (page !== 'public') return;
          if (e.button !== 0) return;
          if (e.target && e.target.classList.contains('booking')) return;
          if (!isWithinMaxLimit(ymd)) {
            showToast('Rezervace nelze vytvářet tak daleko dopředu.');
            return;
          }
          e.preventDefault();
          dragActive = true;
          dragMoved = false;
          dragStartY = e.clientY - track.getBoundingClientRect().top;
          dragStartSpace = laneSpace;
          dragCurrentSpace = laneSpace;
          ensureSelectionElement(laneSpace);
          updateSelection(e.clientY, laneSpace);
        });

        lane.addEventListener('click', (e) => {
          if (page !== 'public') return;
          if (dragActive || dragMoved) return;
          const rect = lane.getBoundingClientRect();
          const pxPerMin = parseFloat(getComputedStyle(track).getPropertyValue('--px-per-min')) || 1;
          const offset = e.clientY - rect.top;
          const minutes = Math.max(0, Math.min(totalMinutes - stepMin, Math.round(offset / pxPerMin / stepMin) * stepMin));
          const start = startMin + minutes;
          const end = start + stepMin;
          if (!isWithinMaxLimit(ymd)) {
            showToast('Rezervace nelze vytvářet tak daleko dopředu.');
            return;
          }
          openReservationModal(ymd, start, end, laneSpace);
        });
      };

      attachLaneHandlers(laneA, 'HALF_A');
      attachLaneHandlers(laneB, 'HALF_B');

      const handleMove = (e) => {
        if (!dragActive) return;
        const rect = track.getBoundingClientRect();
        if (rect && Math.abs(e.clientY - (rect.top + dragStartY)) > 4) {
          dragMoved = true;
        }
        const currentSpace = getSpaceAtPoint(e.clientX, e.clientY);
        let selectionSpace = dragStartSpace;
        if (currentSpace) {
          selectionSpace = currentSpace !== dragStartSpace ? 'WHOLE' : dragStartSpace;
        } else if (dragCurrentSpace) {
          selectionSpace = dragCurrentSpace;
        }
        dragCurrentSpace = selectionSpace;
        updateSelection(e.clientY, selectionSpace);
      };

      const handleUp = (e) => {
        if (!dragActive) return;
        endDrag(e.clientY);
      };

      document.addEventListener('mousemove', handleMove);
      document.addEventListener('mouseup', handleUp);
      document.addEventListener('keydown', (e) => {
        if (!dragActive) return;
        if (e.key === 'Escape') {
          dragActive = false;
          dragMoved = false;
          if (selectionEl) {
            selectionEl.remove();
            selectionEl = null;
          }
          selectionTooltip.style.display = 'none';
        }
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
        item.classList.add('cat-0');
        const displayName = (b.name && b.name.trim()) ? b.name : 'Rezervace';
        const head = document.createElement('div');
        head.className = 'booking-head';
        const titleEl = document.createElement('div');
        titleEl.className = 'booking-title';
        titleEl.textContent = displayName;
        head.appendChild(titleEl);

        const timeEl = document.createElement('div');
        timeEl.className = 'booking-time';
        const timeLabel = `${formatTime(startDate)}–${formatTime(endDate)}`;
        const timeShort = formatTime(startDate);
        const isHalf = b.space === 'HALF_A' || b.space === 'HALF_B';
        timeEl.textContent = isHalf ? timeShort : timeLabel;

        const notePart = b.note ? ` — ${b.note}` : '';
        item.title = `${displayName} — ${timeLabel}${notePart}`;
        item.setAttribute('tabindex', '0');

        item.appendChild(head);
        item.appendChild(timeEl);

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
          showToast(`${displayName} · ${timeLabel}`);
        });
        item.addEventListener('keydown', (ev) => {
          if (ev.key === 'Enter' || ev.key === ' ') {
            ev.preventDefault();
            item.click();
          }
        });
        if (b.space === 'HALF_A') {
          laneA.appendChild(item);
        } else if (b.space === 'HALF_B') {
          laneB.appendChild(item);
        } else {
          track.appendChild(item);
        }
      });

      col.appendChild(header);
      col.appendChild(subHeader);
      col.appendChild(track);
      dayCols.appendChild(col);
    }

    calendar.appendChild(timeCol);
    calendar.appendChild(dayCols);

    const updateGridTopVar = () => {
      const firstCol = dayCols.querySelector('.day-col');
      if (!firstCol) return;
      const headerH = firstCol.querySelector('.day-header')?.getBoundingClientRect().height || 0;
      const subH = firstCol.querySelector('.day-subheader')?.getBoundingClientRect().height || 0;
      const gridTop = headerH + subH;
      if (gridTop > 0) {
        calendar.style.setProperty('--grid-top', `${gridTop}px`);
      }
    };

    if (window.__umtGridTopHandler) {
      window.removeEventListener('resize', window.__umtGridTopHandler);
    }
    window.__umtGridTopHandler = () => {
      window.requestAnimationFrame(updateGridTopVar);
    };
    window.addEventListener('resize', window.__umtGridTopHandler);
    updateGridTopVar();
  };

  const renderMobileWeekstrip = () => {
    if (page !== 'public') return;
    if (mobileView !== 'day') return;
    const strip = document.getElementById('mobile-weekstrip');
    if (!strip) return;
    strip.innerHTML = '';
    for (let i = 0; i < 7; i++) {
      const d = new Date(weekStart);
      d.setDate(weekStart.getDate() + i);
      const btn = document.createElement('button');
      btn.type = 'button';
      btn.textContent = d.toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric' });
      if (i === mobileDayIndex) btn.classList.add('active');
      btn.dataset.dayIndex = String(i);
      strip.appendChild(btn);
    }
  };

  const renderMobileDayView = () => {
    if (page !== 'public') return;
    const wrapper = document.getElementById('mobile-calendar');
    const timeline = document.getElementById('mobile-timeline');
    const label = document.getElementById('mobile-day-label');
    if (!wrapper || !timeline || !label) return;
    if (!mobileMq.matches) return;
    if (mobileView !== 'day') {
      const dayWrap = wrapper.querySelector('.mobile-day-wrap');
      if (dayWrap) dayWrap.setAttribute('hidden', 'true');
      return;
    }
    const dayWrap = wrapper.querySelector('.mobile-day-wrap');
    if (dayWrap) dayWrap.removeAttribute('hidden');

    const selectedDate = new Date(weekStart);
    selectedDate.setDate(weekStart.getDate() + mobileDayIndex);
    const ymd = formatYmd(selectedDate);
    label.textContent = formatDayLabel(selectedDate);

    const startMin = parseHm(gridStart);
    const endMin = parseHm(gridEnd);
    const totalMinutes = endMin - startMin;
    timeline.style.setProperty('--total-minutes', totalMinutes);
    timeline.innerHTML = '';

    const timeCol = document.createElement('div');
    timeCol.className = 'mobile-time-col';
    const timeLabels = document.createElement('div');
    timeLabels.className = 'mobile-time-labels';
    timeLabels.style.setProperty('--total-minutes', totalMinutes);
    for (let m = startMin; m <= endMin; m += 60) {
      const labelEl = document.createElement('div');
      labelEl.className = 'mobile-time-label';
      const h = String(Math.floor(m / 60)).padStart(2, '0');
      labelEl.textContent = `${h}:00`;
      labelEl.style.top = `calc(${m - startMin} * var(--px-per-min-mobile))`;
      timeLabels.appendChild(labelEl);
    }
    timeCol.appendChild(timeLabels);

    const dayCol = document.createElement('div');
    dayCol.className = 'mobile-day-track';
    dayCol.style.setProperty('--total-minutes', totalMinutes);

    const laneWrap = document.createElement('div');
    laneWrap.className = 'mobile-lane-wrap';
    const laneA = document.createElement('div');
    laneA.className = 'mobile-lane lane-a';
    const laneB = document.createElement('div');
    laneB.className = 'mobile-lane lane-b';
    laneWrap.appendChild(laneA);
    laneWrap.appendChild(laneB);
    dayCol.appendChild(laneWrap);
    dayCol.dataset.date = ymd;

    const pxPerMin = parseFloat(getComputedStyle(timeline).getPropertyValue('--px-per-min-mobile')) || 1.15;

    const openQuickCreate = (event, laneSpace) => {
      const rect = event.currentTarget.getBoundingClientRect();
      const offset = event.clientY - rect.top;
      const minutes = Math.max(0, Math.min(totalMinutes - stepMin, Math.round(offset / pxPerMin / stepMin) * stepMin));
      const start = startMin + minutes;
      const end = start + stepMin;
      if (!isWithinMaxLimit(ymd)) {
        showToast('Rezervace nelze vytvářet tak daleko dopředu.');
        return;
      }
      openReservationModal(ymd, start, end, laneSpace);
    };

    laneA.addEventListener('click', (e) => {
      if (mobileSelectedSpace === 'WHOLE') return;
      openQuickCreate(e, 'HALF_A');
    });
    laneB.addEventListener('click', (e) => {
      if (mobileSelectedSpace === 'WHOLE') return;
      openQuickCreate(e, 'HALF_B');
    });
    laneWrap.addEventListener('click', (e) => {
      if (mobileSelectedSpace !== 'WHOLE') return;
      openQuickCreate(e, 'WHOLE');
    });

    const all = [...mobileBookings, ...mobileRecurring];
    all.filter(b => {
      const d = new Date(b.start_ts * 1000);
      return formatYmd(d) === ymd;
    }).forEach((b) => {
      const item = document.createElement('div');
      item.className = 'm-booking';
      if (b.space === 'WHOLE') item.classList.add('whole');
      const startDate = new Date(b.start_ts * 1000);
      const endDate = new Date(b.end_ts * 1000);
      const minutesFromStart = (startDate.getHours() * 60 + startDate.getMinutes()) - startMin;
      const duration = (endDate - startDate) / 60000;
      item.style.top = `calc(${minutesFromStart} * var(--px-per-min-mobile))`;
      item.style.height = `calc(${duration} * var(--px-per-min-mobile))`;
      const title = document.createElement('div');
      title.className = 'm-booking-title';
      title.textContent = (b.name && b.name.trim()) ? b.name : 'Rezervace';
      const time = document.createElement('div');
      time.className = 'm-booking-time';
      time.textContent = `${formatTime(startDate)}–${formatTime(endDate)}`;
      item.appendChild(title);
      item.appendChild(time);
      item.addEventListener('click', (ev) => {
        ev.stopPropagation();
        showToast(`${title.textContent} · ${time.textContent}`);
      });
      if (b.space === 'HALF_A') {
        laneA.appendChild(item);
      } else if (b.space === 'HALF_B') {
        laneB.appendChild(item);
      } else {
        dayCol.appendChild(item);
      }
    });

    timeline.appendChild(timeCol);
    timeline.appendChild(dayCol);
  };

  const renderMobileWeekGrid = () => {
    if (page !== 'public') return;
    const wrapOuter = document.getElementById('m-week-grid');
    if (!wrapOuter || !mobileMq.matches) return;
    if (mobileView !== 'week') {
      wrapOuter.innerHTML = '';
      wrapOuter.setAttribute('hidden', 'true');
      return;
    }
    wrapOuter.removeAttribute('hidden');
    wrapOuter.innerHTML = '';
    const wrap = document.createElement('div');
    wrap.className = 'm-week-grid';
    wrapOuter.appendChild(wrap);

    const startMin = parseHm(gridStart);
    const endMin = parseHm(gridEnd);
    const totalMinutes = endMin - startMin;
    wrap.style.setProperty('--total-minutes', totalMinutes);

    const timeCol = document.createElement('div');
    timeCol.className = 'm-week-time';
    const labels = document.createElement('div');
    labels.className = 'm-week-time-labels';
    labels.style.setProperty('--total-minutes', totalMinutes);
    for (let m = startMin; m <= endMin; m += 60) {
      const l = document.createElement('div');
      l.className = 'm-week-time-label';
      const h = String(Math.floor(m / 60)).padStart(2, '0');
      l.textContent = `${h}:00`;
      l.style.top = `calc(${m - startMin} * var(--px-per-min-mobile))`;
      labels.appendChild(l);
    }
    timeCol.appendChild(labels);
    wrap.appendChild(timeCol);

    const all = [...mobileBookings, ...mobileRecurring];

    for (let i = 0; i < 7; i++) {
      const dayDate = new Date(weekStart);
      dayDate.setDate(weekStart.getDate() + i);
      const ymd = formatYmd(dayDate);
      const dayCol = document.createElement('div');
      dayCol.className = 'm-week-day';
      const head = document.createElement('div');
      head.className = 'm-week-day-header';
      head.textContent = dayDate.toLocaleDateString('cs-CZ', { weekday: 'short', day: 'numeric', month: 'numeric' });
      dayCol.appendChild(head);

      const track = document.createElement('div');
      track.className = 'm-week-track';
      track.style.setProperty('--total-minutes', totalMinutes);
      track.dataset.date = ymd;
      const useHalfColumns = mobileMq.matches;
      if (useHalfColumns) {
        track.classList.add('has-half-lanes');
      }
      const pxPerMin = parseFloat(getComputedStyle(wrap).getPropertyValue('--px-per-min-mobile')) || 1.15;
      let laneA = null;
      let laneB = null;
      if (useHalfColumns) {
        // Mobile override: keep A/B bookings in separate lanes to prevent overlap.
        const laneWrap = document.createElement('div');
        laneWrap.className = 'm-week-lane-wrap';
        laneA = document.createElement('div');
        laneA.className = 'm-week-lane lane-a';
        laneB = document.createElement('div');
        laneB.className = 'm-week-lane lane-b';
        laneWrap.appendChild(laneA);
        laneWrap.appendChild(laneB);
        track.appendChild(laneWrap);
      }

      track.addEventListener('click', (e) => {
        const rect = track.getBoundingClientRect();
        const offset = e.clientY - rect.top;
        const minutes = Math.max(0, Math.min(totalMinutes - stepMin, Math.round(offset / pxPerMin / stepMin) * stepMin));
        const start = startMin + minutes;
        const end = start + stepMin;
        if (!isWithinMaxLimit(ymd)) {
          showToast('Rezervace nelze vytvářet tak daleko dopředu.');
          return;
        }
        openReservationModal(ymd, start, end, mobileSelectedSpace);
      });

      all.filter(b => {
        const d = new Date(b.start_ts * 1000);
        if (formatYmd(d) !== ymd) return false;
        if (mobileSelectedSpace === 'WHOLE') return true;
        return b.space === mobileSelectedSpace;
      }).forEach((b) => {
        const item = document.createElement('div');
        item.className = 'm-week-booking';
        if (b.space === 'WHOLE') {
          item.classList.add('whole');
        } else if (b.space === 'HALF_A') {
          item.classList.add('half-a');
        } else if (b.space === 'HALF_B') {
          item.classList.add('half-b');
        }
        const startDate = new Date(b.start_ts * 1000);
        const endDate = new Date(b.end_ts * 1000);
        const minutesFromStart = (startDate.getHours() * 60 + startDate.getMinutes()) - startMin;
        const duration = (endDate - startDate) / 60000;
        item.style.top = `calc(${minutesFromStart} * var(--px-per-min-mobile))`;
        item.style.height = `calc(${duration} * var(--px-per-min-mobile))`;
        const title = (b.name && b.name.trim()) ? b.name : 'Rezervace';
        const timeLabel = `${formatTime(startDate)}–${formatTime(endDate)}`;
        if (mobileMq.matches) {
          const titleEl = document.createElement('div');
          titleEl.className = 'm-week-booking-title';
          titleEl.textContent = title;
          const timeEl = document.createElement('div');
          timeEl.className = 'm-week-booking-time';
          timeEl.textContent = timeLabel;
          item.appendChild(titleEl);
          item.appendChild(timeEl);
          if (duration <= 45) {
            item.classList.add('is-compact');
          }
        } else {
          item.textContent = `${title} · ${formatTime(startDate)}`;
        }
        if (useHalfColumns && b.space === 'HALF_A' && laneA) {
          laneA.appendChild(item);
        } else if (useHalfColumns && b.space === 'HALF_B' && laneB) {
          laneB.appendChild(item);
        } else {
          track.appendChild(item);
        }
      });

      dayCol.appendChild(track);
      wrap.appendChild(dayCol);
    }
  };

  const enforceMobilePublicDefaults = () => {
    if (page !== 'public' || !mobileMq.matches) return;
    mobileView = 'week';
    mobileSelectedSpace = 'WHOLE';

    const viewToggle = document.querySelector('.m-view-toggle');
    if (viewToggle) {
      viewToggle.querySelectorAll('button[data-view]').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.view === 'week');
      });
    }
    const spaceToggle = document.getElementById('mobile-space-toggle');
    if (spaceToggle) {
      spaceToggle.querySelectorAll('button[data-space]').forEach((btn) => {
        btn.classList.toggle('active', btn.dataset.space === 'WHOLE');
      });
    }

    const url = new URL(window.location.href);
    let changed = false;
    if (url.searchParams.get('view') !== null && url.searchParams.get('view') !== 'week') {
      url.searchParams.set('view', 'week');
      changed = true;
    }
    if (url.searchParams.get('space') !== null && url.searchParams.get('space') !== 'WHOLE') {
      url.searchParams.set('space', 'WHOLE');
      changed = true;
    }
    if (changed) {
      window.history.replaceState({}, '', `${url.pathname}${url.search}${url.hash}`);
    }
  };

  const initMobileControls = () => {
    if (page !== 'public') return;
    const strip = document.getElementById('mobile-weekstrip');
    const prev = document.getElementById('mobile-day-prev');
    const next = document.getElementById('mobile-day-next');
    const todayBtn = document.getElementById('week-today');
    const spaceToggle = document.getElementById('mobile-space-toggle');
    const mPrev = document.getElementById('m-week-prev');
    const mNext = document.getElementById('m-week-next');
    const mToday = document.getElementById('m-week-today');
    const mDateTrigger = document.getElementById('m-week-date-trigger');
    const weekPrev = document.getElementById('week-prev');
    const weekNext = document.getElementById('week-next');
    const weekDateTrigger = document.getElementById('week-date-trigger');
    const btnNew = document.getElementById('btn-new');
    const mBtnNew = document.getElementById('m-btn-new');
    const viewToggle = document.querySelector('.m-view-toggle');
    const viewButtons = viewToggle ? viewToggle.querySelectorAll('button[data-view]') : [];
    if (mobileMq.matches) {
      enforceMobilePublicDefaults();
    } else {
      mobileView = 'week';
    }

    const applyView = () => {
      if (mobileMq.matches) {
        enforceMobilePublicDefaults();
      }
      body.classList.toggle('mobile-view-week', mobileView === 'week');
      body.classList.toggle('mobile-view-day', mobileView === 'day');
      viewButtons.forEach(btn => btn.classList.toggle('active', btn.dataset.view === mobileView));
      if (mobileView === 'week') {
        renderMobileWeekGrid();
        renderMobileDayView();
        const stripEl = document.getElementById('mobile-weekstrip');
        if (stripEl) stripEl.setAttribute('hidden', 'true');
      } else {
        renderMobileWeekstrip();
        renderMobileDayView();
        const wrapOuter = document.getElementById('m-week-grid');
        if (wrapOuter) wrapOuter.setAttribute('hidden', 'true');
        const stripEl = document.getElementById('mobile-weekstrip');
        if (stripEl) stripEl.removeAttribute('hidden');
      }
    };

    viewButtons.forEach(btn => {
      btn.addEventListener('click', () => {
        if (mobileMq.matches) {
          enforceMobilePublicDefaults();
          applyView();
          return;
        }
        mobileView = btn.dataset.view || 'week';
        applyView();
      });
    });

    if (strip) {
      strip.addEventListener('click', (e) => {
        const btn = e.target.closest('button[data-day-index]');
        if (!btn) return;
        mobileDayIndex = parseInt(btn.dataset.dayIndex || '0', 10);
        renderMobileWeekstrip();
        renderMobileDayView();
        renderMobileWeekGrid();
      });
    }
    if (prev) {
      prev.addEventListener('click', () => {
        mobileDayIndex = Math.max(0, mobileDayIndex - 1);
        renderMobileWeekstrip();
        renderMobileDayView();
        renderMobileWeekGrid();
      });
    }
    if (next) {
      next.addEventListener('click', () => {
        mobileDayIndex = Math.min(6, mobileDayIndex + 1);
        renderMobileWeekstrip();
        renderMobileDayView();
        renderMobileWeekGrid();
      });
    }
    if (todayBtn) {
      todayBtn.addEventListener('click', () => {
        const now = new Date();
        const monday = new Date(now);
        const day = monday.getDay();
        const diff = (day === 0 ? -6 : 1 - day);
        monday.setDate(monday.getDate() + diff);
        monday.setHours(0, 0, 0, 0);
        mobileDayIndex = Math.min(6, Math.max(0, Math.round((now - monday) / 86400000)));
        renderMobileWeekstrip();
        renderMobileDayView();
        renderMobileWeekGrid();
      });
    }
    if (spaceToggle) {
      spaceToggle.addEventListener('click', (e) => {
        if (mobileMq.matches) {
          enforceMobilePublicDefaults();
          renderMobileDayView();
          renderMobileWeekGrid();
          return;
        }
        const btn = e.target.closest('button[data-space]');
        if (!btn) return;
        mobileSelectedSpace = btn.dataset.space || 'HALF_A';
        spaceToggle.querySelectorAll('button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        renderMobileDayView();
        renderMobileWeekGrid();
      });
    }
    mobileMq.addEventListener('change', (e) => {
      if (e.matches) {
        enforceMobilePublicDefaults();
        renderMobileWeekstrip();
        renderMobileDayView();
        renderMobileWeekGrid();
      } else {
        body.classList.remove('mobile-view-week', 'mobile-view-day');
      }
    });
    applyView();

    if (mPrev && weekPrev) mPrev.addEventListener('click', () => weekPrev.click());
    if (mNext && weekNext) mNext.addEventListener('click', () => weekNext.click());
    if (mToday && todayBtn) mToday.addEventListener('click', () => todayBtn.click());
    if (mDateTrigger && weekDateTrigger) mDateTrigger.addEventListener('click', () => weekDateTrigger.click());
    if (mBtnNew && btnNew) mBtnNew.addEventListener('click', () => btnNew.click());
  };

  initMobileControls();

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
    const desktopLabel = `${weekStart.toLocaleDateString('cs-CZ')} – ${end.toLocaleDateString('cs-CZ')}`;
    label.textContent = desktopLabel;
    const mLabel = document.getElementById('m-week-label');
    if (mLabel) {
      const sameYear = weekStart.getFullYear() === end.getFullYear();
      const startCompact = formatCompactDate(weekStart, !sameYear);
      const endCompact = formatCompactDate(end, true);
      mLabel.textContent = `${startCompact}–${endCompact}`;
      mLabel.title = desktopLabel;
    }
  };

  const renderDataLoadError = () => {
    const message = '<div class="panel warning">Nepodařilo se načíst aktuální data.</div>';
    mobileBookings = [];
    mobileRecurring = [];

    const calendar = document.getElementById('calendar');
    if (calendar) calendar.innerHTML = message;
    const agenda = document.getElementById('agenda');
    if (agenda) agenda.innerHTML = message;
    const mobileWeekGrid = document.getElementById('m-week-grid');
    if (mobileWeekGrid) {
      mobileWeekGrid.removeAttribute('hidden');
      mobileWeekGrid.innerHTML = message;
    }
    const mobileTimeline = document.getElementById('mobile-timeline');
    if (mobileTimeline) mobileTimeline.innerHTML = message;
    const mobileWeekStrip = document.getElementById('mobile-weekstrip');
    if (mobileWeekStrip) mobileWeekStrip.innerHTML = '';
    const mobileDayLabel = document.getElementById('mobile-day-label');
    if (mobileDayLabel) mobileDayLabel.textContent = '';
    const adminBookings = document.getElementById('admin-bookings');
    if (adminBookings) adminBookings.innerHTML = message;
    const adminRules = document.getElementById('admin-rules');
    if (adminRules) adminRules.innerHTML = message;
    const adminAudit = document.getElementById('admin-audit');
    if (adminAudit) adminAudit.innerHTML = message;
  };

  const loadWeek = async () => {
    updateWeekLabel();
    const from = Math.floor(weekStart.getTime() / 1000);
    const to = Math.floor((weekStart.getTime() + 7 * 86400000) / 1000);
    mobileDayIndex = 0;
    try {
      if (page === 'admin') {
        const res = await fetchJson('/admin.php', {
          method: 'POST',
          headers: { 'X-CSRF-Token': csrf },
          body: new URLSearchParams({ action: 'list_admin', csrf, from: String(from), to: String(to) })
        });
        renderCalendar(res.bookings, res.recurring);
        renderAgenda(res.bookings, res.recurring);
        if (mobileMq.matches) {
          mobileBookings = res.bookings;
          mobileRecurring = res.recurring;
          renderMobileWeekstrip();
          renderMobileDayView();
          renderMobileWeekGrid();
        }
        renderAdminTables(res.bookings, res.recurring, res.rules, res.audit);
        const toggle = document.getElementById('toggle-verify');
        if (toggle) {
          toggle.checked = String(res.require_email_verification || '1') === '1';
        }
        if (typeof res.max_advance_booking_days === 'number') {
          maxAdvanceDays = res.max_advance_booking_days;
          const inputMax = document.getElementById('input-max-advance');
          if (inputMax) inputMax.value = String(maxAdvanceDays);
          const formAdminBook = document.getElementById('form-admin-book');
          if (formAdminBook && formAdminBook.date) setDateLimits(formAdminBook.date, { setMin: true });
        }
        if (typeof res.max_reservations_per_email === 'number') {
          maxEmailReservations = res.max_reservations_per_email;
          const inputMaxEmail = document.getElementById('input-max-email');
          if (inputMaxEmail) inputMaxEmail.value = String(maxEmailReservations);
        }
        if (typeof res.max_reservation_duration_hours === 'number') {
          maxDurationHours = res.max_reservation_duration_hours;
          const inputMaxDur = document.getElementById('input-max-duration');
          if (inputMaxDur) inputMaxDur.value = String(maxDurationHours);
        }
        return;
      }
      const res = await fetchJson(`/api.php?action=list&from=${from}&to=${to}`);
      renderCalendar(res.bookings, res.recurring);
      renderAgenda(res.bookings, res.recurring);
      if (page === 'public') {
        mobileBookings = res.bookings;
        mobileRecurring = res.recurring;
        if (mobileMq.matches) {
          renderMobileWeekstrip();
          renderMobileDayView();
          renderMobileWeekGrid();
        }
      }
    } catch (_) {
      renderDataLoadError();
      showToast('Nepodařilo se načíst aktuální data.');
    }
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
      const targetYmd = formatYmd(monday);
      const limit = maxAllowedDate();
      if (limit && targetYmd > limit) {
        showToast('Nelze zobrazit týden tak daleko dopředu.');
        syncDateInput();
        return;
      }
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
      const limit = maxAllowedDate();
      const targetYmd = formatYmd(weekStart);
      if (limit && targetYmd > limit) {
        weekStart.setDate(weekStart.getDate() - 7);
        showToast('Nelze zobrazit týden tak daleko dopředu.');
        return;
      }
      loadWeek();
      syncDateInput();
    });
    if (dateInput) {
      dateInput.addEventListener('change', () => jumpToDate(dateInput.value));
      const limit = maxAllowedDate();
      if (limit) {
        dateInput.max = limit;
      } else {
        dateInput.removeAttribute('max');
      }
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

  const openReservationModal = (dateStr, startMin, endMin, spaceChoice = null) => {
    const form = document.getElementById('form-reserve');
    if (!form) return;
    const limit = maxAllowedDate();
    if (limit && dateStr > limit) {
      showToast('Rezervace nelze vytvářet tak daleko dopředu.');
      return;
    }
    formResetToDefaults(form);
    form.date.value = dateStr;
    form.date.dataset.prevDate = form.date.value;
    const baseDate = parseYmd(dateStr);
    const start = new Date(baseDate);
    start.setHours(0, 0, 0, 0);
    start.setMinutes(startMin);
    const end = new Date(baseDate);
    end.setHours(0, 0, 0, 0);
    end.setMinutes(endMin);
    form.start.value = formatTime(start);
    form.end.value = formatTime(end);
    if (spaceChoice && form.space) {
      form.space.value = spaceChoice;
    }
    if (limit) form.date.max = limit; else form.date.removeAttribute('max');
    openModal('modal-reserve');
  };

  const openEditModal = (booking) => {
    const form = document.getElementById('form-edit');
    if (!form) return;
    form.reset();
    const start = new Date(booking.start_ts * 1000);
    const end = new Date(booking.end_ts * 1000);
    form.id.value = booking.id;
    form.date.value = formatYmd(start);
    form.start.value = formatTime(start);
    form.end.value = formatTime(end);
    form.name.value = booking.name || '';
    if (form.email) form.email.value = booking.email || '';
    form.space.value = booking.space || 'WHOLE';
    if (form.note) form.note.value = booking.note || '';
    if (form.date) setDateLimits(form.date, { setMin: false });
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
    applyMaxLimitsToForm();
    updateAdvanceHint();
    updateEmailHint();
    updateDurationHint();
    if (btnNew) {
      btnNew.addEventListener('click', () => {
        const today = new Date();
        const todayYmd = formatYmd(today);
        const limit = maxAllowedDate();
          if (limit && todayYmd > limit) {
            showToast('Rezervace nelze vytvářet tak daleko dopředu.');
            return;
          }
          openReservationModal(todayYmd, parseHm(gridStart), parseHm(gridStart) + stepMin);
        });
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
      .then((res) => {
        const requireVerify = String(res.require_email_verification || '1') === '1';
        applyVerifySetting(requireVerify);
        requireEmailVerification = requireVerify;
        if (typeof res.max_advance_booking_days === 'number') {
          maxAdvanceDays = res.max_advance_booking_days;
        }
        if (typeof res.max_reservations_per_email === 'number') {
          maxEmailReservations = res.max_reservations_per_email;
        }
        if (typeof res.max_reservation_duration_hours === 'number') {
          maxDurationHours = res.max_reservation_duration_hours;
        }
        applyMaxLimitsToForm();
        updateAdvanceHint();
        updateEmailHint();
        updateDurationHint();
      })
      .catch(() => {
        applyVerifySetting(true);
        requireEmailVerification = true;
        updateEmailHint();
      });

    const getMaxDurationMinutes = () => (maxDurationHours && maxDurationHours > 0) ? maxDurationHours * 60 : 0;

    const updateDurationWarning = () => {
      if (!formReserve || !durationWarning) return;
      const start = formReserve.start.value;
      const end = formReserve.end.value;
      if (!start || !end) {
        durationWarning.classList.remove('show');
        durationWarning.textContent = '';
        return;
      }
      const maxDurationMinutes = getMaxDurationMinutes();
      const duration = parseHm(end) - parseHm(start);
      if (maxDurationMinutes > 0 && duration > maxDurationMinutes) {
        durationWarning.textContent = `Délka rezervace přesahuje ${maxDurationHours} hodin.`;
        durationWarning.classList.add('show');
      } else {
        durationWarning.classList.remove('show');
        durationWarning.textContent = '';
      }
    };

      if (formReserve) {
      const enforceDurationClamp = () => {
        if (!formReserve) return;
        const startInput = formReserve.start;
        const endInput = formReserve.end;
        if (!startInput || !endInput || !startInput.value || !endInput.value) return;
        const maxDurationMinutes = getMaxDurationMinutes();
        let startMin = parseHm(startInput.value);
        let endMin = parseHm(endInput.value);
        if (endMin < startMin) {
          endMin = startMin;
        }
        if (maxDurationMinutes > 0 && (endMin - startMin) > maxDurationMinutes) {
          endMin = startMin + maxDurationMinutes;
        }
        endInput.value = minutesToTime(endMin);
      };

      formReserve.querySelectorAll('[data-time-adjust]').forEach((btn) => {
        btn.addEventListener('click', () => {
          const field = btn.dataset.timeAdjust;
          const delta = parseInt(btn.dataset.delta || '0', 10);
          const input = formReserve.querySelector(`input[name="${field}"]`);
          if (!input || !input.value) return;
          const current = parseHm(input.value);
          const next = Math.max(0, Math.min(24 * 60 - stepMin, current + delta));
          input.value = minutesToTime(next);
          enforceDurationClamp();
          const event = new Event('change', { bubbles: true });
          input.dispatchEvent(event);
        });
      });

        formReserve.start.addEventListener('change', updateDurationWarning);
        formReserve.end.addEventListener('change', updateDurationWarning);
        formReserve.start.addEventListener('change', enforceDurationClamp);
        formReserve.end.addEventListener('change', enforceDurationClamp);
        applyMaxLimitsToForm();
      formReserve.addEventListener('submit', async (e) => {
        e.preventDefault();
        const duration = parseHm(formReserve.end.value || '00:00') - parseHm(formReserve.start.value || '00:00');
        const maxDurationMinutes = getMaxDurationMinutes();
        if (maxDurationMinutes > 0 && duration > maxDurationMinutes) {
          updateDurationWarning();
          showToast('Délka rezervace přesahuje povolený limit.');
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
            formResetToDefaults(formReserve);
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
            <div><strong>${b.name}</strong></div>
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
            <div><strong>${r.title}</strong></div>
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
      setDateLimits(formAdminBook.date, { setMin: true });
      formAdminBook.addEventListener('submit', async (e) => {
        e.preventDefault();
        const btn = formAdminBook.querySelector('button[type="submit"]');
        setLoading(btn, true);
        try {
          const data = new URLSearchParams(new FormData(formAdminBook));
          const dateVal = formAdminBook.date?.value || '';
          const limit = maxAllowedDate();
          if (limit && dateVal > limit) {
            showToast('Rezervaci nelze vytvořit tak daleko dopředu.');
            setLoading(btn, false);
            return;
          }
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

    const inputMax = document.getElementById('input-max-advance');
    if (inputMax) {
      inputMax.addEventListener('change', async () => {
        const val = inputMax.value.trim();
        if (!/^\d+$/.test(val)) {
          showToast('Zadejte celé číslo.');
          return;
        }
        try {
          await adminPost({
            action: 'set_setting',
            key: 'max_advance_booking_days',
            value: val
          });
          maxAdvanceDays = parseInt(val, 10);
          const formAdminBook = document.getElementById('form-admin-book');
          if (formAdminBook && formAdminBook.date) setDateLimits(formAdminBook.date, { setMin: true });
          showToast('Nastavení uloženo.');
        } catch (err) {
          showToast(err.message);
        }
      });
    }

    const inputMaxEmail = document.getElementById('input-max-email');
    if (inputMaxEmail) {
      inputMaxEmail.addEventListener('change', async () => {
        const val = inputMaxEmail.value.trim();
        if (!/^\d+$/.test(val)) {
          showToast('Zadejte celé číslo.');
          return;
        }
        try {
          await adminPost({
            action: 'set_setting',
            key: 'max_reservations_per_email',
            value: val
          });
          maxEmailReservations = parseInt(val, 10);
          updateEmailHint();
          showToast('Nastavení uloženo.');
        } catch (err) {
          showToast(err.message);
        }
      });
    }

    const inputMaxDuration = document.getElementById('input-max-duration');
    if (inputMaxDuration) {
      inputMaxDuration.addEventListener('change', async () => {
        const val = inputMaxDuration.value.trim();
        if (!/^\d+(\.\d+)?$/.test(val)) {
          showToast('Zadejte číslo (hodiny).');
          return;
        }
        const num = parseFloat(val);
        if (!Number.isFinite(num) || num < 0) {
          showToast('Zadejte číslo (hodiny).');
          return;
        }
        try {
          await adminPost({
            action: 'set_setting',
            key: 'max_reservation_duration_hours',
            value: String(num)
          });
          maxDurationHours = num;
          updateDurationHint();
          showToast('Nastavení uloženo.');
        } catch (err) {
          showToast(err.message);
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
          const dateVal = formEdit.date?.value || '';
          const limit = maxAllowedDate();
          if (limit && dateVal > limit) {
            showToast('Rezervaci nelze upravit tak daleko dopředu.');
            setLoading(btn, false);
            return;
          }
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
