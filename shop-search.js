(() => {
  'use strict';
  const normalize = value => String(value || '').normalize('NFKC').toLocaleLowerCase('bg').replace(/\s+/g, ' ').trim();
  const matches = (product, query) => {
    const text = normalize([product.name, product.code, product.brand, product.category, product.description].join(' '));
    return normalize(query).split(' ').filter(Boolean).every(word => text.includes(word));
  };
  if (typeof module !== 'undefined') module.exports = { matches };
  if (typeof document === 'undefined') return;
  const search = document.getElementById('search');
  const results = document.getElementById('product-search');
  const status = document.getElementById('product-search-status');
  const grid = document.getElementById('product-search-grid');
  const more = document.getElementById('product-search-more');
  let request, data, limit = 24, timer;
  function load() {
    if (!request) request = fetch('api/products.php', {cache:'no-store'}).then(r => {
      if (!r.ok) throw Error('catalog');
      return r.json();
    }).then(value => {
      if (!Array.isArray(value.products)) throw Error('catalog');
      data = value; return value;
    }).catch(error => { request = null; throw error; });
    return request;
  }
  async function render() {
    const query = search.value.trim();
    results.hidden = !query;
    if (!query) return;
    status.textContent = 'Търсене във всички артикули…';
    more.hidden = true;
    try {
      await load();
      if (query !== search.value.trim()) return;
      const products = data.products.filter(p => matches(p, query));
      grid.replaceChildren();
      for (const p of products.slice(0, limit)) {
        const card = document.createElement('article'); card.className = 'card';
        const body = document.createElement('div'); body.className = 'body';
        const title = document.createElement('h3'), link = document.createElement('a');
        link.href = 'product.html?id=' + encodeURIComponent(p.id); link.textContent = p.name;
        title.append(link);
        if (p.imageUrl && /^(https?:\/\/|\/?assets\/)/i.test(p.imageUrl)) {
          const image = document.createElement('img'); image.src = p.imageUrl; image.alt = p.name;
          image.loading = 'lazy'; image.style.cssText = 'width:100%;height:160px;object-fit:contain;background:white';
          card.append(image);
        }
        const code = document.createElement('p'); code.textContent = p.brand + ' · Код: ' + p.code;
        const category = document.createElement('p'); category.textContent = p.category;
        const price = document.createElement('p'); price.style.cssText = 'margin-top:14px;font-weight:750';
        price.textContent = data.prices_visible !== true ? 'Цена след регистрация' : Number(p.price) > 0 ? Number(p.price).toLocaleString('bg-BG', {style:'currency',currency:'EUR'}) + ' без ДДС' : 'Цена по запитване';
        const action = document.createElement('a'); action.className = 'status';
        action.href = data.prices_visible === true ? link.href : 'auth.html?next=' + encodeURIComponent('product.html?id=' + p.id);
        action.textContent = data.prices_visible === true ? 'Виж продукта →' : 'Вход / Регистрация';
        body.append(title,code,category,price,action); card.append(body); grid.append(card);
      }
      status.textContent = products.length ? 'Намерени артикули: ' + products.length : 'Няма намерени артикули. Опитайте с друго име или код.';
      more.hidden = limit >= products.length;
    } catch (error) {
      if (query !== search.value.trim()) return;
      grid.replaceChildren(); status.textContent = 'Каталогът временно не е достъпен. Опитайте отново.';
    }
  }
  search.addEventListener('input', () => {limit = 24; clearTimeout(timer); timer = setTimeout(render, 150);});
  more.addEventListener('click', () => {limit += 24; render();});
  render();
})();
