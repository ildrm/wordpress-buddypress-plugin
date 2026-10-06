/* global bpiConfig, wp */
(() => {
  'use strict';
  const { __ } = wp.i18n;
  document.addEventListener('click', (event) => {
    if (!bpiConfig.analytics) return;
    const link = event.target.closest('a[data-bpi-event]');
    if (!link) return;
    const { bpiEvent, bpiType, bpiId } = link.dataset;
    if (!['recommendation_clicked', 'search_result_clicked'].includes(bpiEvent) || !/^[a-z_]+$/.test(bpiType) || !/^[1-9]\d*$/.test(bpiId)) return;
    fetch(bpiConfig.root + 'events', {
      method: 'POST', credentials: 'same-origin', keepalive: true,
      headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': bpiConfig.nonce },
      body: JSON.stringify({ event: bpiEvent, type: bpiType, id: Number(bpiId) })
    }).catch(() => {});
  });
  function payload(form) {
    const data = new FormData(form);
    const base = JSON.parse(data.get('bpi_payload') || '{}');
    if (!base || typeof base !== 'object' || (Array.isArray(base) && base.length)) throw new Error(__('Invalid form configuration.', 'buddypress-intelligence'));
    const result = Array.isArray(base) ? {} : base;
    const types = JSON.parse(data.get('bpi_types') || '{}');
    for (const [key, type] of Object.entries(types)) {
      const name = `input[${key}]`;
      let value;
      if (type === 'ids' || type === 'topic_ids') {
        value = data.getAll(`${name}[]`).map(Number);
      } else if (type === 'checkbox') {
        value = data.getAll(name).includes('1');
      } else {
        value = data.get(name) ?? '';
        if (type === 'number' || type === 'select_number') value = Number(value);
        if (type === 'json') value = JSON.parse(value);
      }
      if (key.includes(':')) {
        const [parent, child] = key.split(':');
        result[parent] ??= {};
        result[parent][child] = value;
      } else result[key] = value;
    }
    return result;
  }
  document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-bpi-form]');
    if (!form) return;
    event.preventDefault();
    const status = form.querySelector('.bpi-form-status');
    const button = form.querySelector('button[type="submit"]');
    const label = button.textContent;
    button.disabled = true;
    form.setAttribute('aria-busy', 'true');
    status.textContent = __('Saving…', 'buddypress-intelligence');
    try {
      const path = form.elements.bpi_path.value;
      if (!/^[a-z-]+(?:\/\d+)?(?:\/(?:retry|reverse))?$/.test(path)) throw new Error(__('Invalid action.', 'buddypress-intelligence'));
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), 15000);
      let response;
      try {
        response = await fetch(bpiConfig.root + path, {
          method: form.elements.bpi_method.value,
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': bpiConfig.nonce },
          body: JSON.stringify(payload(form)), signal: controller.signal
        });
      } finally { clearTimeout(timer); }
      const result = await response.json();
      if (!response.ok) throw new Error(result.message || __('The action failed. Try again.', 'buddypress-intelligence'));
      status.textContent = __('Saved. Refreshing your community view…', 'buddypress-intelligence');
      const url = new URL(window.location.href);
      url.searchParams.delete('bpi_cursor');
      url.searchParams.delete('bpi_page');
      url.searchParams.delete('bpi_window');
      url.searchParams.set('bpi_notice', 'saved');
      if (['questions', 'answers', 'knowledge'].includes(path) && result.id) {
        url.searchParams.set('bpi_view', path === 'knowledge' ? 'knowledge' : 'questions');
        url.searchParams.set('bpi_entry', path === 'answers' ? String(payload(form).parent_id) : String(result.id));
      }
      window.location.assign(url.toString());
    } catch (error) {
      status.textContent = error.name === 'AbortError' || error instanceof TypeError
        ? __('Connection failed. Your form is still here; check your connection and try again.', 'buddypress-intelligence')
        : error.message;
      button.disabled = false;
      button.textContent = label;
      button.focus();
    } finally { form.removeAttribute('aria-busy'); }
  });
})();
