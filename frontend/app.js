document.documentElement.classList.add('js');

const parseNetwork = (form) => {
  try { return JSON.parse(form.dataset.routeNetwork || '{}'); } catch { return {}; }
};

function syncAirportDestinations(form) {
  const origin = form.querySelector('[data-airport-origin]');
  const destination = form.querySelector('[data-airport-destination]');
  if (!origin || !destination) return;
  const allowed = parseNetwork(form)[origin.value] || [];
  const previous = destination.value;
  const placeholder = !origin.value ? 'Chọn điểm đi trước' : (allowed.length ? 'Chọn điểm đến' : 'Chưa có đường bay khả dụng');
  const options = [...origin.options].filter((option) => option.value && allowed.includes(option.value));
  destination.replaceChildren(new Option(placeholder, ''), ...options.map((option) => new Option(option.textContent, option.value)));
  destination.value = allowed.includes(previous) ? previous : '';
  destination.disabled = !origin.value || !options.length;
}

const formatRangeValue = (group, value) => {
  const number = Number(value);
  if (group.dataset.rangeUnit === 'money') return `${new Intl.NumberFormat('vi-VN').format(number)} VND`;
  return number >= 60 ? `${Math.floor(number / 60)}h ${String(number % 60).padStart(2, '0')}m` : `${number}m`;
};

