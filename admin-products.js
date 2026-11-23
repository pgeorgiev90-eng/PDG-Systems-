(() => {
  'use strict';

  const get = id => document.getElementById(id);
  const endpoint = 'api/admin-products-draft.php';
  const names = ['name', 'code', 'brand', 'category', 'price', 'image_url'];
  const pageSize = 30;
  const form = get('product-form');
  const editor = get('editor');
  const specs = get('specifications');
  const status = get('editor-message');
  const buttons = [...form.querySelectorAll('button[type="submit"]')];
  const actions = buttons[0].parentElement;
  const money = new Intl.NumberFormat('bg-BG', {style: 'currency', currency: 'EUR'});
  let products = [];
  let page = 0;
  let csrf;
  let version;
  let editing = 0;
  let request = 0;
  let trigger;
  let initialImage = '';
  let saving = false;

  function element(tag, text, className) {
    const item = document.createElement(tag);
    if (text !== undefined) item.textContent = text;
    if (className) item.className = className;
    return item;
  }

  async function api(url, options) {
    const response = await fetch(url, {credentials: 'same-origin', cache: 'no-store', ...options});
    const data = await response.json();
    if (!response.ok) throw new Error(data.error);
    return data;
  }

  function choices(filterId, datalistId, field) {
    const select = get(filterId);
    const current = select.value;
    const values = [...new Set(products.map(product => product[field]).filter(Boolean))]
      .sort((first, second) => first.localeCompare(second, 'bg'));
    select.replaceChildren(new Option('Всички', ''), ...values.map(value => new Option(value, value)));
    get(datalistId).replaceChildren(...values.map(value => new Option(value, value)));
    select.value = values.includes(current) ? current : '';
  }

  function render() {
    const query = get('product-search').value.trim().toLocaleLowerCase('bg');
    const category = get('filter-category').value;
    const brand = get('filter-brand').value;
    const visibility = get('filter-status').value;
    const sort = get('sort').value;
    const matches = products.filter(product =>
      (!category || product.category === category) &&
      (!brand || product.brand === brand) &&
      (!visibility || String(product.active) === visibility) &&
      [product.name, product.code, product.brand, product.category].join(' ').toLocaleLowerCase('bg').includes(query));
    if (sort === 'name') matches.sort((a, b) => a.name.localeCompare(b.name, 'bg'));
    if (sort.startsWith('price-')) {
      matches.sort((a, b) => {
        if (a.price == null) return b.price == null ? 0 : 1;
        if (b.price == null) return -1;
        return (Number(a.price) - Number(b.price)) * (sort === 'price-up' ? 1 : -1);
      });
    }
    page = Math.min(page, Math.max(0, Math.ceil(matches.length / pageSize) - 1));
    const offset = page * pageSize;
    get('product-count').textContent = matches.length
      ? `${offset + 1}–${Math.min(offset + pageSize, matches.length)} от ${matches.length}`
      : 'Няма намерени артикули';
    get('previous-page').disabled = page === 0;
    get('next-page').disabled = offset + pageSize >= matches.length;
    get('rows').replaceChildren(...matches.slice(offset, offset + pageSize).map(product => {
      const row = element('tr');
      const item = element('td');
      const link = element('button', product.name, 'crm-product-link');
      link.type = 'button';
      link.addEventListener('click', () => open(product.id, link));
      item.append(link, element('small', [product.code, product.brand, product.category].filter(Boolean).join(' · ')));
      const state = element('td');
      const active = Number(product.active) === 1;
      state.append(element('span', active ? 'Публикуван' : 'Скрит', active ? 'crm-status' : 'crm-status processing'));
      row.append(item, element('td', product.price == null ? 'По запитване' : money.format(product.price)), state);
      return row;
    }));
  }

  async function refresh() {
    ({products} = await api(endpoint));
    choices('filter-category', 'product-categories', 'category');
    choices('filter-brand', 'product-brands', 'brand');
    render();
  }

  function addField(label = '', value = '') {
    const row = element('div', undefined, 'crm-product-specification');
    for (const [caption, contents, limit] of [['Име', label, 100], ['Стойност', value, 10000]]) {
      const wrapper = element('label', caption);
      const input = element('input');
      input.maxLength = limit;
      input.required = true;
      input.value = contents;
      wrapper.append(input);
      row.append(wrapper);
    }
    const remove = element('button', '×');
    remove.type = 'button';
    remove.setAttribute('aria-label', 'Премахни характеристиката');
    remove.onclick = () => row.remove();
    row.append(remove);
    specs.append(row);
    return row.querySelector('input');
  }

  async function open(id, source) {
    if (saving) return;
    const current = ++request;
    trigger = source;
    editing = id;
    version = undefined;
    form.reset();
    actions.prepend(buttons[0]);
    initialImage = '';
    specs.replaceChildren();
    form.querySelector('details').open = false;
    editor.hidden = false;
    form.hidden = Boolean(id);
    get('editor-links').hidden = true;
    get('form-title').textContent = id ? 'Редакция на артикул' : 'Нов артикул';
    status.textContent = id ? 'Зареждане…' : '';
    get('form-title').focus();
    editor.scrollIntoView({block: 'nearest'});
    if (!id) return;

    try {
      const data = await api(endpoint + '?id=' + encodeURIComponent(id));
      if (current !== request) return;
      version = data.version;
      for (const name of names) form.elements[name].value = data.product[name] ?? '';
      initialImage = form.elements.image_url.value;
      let firstDescription = true;
      for (const {label, value} of data.fields) {
        if (firstDescription && label.trim().toLocaleLowerCase('bg') === 'описание') {
          form.elements.description.value = value;
          firstDescription = false;
        } else {
          addField(label, value);
        }
      }
      get('editor-links').hidden = false;
      get('preview-link').hidden = Number(data.product.active) !== 1;
      if (Number(data.product.active) === 1) actions.prepend(buttons[1]);
      get('preview-link').href = 'product.html?id=' + encodeURIComponent(id);
      get('related-link').href = 'admin-related-products.php?product_id=' + encodeURIComponent(id);
      form.hidden = false;
      status.textContent = '';
    } catch {
      if (current === request) status.textContent = 'Артикулът не може да се зареди. Затвори и опитай отново.';
    }
  }

  function close() {
    if (saving) return;
    request++;
    editor.hidden = true;
    (trigger?.isConnected ? trigger : get('new-product')).focus();
  }

  form.addEventListener('submit', async event => {
    event.preventDefault();
    if (saving) return;
    const fields = [];
    const description = form.elements.description.value.trim();
    if (description) fields.push({label: 'Описание', value: description});
    for (const row of specs.children) {
      const [label, value] = row.querySelectorAll('input');
      fields.push({label: label.value, value: value.value});
    }
    const data = {id: editing, version, active: event.submitter?.value === '1', fields};
    for (const name of names) data[name] = form.elements[name].value.trim();
    if (data.image_url === initialImage) delete data.image_url;

    saving = true;
    for (const button of buttons) button.disabled = true;
    get('close-editor').disabled = true;
    status.textContent = 'Запазване…';
    try {
      await api(endpoint, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrf},
        body: JSON.stringify(data)
      });
    } catch (error) {
      status.textContent = error.message === 'changed_refresh'
        ? 'Артикулът е променен. Копирай редакцията си и отвори отново актуалния запис.'
        : error.message === 'invalid_image'
          ? 'Въведи HTTPS адрес или път до снимка в assets/.'
          : 'Промяната не е записана. Провери полетата и опитай отново.';
      return;
    } finally {
      saving = false;
      for (const button of buttons) button.disabled = false;
      get('close-editor').disabled = false;
    }
    close();
    get('message').textContent = data.active ? 'Артикулът е публикуван.' : 'Артикулът е запазен като скрит.';
    try {
      await refresh();
    } catch {
      get('message').textContent += ' Обнови страницата, за да видиш записа в списъка.';
    }
  });

  get('new-product').onclick = event => open(0, event.currentTarget);
  get('close-editor').onclick = close;
  get('add-specification').onclick = () => addField().focus();
  get('previous-page').onclick = () => { page--; render(); };
  get('next-page').onclick = () => { page++; render(); };
  for (const id of ['product-search', 'filter-category', 'filter-brand', 'filter-status', 'sort']) {
    get(id).addEventListener(id === 'product-search' ? 'input' : 'change', () => { page = 0; render(); });
  }

  (async () => {
    try {
      const session = await api('api/account-draft.php?action=session');
      if (session.user?.role !== 'admin') {
        get('message').textContent = 'Необходим е администраторски вход.';
        return;
      }
      csrf = session.csrf;
      await refresh();
      get('app').hidden = false;
      get('message').textContent = '';
      if (location.hash === '#categories') get('categories').scrollIntoView();
    } catch {
      get('message').textContent = 'Каталогът временно не е достъпен.';
    }
  })();
})();
