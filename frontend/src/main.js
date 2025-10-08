const API_BASE = String(window.MEMO_API_BASE || '/api').replace(/\/?$/, '');

const state = {
  categories: [],
  uncategorizedCount: 0,
  stats: { active_total: 0, active_uncategorized: 0, mindmap_total: 0 },
  selectedCategory: 'all',
  statusFilter: 'active',
  searchTerm: '',
  items: [],
  editingId: null,
  loading: false,
  error: null,
  searchTimer: null,
};

const elements = {
  categoryList: document.getElementById('category-list'),
  categoryFilter: document.getElementById('category-filter'),
  statusFilter: document.getElementById('status-filter'),
  searchInput: document.getElementById('search-input'),
  refreshBtn: document.getElementById('refresh-btn'),
  stats: document.getElementById('stats'),
  currentCategory: document.getElementById('current-category'),
  itemCount: document.getElementById('item-count'),
  itemList: document.getElementById('item-list'),
  itemEmpty: document.getElementById('items-empty'),
  itemLoading: document.getElementById('items-loading'),
  itemError: document.getElementById('items-error'),
  newItemForm: document.getElementById('new-item-form'),
  newTitle: document.getElementById('new-title'),
  newDescription: document.getElementById('new-description'),
  newCategory: document.getElementById('new-category'),
  newCategoryBtn: document.getElementById('new-category-btn'),
  toast: document.getElementById('toast'),
};

async function api(path, { method = 'GET', body, headers = {} } = {}) {
  const init = { method, headers: { ...headers } };
  if (body !== undefined) {
    init.body = JSON.stringify(body);
    init.headers['Content-Type'] = 'application/json';
  }
  const response = await fetch(`${API_BASE}${path}`, init);
  if (response.status === 204) {
    return { ok: 1 };
  }
  let data;
  try {
    data = await response.json();
  } catch (error) {
    throw new Error('服务器返回格式错误');
  }
  if (!response.ok || !data.ok) {
    throw new Error(data?.error || '请求失败');
  }
  return data;
}

function showToast(message, timeout = 2600) {
  if (!elements.toast) return;
  elements.toast.textContent = message;
  elements.toast.hidden = false;
  clearTimeout(elements.toast._timer);
  elements.toast._timer = setTimeout(() => {
    elements.toast.hidden = true;
  }, timeout);
}

function handleError(error) {
  console.error(error);
  state.error = error.message || '发生未知错误';
  renderStatus();
  showToast(state.error, 3600);
}

function renderStatus() {
  elements.itemLoading.hidden = !state.loading;
  elements.itemError.hidden = !state.error;
  elements.itemEmpty.hidden = !(state.items.length === 0 && !state.loading && !state.error);
  if (state.error) {
    elements.itemError.textContent = state.error;
  }
}

function summarizeTotal() {
  const totalFromCategories = state.categories.reduce((sum, cat) => sum + cat.count, 0);
  return totalFromCategories + state.uncategorizedCount;
}

function renderCategories() {
  const list = elements.categoryList;
  list.innerHTML = '';
  const total = summarizeTotal();
  const entries = [
    { id: 'all', name: '全部', count: total },
    { id: 'active', name: '进行中', count: state.stats.active_total },
    { id: 'uncategorized', name: '未分类', count: state.uncategorizedCount },
    ...state.categories.map((cat) => ({ id: String(cat.id), name: cat.name, count: cat.count })),
  ];

  entries.forEach((entry) => {
    const li = document.createElement('li');
    li.dataset.id = entry.id;
    li.className = state.selectedCategory === entry.id ? 'active' : '';
    li.innerHTML = `<span>${entry.name}</span><span class="count">${entry.count}</span>`;
    list.appendChild(li);
  });

  const selectOptions = [
    { value: 'all', label: '全部' },
    { value: 'uncategorized', label: '未分类' },
    ...state.categories.map((cat) => ({ value: String(cat.id), label: cat.name })),
  ];
  elements.categoryFilter.innerHTML = selectOptions
    .map((option) => `<option value="${option.value}">${option.label}</option>`)
    .join('');
  elements.categoryFilter.value = state.selectedCategory;

  const newSelectOptions = [
    { value: '', label: '未分类' },
    ...state.categories.map((cat) => ({ value: String(cat.id), label: cat.name })),
  ];
  elements.newCategory.innerHTML = newSelectOptions
    .map((option) => `<option value="${option.value}">${option.label}</option>`)
    .join('');

  elements.stats.innerHTML = `
    <div>进行中：<strong>${state.stats.active_total}</strong></div>
    <div>未分类待办：<strong>${state.stats.active_uncategorized}</strong></div>
    <div>思维导图：<strong>${state.stats.mindmap_total}</strong></div>
  `;
}