const initFlightRanges = (root) => {
  root?.querySelectorAll('[data-flight-range]').forEach((group) => {
    if (group.dataset.rangeReady === 'true') return;
    const dual = group.querySelector('.flight-dual-range');
    const track = group.querySelector('.flight-range-track');
    const lower = group.querySelector('[data-range-bound="min"]');
    const upper = group.querySelector('[data-range-bound="max"]');
    const fill = group.querySelector('[data-range-fill]');
    const lowerHandle = group.querySelector('[data-range-handle="min"]');
    const upperHandle = group.querySelector('[data-range-handle="max"]');
    if (!dual || !track || !lower || !upper || !fill || !lowerHandle || !upperHandle || lower.disabled) return;
    group.dataset.rangeReady = 'true';
    dual.classList.add('is-enhanced');
    const handles = { min: lowerHandle, max: upperHandle };
    const inputs = { min: lower, max: upper };
    for (const side of ['min', 'max']) {
      handles[side].tabIndex = 0;
      handles[side].role = 'slider';
      handles[side].setAttribute('aria-label', side === 'min' ? 'Giá trị tối thiểu' : 'Giá trị tối đa');
    }
    const updateVisual = () => {
      const min = Number(lower.min);
      const max = Number(lower.max);
      const low = Number(lower.value);
      const high = Number(upper.value);
      const span = max - min || 1;
      const lowPercent = ((low - min) / span) * 100;
      const highPercent = ((high - min) / span) * 100;
      fill.style.left = `${lowPercent}%`;
      fill.style.width = `${Math.max(0, highPercent - lowPercent)}%`;
      lowerHandle.style.left = `${lowPercent}%`;
      upperHandle.style.left = `${highPercent}%`;
      group.querySelector('[data-range-output]')?.replaceChildren(document.createTextNode(`${formatRangeValue(group, low)} – ${formatRangeValue(group, high)}`));
      for (const [side, input] of Object.entries(inputs)) {
        handles[side].setAttribute('aria-valuemin', input.min);
        handles[side].setAttribute('aria-valuemax', input.max);
        handles[side].setAttribute('aria-valuenow', input.value);
        handles[side].setAttribute('aria-valuetext', formatRangeValue(group, input.value));
      }
    };
    const setValue = (side, raw, emit = true) => {
      const input = inputs[side];
      const other = inputs[side === 'min' ? 'max' : 'min'];
      const min = Number(input.min);
      const max = Number(input.max);
      let value = Math.max(min, Math.min(max, Math.round(Number(raw))));
      if (side === 'min') value = Math.min(value, Number(other.value));
      else value = Math.max(value, Number(other.value));
      input.value = String(value);
      updateVisual();
      if (emit) input.dispatchEvent(new Event('input', { bubbles: true }));
    };
    const valueFromPointer = (event) => {
      const bounds = track.getBoundingClientRect();
      const ratio = Math.max(0, Math.min(1, (event.clientX - bounds.left) / bounds.width));
      return Number(lower.min) + ratio * (Number(lower.max) - Number(lower.min));
    };
    let activeSide = null;
    let pointerId = null;
    let dragStartValue = 0;
    let dragStartLow = 0;
    let dragStartHigh = 0;
    const chooseSide = (value) => Math.abs(value - Number(lower.value)) <= Math.abs(value - Number(upper.value)) ? 'min' : 'max';
    dual.addEventListener('pointerdown', (event) => {
      if (event.button !== 0 || lower.disabled) return;
      const value = valueFromPointer(event);
      const handle = event.target.closest('[data-range-handle]');
      if (event.target.closest('[data-range-fill]') && !handle) {
        activeSide = 'both';
        dragStartValue = value;
        dragStartLow = Number(lower.value);
        dragStartHigh = Number(upper.value);
      } else {
        activeSide = handle?.dataset.rangeHandle || chooseSide(value);
      }
      pointerId = event.pointerId;
      dual.setPointerCapture?.(pointerId);
      if (activeSide !== 'both') setValue(activeSide, value);
      event.preventDefault();
    });
    dual.addEventListener('pointermove', (event) => {
      if (!activeSide || event.pointerId !== pointerId) return;
      const value = valueFromPointer(event);
      if (activeSide !== 'both') {
        setValue(activeSide, value);
        return;
      }
      const delta = value - dragStartValue;
      const minDelta = Number(lower.min) - dragStartLow;
      const maxDelta = Number(lower.max) - dragStartHigh;
      const offset = Math.max(minDelta, Math.min(maxDelta, delta));
      lower.value = String(Math.round(dragStartLow + offset));
      upper.value = String(Math.round(dragStartHigh + offset));
      updateVisual();
      lower.dispatchEvent(new Event('input', { bubbles: true }));
      upper.dispatchEvent(new Event('input', { bubbles: true }));
    });
    const releasePointer = (event) => {
      if (event.pointerId === pointerId) {
        activeSide = null;
        pointerId = null;
      }
    };
    dual.addEventListener('pointerup', releasePointer);
    dual.addEventListener('pointercancel', releasePointer);
    for (const side of ['min', 'max']) {
      inputs[side].addEventListener('input', () => {
        // Native input events (and browser automation) can change a bound
        // directly, bypassing setValue(). Keep the interval valid before the
        // search request serializes both values.
        if (Number(lower.value) > Number(upper.value)) {
          if (side === 'min') upper.value = lower.value;
          else lower.value = upper.value;
        }
        updateVisual();
      });
      handles[side].addEventListener('keydown', (event) => {
        const input = inputs[side];
        const step = Number(input.step) || 1;
        let next = Number(input.value);
        if (event.key === 'ArrowLeft' || event.key === 'ArrowDown') next -= step;
        else if (event.key === 'ArrowRight' || event.key === 'ArrowUp') next += step;
        else if (event.key === 'Home') next = Number(input.min);
        else if (event.key === 'End') next = Number(input.max);
        else if (event.key === 'PageDown') next -= step * 10;
        else if (event.key === 'PageUp') next += step * 10;
        else return;
        event.preventDefault();
        setValue(side, next);
      });
    }
    updateVisual();
  });
};

document.querySelectorAll('[data-airport-search]').forEach((form) => {
  const origin = form.querySelector('[data-airport-origin]');
  origin?.addEventListener('change', () => syncAirportDestinations(form));
  syncAirportDestinations(form);
});

