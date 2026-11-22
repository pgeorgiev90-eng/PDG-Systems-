(() => {
  'use strict';

  const endpoint = new URL('/api/solax-battery-compatibility.php', document.currentScript.src);
  const normalize = value => value.toUpperCase().replace(/[^A-Z0-9]/g, '');
  let request;
  function load() {
    request ??= fetch(endpoint).then(async response => {
      if (!response.ok) throw new Error('Compatibility catalogue unavailable');
      const catalog = await response.json();
      if (catalog.schema_version !== 1 || !Array.isArray(catalog.compatibility)) {
        throw new Error('Invalid compatibility catalogue');
      }
      return catalog;
    }).catch(error => {
      request = undefined;
      throw error;
    });
    return request;
  }

  function lookup(catalog, inverter, target) {
    return catalog.compatibility.find(row => row.inverter_id === inverter && row.target_id === target);
  }

  const count = value => !value ? '—' : value.allowed?.join(', ') ?? `${value.min}–${value.max}`;
  const statuses = {
    listed: 'Посочена съвместимост', coming_soon: 'Отбелязано като „Coming soon“',
    marked_x: 'Маркирано с X', hatched: 'Щрихована клетка без легенда',
    cropped: 'Част от клетката липсва', ambiguous: 'Нееднозначен запис'
  };
  const heating = {completed:'завършено',processing:'в разработка',not_available:'липсва',unspecified:'без информация'};

  function describe(catalog, inverter, target) {
    const row = lookup(catalog, inverter, target);
    const heading = `${inverter} + ${catalog.targets[target]?.label ?? target}`;
    if (!row) return heading + '\nНяма запис за тази комбинация.';
    const lead = row.conflicting_fields.length ? 'Източниците се разминават. Нужна е проверка на конкретния модел.'
      : row.needs_review ? 'Записът изисква потвърждение.' : '';
    const evidence = row.evidence.map(item => {
      const source = catalog.sources[item.source_id];
      const detail = item.status === 'listed'
        ? `${count(item.total_modules)} общо; ${count(item.modules_per_port)} на порт; подгряване: ${heating[item.heating]}.`
        : `${statuses[item.status]}${item.raw_value ? ' (' + item.raw_value + ')' : ''}.`;
      return `${source.date ?? 'Таблица за европейски модели без видима дата'}: ${detail}`;
    });
    return [heading, lead, ...evidence].filter(Boolean).join('\n');
  }

  async function answer(question) {
    if (!/съвместим|съвместява|поддърж|работи ли|колко.{0,36}(батери|модул)|compatible|compatibility/i.test(question)) return null;
    const normalized = normalize(question);
    if (!/X[13]/.test(normalized)) return null;
    const catalog = await load();
    const inverters = Object.keys(catalog.inverters).map(id => [id, normalize(id.replace(/-FIT$/, ''))]);
    const targets = Object.keys(catalog.targets)
      .filter(id => catalog.targets[id].kind === 'battery_series')
      .map(id => [id, normalize(id)]);
    function match(items) {
      const matches = items.filter(([, key]) => normalized.includes(key));
      const distinct = matches.filter(([, key]) => !matches.some(([, other]) => other !== key && other.includes(key)));
      return distinct.length === 1 ? distinct[0][0] : null;
    }
    const inverter = match(inverters);
    const target = match(targets);
    return inverter && target ? describe(catalog, inverter, target) : null;
  }

  let dialog;
  async function open() {
    if (dialog?.open) return;
    if (!dialog) {
      dialog = document.createElement('dialog');
      dialog.style.cssText = 'width:min(30rem, calc(100% - 2rem));max-height:85vh;overflow:auto;border:1px solid #70858c;border-radius:12px;padding:1rem;background:#fff;color:#102638;font:16px/1.5 system-ui';
      dialog.innerHTML = '<form method="dialog"><button type="submit">Затвори</button></form><h2>Батерии SolaX</h2><div data-compatibility-content></div>';
      dialog.setAttribute('aria-label', 'Съвместимост на батерии SolaX');
      document.body.append(dialog);
    }
    dialog.showModal();
    const content = dialog.querySelector('[data-compatibility-content]');
    content.textContent = 'Зареждане…';
    try {
      const catalog = await load();
      function select(label, options) {
        const wrapper = document.createElement('label');
        wrapper.style.display = 'block';
        wrapper.textContent = label + ' ';
        const input = document.createElement('select');
        input.style.cssText = 'display:block;width:100%;margin:0.25rem 0 0.75rem;color:inherit;background:inherit';
        input.add(new Option('Изберете', ''));
        for (const [value, text] of options) input.add(new Option(text, value));
        wrapper.append(input);
        content.append(wrapper);
        return input;
      }
      content.replaceChildren();
      const inverter = select('Инвертор', Object.keys(catalog.inverters).map(id => [id,id]));
      const target = select('Батерия или разширителна кутия', Object.entries(catalog.targets).map(([id,item]) => [id,item.label]));
      const output = document.createElement('p');
      output.setAttribute('role','status');
      output.style.whiteSpace = 'pre-line';
      content.append(output);
      function refresh() {
        output.textContent = inverter.value && target.value ? describe(catalog, inverter.value, target.value) : '';
      }
      inverter.addEventListener('change', refresh);
      target.addEventListener('change', refresh);
    } catch {
      content.textContent = 'Данните за съвместимост временно не са достъпни. Отворете проверката отново.';
    }
  }

  window.PDGSolaXCompatibility = {load, lookup, describe, answer, open};
  for (const slot of document.querySelectorAll('[data-solax-compatibility]')) {
    const button = document.createElement('button');
    button.type = 'button';
    button.textContent = 'Провери батерия SolaX';
    button.addEventListener('click', open);
    slot.append(button);
  }
})();