function renderHeader() {
  const selected = state.selectedCategory;
  if (selected === 'all') {
    elements.currentCategory.textContent = '全部备忘录';
  } else if (selected === 'uncategorized') {
    elements.currentCategory.textContent = '未分类';
  } else if (selected === 'active') {
    elements.currentCategory.textContent = '进行中';
  } else {
    const found = state.categories.find((cat) => String(cat.id) === selected);
    elements.currentCategory.textContent = found ? found.name : '备忘录';
  }
  elements.itemCount.textContent = `共 ${state.items.length} 条记录`;
}

function renderItems() {
  renderStatus();
  renderHeader();
  const container = elements.itemList;
  container.innerHTML = '';
  state.items.forEach((item) => {
    const card = document.createElement('article');
    card.className = `item-card${item.done ? ' done' : ''}`;
    card.dataset.id = item.id;
    if (state.editingId === item.id) {
      card.innerHTML = renderEditForm(item);
    } else {
      card.innerHTML = renderItemView(item);
    }
    container.appendChild(card);
  });
}

function renderItemView(item) {
  const categoryName = item.category_name || '未分类';
  const statusLabel = item.done ? '已完成' : '进行中';
  const updated = new Date(item.updated_at * 1000).toLocaleString();
  return `
    <div>
      <h4>${escapeHtml(item.title)}</h4>
      <div class="meta">
        <span>${categoryName}</span>
        <span>${statusLabel} · 更新于 ${updated}</span>
      </div>
    </div>
    <div class="description">${escapeHtml(item.description || '暂无描述')}</div>
    <div class="card-actions">
      <button data-action="toggle" data-id="${item.id}">${item.done ? '恢复为进行中' : '标记完成'}</button>
      <button data-action="edit" data-id="${item.id}" class="ghost">编辑</button>
      <button data-action="delete" data-id="${item.id}" class="ghost">删除</button>
    </div>
  `;
}

function renderEditForm(item) {
  const categoryOptions = [
    { value: '', label: '未分类' },
    ...state.categories.map((cat) => ({ value: String(cat.id), label: cat.name })),
  ]
    .map((option) => `<option value="${option.value}" ${String(item.category_id ?? '') === option.value ? 'selected' : ''}>${option.label}</option>`)
    .join('');
  return `
    <form class="edit-form" data-form="edit" data-id="${item.id}">
      <label>
        标题
        <input type="text" name="title" value="${escapeHtmlAttr(item.title)}" required />
      </label>
      <label>
        分类
        <select name="category_id">${categoryOptions}</select>
      </label>
      <label>
        描述
        <textarea name="description" rows="4">${escapeHtml(item.description || '')}</textarea>
      </label>
      <div class="card-actions">
        <button type="submit">保存</button>
        <button type="button" class="ghost" data-action="cancel-edit" data-id="${item.id}">取消</button>
      </div>
    </form>
  `;
}