document.querySelectorAll('[data-home-search]').forEach((form) => {
  const type = form.elements.namedItem('trip_type');
  const departure = form.elements.namedItem('date');
  const returning = form.elements.namedItem('return_date');
  const sync = () => {
    const roundTrip = type?.value === 'ROUND_TRIP';
    const returnField = form.querySelector('[data-home-return]');
    if (returnField) returnField.hidden = !roundTrip;
    if (returning) {
      returning.disabled = !roundTrip;
      returning.required = roundTrip;
      returning.min = departure?.value || form.dataset.defaultDate || '';
      if (roundTrip && departure?.value && (!returning.value || returning.value < departure.value)) {
        const date = new Date(`${departure.value}T12:00:00Z`);
        date.setUTCDate(date.getUTCDate() + 3);
        returning.value = date.toISOString().slice(0, 10);
      }
    }
  };
  type?.addEventListener('change', sync);
  departure?.addEventListener('change', sync);
  sync();
});

document.querySelectorAll('[data-flight-search]').forEach((form) => {
  const results = document.querySelector('[data-flight-search-results]');
  const feedback = document.querySelector('[data-flight-search-feedback]');
  const preview = document.querySelector('[data-trip-preview]');
  let controller;
  let timer;
  let previewButton;

  const syncTripDates = () => {
    const type = form.elements.namedItem('trip_type');
    const departure = form.elements.namedItem('date');
    const returning = form.elements.namedItem('return_date');
    const outbound = form.elements.namedItem('outbound');
    const returnField = form.querySelector('[data-return-date-field]');
    if (!type || !returning) return;
    const roundTrip = type.value === 'ROUND_TRIP';
    const choosingReturn = Boolean(outbound?.value);
    if (returnField) returnField.hidden = !roundTrip || choosingReturn;
    returning.disabled = !roundTrip || choosingReturn;
    returning.required = roundTrip && !choosingReturn;
    if (departure?.value) returning.min = departure.value;
    if (!roundTrip && !choosingReturn) returning.value = '';
    if (roundTrip && !choosingReturn && departure?.value && (!returning.value || returning.value < departure.value)) {
      const date = new Date(`${departure.value}T12:00:00Z`);
      date.setUTCDate(date.getUTCDate() + 3);
      returning.value = date.toISOString().slice(0, 10);
    }
  };
  syncTripDates();

  const message = (text, error = false) => {
    if (!feedback) return;
    feedback.hidden = !text;
    feedback.textContent = text;
    feedback.classList.toggle('is-error', error);
  };
  const setBusy = (busy) => {
    results?.setAttribute('aria-busy', String(busy));
    results?.querySelectorAll('[data-trip-choose], [data-flight-fare]').forEach((control) => { control.disabled = busy; });
  };
  const lockResultSelection = () => {
    results?.querySelectorAll('[data-trip-choose], [data-flight-fare]').forEach((control) => { control.disabled = true; });
  };
  const refresh = async (url, historyMode = 'replace') => {
    controller?.abort();
    const requestController = new AbortController();
    let failed = false;
    controller = requestController;
    const filtersWereOpen = results?.querySelector('.flight-filters')?.classList.contains('is-open') ?? false;
    setBusy(true);
    message('Đang cập nhật chuyến bay…');
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Flight-Results': '1' }, signal: requestController.signal });
      let data = null;
      try { data = await response.json(); } catch { data = {}; }
      if (!response.ok || typeof data.html !== 'string') throw new Error(data.message || 'Chưa tải được chuyến bay. Vui lòng thử lại.');
      if (controller !== requestController) return;
      results.innerHTML = data.html;
      const filters = results.querySelector('.flight-filters');
      const toggle = results.querySelector('[data-flight-filter-toggle]');
      if (filtersWereOpen && filters && matchMedia('(max-width: 767px)').matches) {
        filters.classList.add('is-open');
        toggle?.setAttribute('aria-expanded', 'true');
      }
      initFlightRanges(results);
      for (const name of ['origin', 'destination', 'date', 'return_date', 'trip_type', 'passenger_count', 'outbound', 'sort']) {
        const control = form.elements.namedItem(name);
        if (control && data.filters?.[name] !== undefined) control.value = data.filters[name] ?? '';
      }
      syncAirportDestinations(form);
      if (historyMode !== 'none') history[historyMode === 'push' ? 'pushState' : 'replaceState']({}, '', `${url.pathname}${url.search}`);
      message('');
    } catch (error) {
      if (error.name !== 'AbortError') {
        failed = true;
        message(error.message, true);
      }
    } finally {
      if (controller === requestController) {
        setBusy(false);
        // Keep stale cards visible for context, but prevent booking a result
        // that was not confirmed by the failed refresh.
        if (failed) lockResultSelection();
      }
    }
  };
  const search = () => {
    if (!form.checkValidity()) { setBusy(false); message('Vui lòng nhập bộ lọc hợp lệ trước khi tìm chuyến bay.', true); return; }
    const url = new URL(form.action, location.href);
    const values = new FormData(form);
    for (const [name, value] of values.entries()) {
      if (value === '') values.delete(name);
    }
    results?.querySelectorAll('[data-range-bound]').forEach((input) => {
      const atDomainEdge = input.dataset.rangeBound === 'min' ? input.value === input.min : input.value === input.max;
      if (atDomainEdge) values.delete(input.name);
    });
    url.search = new URLSearchParams(values).toString();
    refresh(url);
  };
  const schedule = () => { clearTimeout(timer); setBusy(true); timer = setTimeout(search, 300); };
  form.addEventListener('submit', (event) => { event.preventDefault(); search(); });
  const handleChange = (event) => {
    if (event.target.matches('[data-flight-sort]')) form.elements.namedItem('sort').value = event.target.value;
    if (event.target.matches('[data-airport-origin]')) syncAirportDestinations(form);
    if (event.target.matches('[name="trip_type"], [name="date"], [name="return_date"]')) syncTripDates();
    if (event.target.matches('[data-flight-fare]')) {
      const card = event.target.closest('[data-flight-card]');
      const option = event.target.selectedOptions[0];
      if (card && option) {
        card.querySelector('[data-flight-price]')?.replaceChildren(document.createTextNode(new Intl.NumberFormat('vi-VN').format(Number(option.dataset.price)) + ' ₫'));
        const remaining = Number(option.dataset.remaining);
        const stock = card.querySelector('[data-flight-stock]');
        if (stock) stock.textContent = remaining ? `Còn ${remaining} chỗ` : 'Hết chỗ';
        const choose = card.querySelector('[data-trip-choose]');
        if (choose) choose.disabled = remaining < Number(card.dataset.passengerCount || 1);
      }
    }
    if (event.target.form === form) schedule();
  };
  const handleInput = (event) => { if (event.target.form === form && !event.target.matches('select, [type=checkbox]')) schedule(); };
  form.addEventListener('change', handleChange);
  form.addEventListener('input', handleInput);
  results?.addEventListener('change', handleChange);
  results?.addEventListener('input', handleInput);
  initFlightRanges(results);

  document.addEventListener('click', (event) => {
    const toggle = event.target.closest('[data-flight-filter-toggle]');
    if (toggle && results?.contains(toggle)) {
      const filters = results.querySelector('.flight-filters');
      const open = filters?.classList.toggle('is-open') ?? false;
      toggle.setAttribute('aria-expanded', String(open));
      return;
    }
    const link = event.target.closest('[data-flight-search-link], .flight-pagination a');
    if (link && results?.contains(link) && !event.ctrlKey && !event.metaKey && event.button === 0) {
      event.preventDefault();
      refresh(new URL(link.href), 'push');
    }
  });

  const showPreview = async (choice) => {
    if (!preview) return;
    const content = preview.querySelector('[data-trip-preview-content]');
    content.innerHTML = '<header class="trip-preview-header trip-preview-loading"><h2 id="trip-preview-title">Thông tin chuyến bay</h2><button type="button" class="trip-preview-close" data-trip-preview-close aria-label="Đóng thông tin chuyến bay">×</button></header><p class="trip-preview-status" role="status">Đang kiểm tra lịch trình và giá vé…</p>';
    preview.showModal();
    const url = new URL(choice.action, location.href);
    url.search = new URLSearchParams(new FormData(choice)).toString();
    try {
      const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
      const data = await response.json();
      if (!response.ok || typeof data.html !== 'string') throw new Error(data.message || 'Chuyến bay hoặc giá vé đã thay đổi. Vui lòng tìm lại.');
      content.innerHTML = data.html;
      preview.querySelector('[data-trip-preview-continue]')?.focus({ preventScroll: true });
    } catch (error) {
      const status = content.querySelector('[role=status]');
      status.textContent = error.message;
      status.setAttribute('role', 'alert');
    }
  };
  document.addEventListener('submit', (event) => {
    if (!event.target.matches('[data-trip-choice]') || !results?.contains(event.target) || !preview?.showModal) return;
    event.preventDefault();
    previewButton = event.submitter;
    showPreview(event.target);
  });
  preview?.addEventListener('click', (event) => {
    if (event.target.closest('[data-trip-preview-close], [data-trip-preview-continue]') || event.target === preview) preview.close();
  });
  window.addEventListener('popstate', () => refresh(new URL(location.href), 'none'));
  preview?.addEventListener('close', () => previewButton?.focus({ preventScroll: true }));
});

