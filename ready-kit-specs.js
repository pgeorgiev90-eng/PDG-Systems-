(() => {
  for (const section of document.querySelectorAll('[data-spec-id]')) {
    section.addEventListener('toggle', () => {
      if (section.open && !section.dataset.loaded) load(section);
    });
  }
  async function load(section) {
    section.dataset.loaded = 'yes';
    const status = section.querySelector('[data-spec-status]');
    const table = section.querySelector('[data-spec-fields]');
    try {
      const response = await fetch('/api/product-details.php?id=' + encodeURIComponent(section.dataset.specId), {cache:'no-store'});
      if (!response.ok) throw new Error('Specification unavailable');
      const data = await response.json();
      const fields = data.fields.filter(field =>
        typeof field.label === 'string' && !/^CAD:/i.test(field.label.trim()) &&
        field.value != null && String(field.value).trim() !== ''
      );
      for (const field of fields) {
        const row = document.createElement('div');
        const name = document.createElement('dt');
        const value = document.createElement('dd');
        name.textContent = field.label;
        value.textContent = String(field.value);
        row.append(name, value);
        table.append(row);
      }
      status.textContent = fields.length ? '' : 'Вижте техническите данни в документацията на производителя.';
      status.hidden = Boolean(fields.length);
    } catch {
      delete section.dataset.loaded;
      status.textContent = 'Спецификацията в каталога е временно недостъпна. Можете да отворите документацията на производителя.';
    }
  }
})();