function escapeHtml(value) {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

function escapeHtmlAttr(value) {
  return escapeHtml(value).replace(/`/g, '&#96;');
}

async function loadCategories() {
  try {
    const data = await api('/categories');
    state.categories = data.categories || [];
    state.uncategorizedCount = data.uncategorized_count ?? 0;
    state.stats = data.stats || state.stats;
    if (!state.categories.find((cat) => String(cat.id) === state.selectedCategory) && !['all', 'uncategorized', 'active'].includes(state.selectedCategory)) {
      state.selectedCategory = 'all';
    }
    renderCategories();
    renderHeader();
  } catch (error) {
    handleError(error);
  }
}

async function loadItems() {
  state.loading = true;
  state.error = null;
  renderStatus();
  try {
    const params = new URLSearchParams();
    if (state.selectedCategory === 'uncategorized') {
      params.set('category', 'none');
    } else if (!['all', 'active'].includes(state.selectedCategory)) {
      params.set('category', state.selectedCategory);
    }
    if (state.selectedCategory === 'active') {
      params.set('done', 'false');
    } else if (state.statusFilter === 'active') {
      params.set('done', 'false');
    } else if (state.statusFilter === 'completed') {
      params.set('done', 'true');
    }
    if (state.searchTerm) {
      params.set('q', state.searchTerm);
    }
    params.set('with_steps', 'false');
    const query = params.toString();
    const data = await api(`/items${query ? `?${query}` : ''}`);
    state.items = data.items || [];
    state.loading = false;
    renderItems();
  } catch (error) {
    state.loading = false;
    handleError(error);
  }
}

function onCategoryClick(event) {
  const target = event.target.closest('li[data-id]');
  if (!target) return;
  const id = target.dataset.id;
  state.selectedCategory = id;
  if (id === 'active') {
    state.statusFilter = 'active';
    elements.statusFilter.value = 'active';
  }
  elements.categoryFilter.value = id;
  state.editingId = null;
  loadItems();
  renderCategories();
}

function onStatusChange(event) {
  state.statusFilter = event.target.value;
  state.selectedCategory = state.selectedCategory === 'active' ? 'all' : state.selectedCategory;
  loadItems();
}

function onSearchChange(event) {
  clearTimeout(state.searchTimer);
  state.searchTimer = setTimeout(() => {
    state.searchTerm = event.target.value.trim();
    loadItems();
  }, 300);
}

async function onNewCategory() {
  const name = prompt('请输入新的分类名称');
  if (!name) return;
  try {
    await api('/categories', { method: 'POST', body: { name } });
    showToast('分类已创建');
    await loadCategories();
  } catch (error) {
    handleError(error);
  }
}

async function onNewItemSubmit(event) {
  event.preventDefault();
  const payload = {
    title: elements.newTitle.value.trim(),
    description: elements.newDescription.value.trim(),
    category_id: elements.newCategory.value || null,
  };
  if (!payload.title) {
    showToast('标题不能为空');
    return;
  }
  try {
    await api('/items', { method: 'POST', body: payload });
    elements.newItemForm.reset();
    showToast('备忘录已保存');
    await Promise.all([loadCategories(), loadItems()]);
  } catch (error) {
    handleError(error);
  }
}

function onCategoryFilterChange(event) {
  state.selectedCategory = event.target.value;
  if (state.selectedCategory === 'active') {
    state.statusFilter = 'active';
    elements.statusFilter.value = 'active';
  }
  state.editingId = null;
  loadItems();
  renderCategories();
}

function onRefresh() {
  loadItems();
  loadCategories();
}

async function onItemAction(event) {
  const button = event.target.closest('button[data-action]');
  if (!button) return;
  const id = Number(button.dataset.id);
  const action = button.dataset.action;
  if (!id || !action) return;
  if (action === 'toggle') {
    const item = state.items.find((it) => it.id === id);
    if (!item) return;
    try {
      await api(`/items/${id}/done`, { method: 'PATCH', body: { done: !item.done } });
      showToast(item.done ? '已恢复为进行中' : '标记完成');
      await Promise.all([loadCategories(), loadItems()]);
    } catch (error) {
      handleError(error);
    }
  } else if (action === 'delete') {
    if (!confirm('确定要删除这条备忘录吗？')) return;
    try {
      await api(`/items/${id}`, { method: 'DELETE' });
      showToast('备忘录已删除');
      await Promise.all([loadCategories(), loadItems()]);
    } catch (error) {
      handleError(error);
    }
  } else if (action === 'edit') {
    state.editingId = id;
    renderItems();
  } else if (action === 'cancel-edit') {
    state.editingId = null;
    renderItems();
  }
}

async function onEditSubmit(event) {
  const form = event.target.closest('form[data-form="edit"]');
  if (!form) return;
  event.preventDefault();
  const id = Number(form.dataset.id);
  if (!id) return;
  const formData = new FormData(form);
  const payload = {
    title: formData.get('title'),
    description: formData.get('description'),
    category_id: formData.get('category_id') || null,
  };
  try {
    await api(`/items/${id}`, { method: 'PUT', body: payload });
    state.editingId = null;
    showToast('已更新');
    await Promise.all([loadCategories(), loadItems()]);
  } catch (error) {
    handleError(error);
  }
}

function bindEvents() {
  elements.categoryList.addEventListener('click', onCategoryClick);
  elements.categoryFilter.addEventListener('change', onCategoryFilterChange);
  elements.statusFilter.addEventListener('change', onStatusChange);
  elements.searchInput.addEventListener('input', onSearchChange);
  elements.refreshBtn.addEventListener('click', onRefresh);
  elements.newItemForm.addEventListener('submit', onNewItemSubmit);
  elements.newCategoryBtn.addEventListener('click', onNewCategory);
  elements.itemList.addEventListener('click', onItemAction);
  elements.itemList.addEventListener('submit', onEditSubmit);
}

async function bootstrap() {
  bindEvents();
  await loadCategories();
  await loadItems();
}

bootstrap().catch(handleError);
