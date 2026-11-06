(() => {
  const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
  const feedback = document.querySelector('[data-module-feedback]');
  const setFeedback = (message, error = false) => {
    if (!feedback) return;
    feedback.textContent = message || '';
    feedback.classList.toggle('is-error', error);
  };
  const value = (input) => {
    if (input.type === 'checkbox') return input.checked ? '1' : '0';
    return input.value;
  };
  document.querySelectorAll('input[name="event_id"]').forEach((input) => {
    if (!input.value && globalThis.crypto?.randomUUID) input.value = globalThis.crypto.randomUUID();
  });
  document.querySelectorAll('input[name="request_id"]').forEach((input) => {
    if (!input.value && globalThis.crypto?.randomUUID) input.value = globalThis.crypto.randomUUID();
  });
  document.querySelectorAll('.module-action-form').forEach((form) => {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      if (!form.checkValidity()) { form.classList.add('was-validated'); return; }
      const button = form.querySelector('button[type="submit"]');
      button?.setAttribute('data-loading', 'true');
      setFeedback('Đang lưu thông tin…');
      const payload = {};
      form.querySelectorAll('input[name],select[name],textarea[name]').forEach((input) => {
        if (input.value !== '' || input.required) payload[input.name] = value(input);
      });
      try {
        const response = await fetch(form.dataset.moduleAction, {
          method: 'POST', credentials: 'same-origin',
          headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf },
          body: JSON.stringify(payload),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
          const errors = data.errors ? Object.values(data.errors).flat().join(' ') : (data.message || 'Thao tác không thành công.');
          throw new Error(errors);
        }
        setFeedback('Đã lưu thông tin. Đang cập nhật dữ liệu…');
        window.setTimeout(() => window.location.reload(), 800);
      } catch (error) {
        setFeedback(error.message, true);
      } finally {
        button?.removeAttribute('data-loading');
      }
    });
  });
  document.querySelector('[data-module-refresh]')?.addEventListener('click', () => window.location.reload());
})();