document.querySelectorAll('[data-print]').forEach((button) => button.addEventListener('click', () => window.print()));

document.querySelectorAll('[data-camera-scan]').forEach((button) => button.addEventListener('click', async () => {
  const area = document.querySelector('[data-scanner]');
  const status = area?.querySelector('[data-scanner-status]');
  const video = area?.querySelector('video');
  if (!area || !status || !video) return;
  area.hidden = false;
  if (!('BarcodeDetector' in window) || !navigator.mediaDevices?.getUserMedia) { status.textContent = 'Camera chưa hỗ trợ quét QR trên trình duyệt này. Vui lòng dùng máy quét tại cổng.'; return; }
  let stream;
  const stop = () => { stream?.getTracks().forEach((track) => track.stop()); video.srcObject = null; button.disabled = false; };
  try {
    button.disabled = true;
    stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } });
    video.srcObject = stream;
    await video.play();
    status.textContent = 'Đưa mã QR vào khung hình.';
    const detector = new BarcodeDetector({ formats: ['qr_code'] });
    const deadline = Date.now() + 60000;
    while (video.srcObject && Date.now() < deadline) {
      const codes = await detector.detect(video);
      const code = codes.find((item) => /^[a-f0-9]{64}$/i.test(item.rawValue));
      if (code) { document.getElementById('token').value = code.rawValue; status.textContent = 'Đã đọc thẻ. Kiểm tra chuyến bay và xác nhận lên tàu.'; stop(); return; }
      await new Promise((resolve) => setTimeout(resolve, 250));
    }
    status.textContent = 'Đã dừng quét. Bạn có thể bật camera để thử lại.';
  } catch { status.textContent = 'Không mở được camera. Kiểm tra quyền truy cập hoặc dùng máy quét tại cổng.'; }
  finally { stop(); }
}));

