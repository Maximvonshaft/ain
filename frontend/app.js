const state = {
  categories: [],
  categoryCounts: {},
  stats: {},
  currentCategory: null,
  status: 'active',
  search: '',
  items: [],
};

const categoryList = document.getElementById('category-list');
const categorySelect = document.getElementById('category-select');
const itemList = document.getElementById('item-list');
const statusFilter = document.getElementById('status-filter');
const searchInput = document.getElementById('search-input');
const refreshBtn = document.getElementById('refresh');
const categoryForm = document.getElementById('category-form');
const itemForm = document.getElementById('item-form');

const apiBaseUrl = new URL('./', import.meta.url);

function apiUrl(path = '') {
  if (typeof path !== 'string' || /^[a-zA-Z][a-zA-Z0-9+.-]*:/.test(path)) {
    return path;
  }

  const cleaned = path.replace(/^\/+/, '');
  return new URL(cleaned, apiBaseUrl).toString();
}

const handleError = (error) => {
  console.error(error);
  alert(error.message || '请求失败');
};

async function fetchJSON(url, options = {}) {
  const response = await fetch(apiUrl(url), {
    headers: {
      'Content-Type': 'application/json',
      ...(options.headers || {}),
    },
    ...options,
  });

  if (response.status === 204) {
    return null;
  }

  const data = await response.json().catch(() => null);
  if (!response.ok) {
    const message = data?.error || '接口请求失败';
    throw new Error(message);
  }
  return data;
}

async function fetchForm(url, formData) {
  const response = await fetch(apiUrl(url), {
    method: 'POST',
    body: formData,
  });
  if (!response.ok) {
    const text = await response.text();
    throw new Error(text || '上传失败');
  }
  return response.json();
}

async function loadCategories() {
  const data = await fetchJSON('api/categories');
  state.categories = data.categories || [];
  state.categoryCounts = data.counts || {};
  state.stats = data.stats || {};
  renderCategories();
  renderCategoryOptions();
}

async function loadItems() {
  const params = new URLSearchParams({
    status: state.status,
    include_steps: '1',
  });
  if (state.search.trim() !== '') {
    params.set('search', state.search.trim());
  }
  if (state.currentCategory) {
    params.set('category_id', String(state.currentCategory));
  }
  const data = await fetchJSON(`api/items?${params.toString()}`);
  state.items = data.items || [];
  renderItems();
}

function renderCategories() {
  categoryList.innerHTML = '';
  const fragment = document.createDocumentFragment();

  const allItem = document.createElement('li');
  allItem.textContent = `全部 (${state.stats.active_total ?? 0})`;
  if (state.currentCategory === null) {
    allItem.classList.add('active');
  }
  allItem.addEventListener('click', () => {
    state.currentCategory = null;
    loadItems();
    renderCategories();
  });
  fragment.appendChild(allItem);

  for (const category of state.categories) {
    const li = document.createElement('li');
    li.textContent = `${category.name} (${state.categoryCounts[category.id] ?? 0})`;
    if (state.currentCategory === category.id) {
      li.classList.add('active');
    }
    li.addEventListener('click', () => {
      state.currentCategory = category.id;
      loadItems();
      renderCategories();
    });
    fragment.appendChild(li);
  }

  categoryList.appendChild(fragment);
}

function renderCategoryOptions() {
  categorySelect.innerHTML = '<option value="">未分类</option>';
  for (const category of state.categories) {
    const option = document.createElement('option');
    option.value = String(category.id);
    option.textContent = category.name;
    categorySelect.appendChild(option);
  }
}