(() => {
  const body = document.body;
  const toggles = [...document.querySelectorAll('[data-sidebar-toggle]')];
  const setSidebar = (open) => {
    body.classList.toggle('sidebar-open', open);
    toggles.forEach((toggle) => { if (toggle.hasAttribute('aria-expanded')) toggle.setAttribute('aria-expanded', String(open)); });
    body.style.overflow = open && matchMedia('(max-width: 800px)').matches ? 'hidden' : '';
  };
  toggles.forEach((toggle) => toggle.addEventListener('click', (event) => { event.preventDefault(); setSidebar(!body.classList.contains('sidebar-open')); }));
  document.querySelectorAll('#app-sidebar a').forEach((link) => link.addEventListener('click', () => setSidebar(false)));
  document.addEventListener('keydown', (event) => { if (event.key === 'Escape') setSidebar(false); });
  document.addEventListener('click', (event) => {
    const close = event.target.closest('[data-dismiss-alert]');
    if (close) close.closest('.alert')?.remove();
  });
  document.addEventListener('submit', (event) => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || form.dataset.noLoading !== undefined || form.matches('[data-trip-choice], [data-flight-search]') || !form.checkValidity()) return;
    const submitter = event.submitter || form.querySelector('button[type=submit]');
    if (!submitter || submitter.disabled) return;
    submitter.disabled = true;
    submitter.setAttribute('aria-busy', 'true');
    submitter.textContent = 'Đang xử lý…';
  });
})();