function renderItems() {
  itemList.innerHTML = '';
  const itemTemplate = document.getElementById('item-template');
  const stepTemplate = document.getElementById('step-template');

  for (const item of state.items) {
    const clone = itemTemplate.content.firstElementChild.cloneNode(true);
    const titleEl = clone.querySelector('.item-title');
    const doneInput = clone.querySelector('.item-done');
    const metaEl = clone.querySelector('.item-meta');
    const descEl = clone.querySelector('.item-desc');
    const stepList = clone.querySelector('.step-list');
    const addStepBtn = clone.querySelector('.add-step');
    const deleteBtn = clone.querySelector('.delete-item');

    titleEl.textContent = item.title;
    doneInput.checked = Boolean(item.done);
    metaEl.textContent = `${item.category_name ?? '未分类'} · 更新于 ${formatDate(item.updated_at)}`;
    descEl.textContent = item.description || '（无描述）';

    doneInput.addEventListener('change', async () => {
      try {
        await fetchJSON(`api/items/${item.id}/done`, {
          method: 'PATCH',
          body: JSON.stringify({ done: doneInput.checked }),
        });
        await Promise.all([loadCategories(), loadItems()]);
      } catch (error) {
        handleError(error);
        doneInput.checked = !doneInput.checked;
      }
    });

    deleteBtn.addEventListener('click', async () => {
      if (!confirm('确定要删除该备忘录吗？')) return;
      try {
        await fetchJSON(`api/items/${item.id}`, { method: 'DELETE' });
        await Promise.all([loadCategories(), loadItems()]);
      } catch (error) {
        handleError(error);
      }
    });

    addStepBtn.addEventListener('click', async () => {
      const title = prompt('步骤标题');
      if (!title) return;
      try {
        await fetchJSON(`api/items/${item.id}/steps`, {
          method: 'POST',
          body: JSON.stringify({ title }),
        });
        await loadItems();
      } catch (error) {
        handleError(error);
      }
    });

    (item.steps || []).forEach((step) => {
      const stepClone = stepTemplate.content.firstElementChild.cloneNode(true);
      const stepTitle = stepClone.querySelector('.step-title');
      const stepDone = stepClone.querySelector('.step-done');
      const deleteStepBtn = stepClone.querySelector('.delete-step');
      stepTitle.textContent = step.title;
      stepDone.checked = Boolean(step.done);

      stepDone.addEventListener('change', async () => {
        try {
          await fetchJSON(`api/steps/${step.id}/done`, {
            method: 'PATCH',
            body: JSON.stringify({ done: stepDone.checked }),
          });
          await loadItems();
        } catch (error) {
          handleError(error);
          stepDone.checked = !stepDone.checked;
        }
      });

      deleteStepBtn.addEventListener('click', async () => {
        try {
          await fetchJSON(`api/steps/${step.id}`, { method: 'DELETE' });
          await loadItems();
        } catch (error) {
          handleError(error);
        }
      });

      stepList.appendChild(stepClone);
    });

    itemList.appendChild(clone);
  }
}

function formatDate(timestamp) {
  if (!timestamp) return '';
  const date = new Date(timestamp * 1000);
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

statusFilter.addEventListener('change', () => {
  state.status = statusFilter.value;
  loadItems().catch(handleError);
});

searchInput.addEventListener('input', (event) => {
  state.search = event.target.value;
  loadItems().catch(handleError);
});

refreshBtn.addEventListener('click', () => {
  Promise.all([loadCategories(), loadItems()]).catch(handleError);
});

categoryForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const formData = new FormData(categoryForm);
  const name = formData.get('name');
  if (!name) return;
  try {
    await fetchJSON('api/categories', {
      method: 'POST',
      body: JSON.stringify({ name }),
    });
    categoryForm.reset();
    await loadCategories();
  } catch (error) {
    handleError(error);
  }
});

itemForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const formData = new FormData(itemForm);
  const payload = {
    title: formData.get('title'),
    description: formData.get('description'),
    category_id: formData.get('category_id') || null,
  };
  try {
    await fetchJSON('api/items', {
      method: 'POST',
      body: JSON.stringify(payload),
    });
    itemForm.reset();
    await Promise.all([loadCategories(), loadItems()]);
  } catch (error) {
    handleError(error);
  }
});

loadCategories().then(loadItems).catch(handleError);
