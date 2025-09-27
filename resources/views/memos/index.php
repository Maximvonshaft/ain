<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        :root {
            color-scheme: light dark;
        }
        body {
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            margin: 0;
            background: #0f172a;
            color: #e2e8f0;
        }
        header {
            padding: 1.5rem;
            background: #1e293b;
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        h1 {
            margin: 0;
            font-size: 1.6rem;
        }
        main {
            padding: 1.5rem;
        }
        .memo-card {
            background: rgba(30, 41, 59, 0.75);
            border: 1px solid rgba(148, 163, 184, 0.2);
            border-radius: 1rem;
            padding: 1rem 1.5rem;
            margin-bottom: 1.5rem;
            box-shadow: 0 18px 40px -25px rgba(15, 23, 42, 0.9);
        }
        .memo-title {
            font-size: 1.2rem;
            font-weight: 600;
            margin: 0;
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        .memo-title button {
            border: none;
            border-radius: 999px;
            background: #38bdf8;
            color: #0f172a;
            font-weight: 600;
            padding: 0.2rem 0.75rem;
            cursor: pointer;
        }
        .memo-title button[aria-pressed="true"] {
            background: #22c55e;
        }
        form#create-memo {
            display: grid;
            gap: 0.5rem;
            max-width: 640px;
        }
        form#create-memo input,
        form#create-memo textarea {
            width: 100%;
            border-radius: 0.75rem;
            border: 1px solid rgba(148, 163, 184, 0.25);
            padding: 0.65rem 1rem;
            background: rgba(15, 23, 42, 0.7);
            color: inherit;
        }
        form#create-memo button {
            justify-self: flex-start;
            border-radius: 999px;
            border: none;
            background: linear-gradient(135deg, #38bdf8, #6366f1);
            color: #0f172a;
            padding: 0.55rem 1.5rem;
            font-weight: 700;
            cursor: pointer;
        }
        ul.subtasks {
            list-style: none;
            margin: 1rem 0 0;
            padding: 0;
            display: grid;
            gap: 0.5rem;
        }
        ul.subtasks li {
            display: flex;
            gap: 0.75rem;
            align-items: center;
        }
        ul.subtasks input[type="checkbox"] {
            width: 1.1rem;
            height: 1.1rem;
        }
        .timestamp {
            font-size: 0.85rem;
            color: #94a3b8;
        }
        .subtask-form {
            margin-top: 0.75rem;
            display: flex;
            gap: 0.5rem;
        }
        .subtask-form input {
            flex: 1;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.3);
            padding: 0.35rem 0.9rem;
            background: rgba(15, 23, 42, 0.7);
            color: inherit;
        }
        .subtask-form button {
            border: none;
            border-radius: 999px;
            padding: 0.4rem 1rem;
            background: #38bdf8;
            color: #0f172a;
            font-weight: 600;
            cursor: pointer;
        }
        .mindmap-actions {
            margin-top: 1rem;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            align-items: center;
        }
        .mindmap-actions a,
        .mindmap-actions button {
            border: none;
            border-radius: 999px;
            padding: 0.4rem 1.1rem;
            background: rgba(56, 189, 248, 0.15);
            color: #38bdf8;
            font-weight: 600;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .mindmap-actions a:hover,
        .mindmap-actions button:hover {
            background: rgba(56, 189, 248, 0.3);
        }
        .mindmap-actions .secondary {
            background: rgba(99, 102, 241, 0.2);
            color: #a855f7;
        }
        .mindmap-actions .hint {
            font-size: 0.85rem;
            color: #94a3b8;
        }
        @media (max-width: 768px) {
            header {
                padding: 1rem;
            }
            main {
                padding: 1rem;
            }
            .mindmap-actions {
                flex-direction: column;
                align-items: stretch;
            }
            .mindmap-actions a,
            .mindmap-actions button {
                justify-content: center;
            }
        }
    </style>
</head>
<body>
<?php $basePath = isset($basePath) ? (string)$basePath : ''; ?>
<header>
    <h1><?= htmlspecialchars($appName) ?> · 备忘录</h1>
    <form id="create-memo">
        <input type="text" name="title" placeholder="快速记录一个想法..." required>
        <textarea name="content_md" rows="4" placeholder="Markdown 正文（可选）"></textarea>
        <button type="submit">新增备忘录</button>
    </form>
</header>
<main>
    <?php foreach ($items as $bundle): $memo = $bundle['memo']; $subtasks = $bundle['subtasks']; ?>
        <article class="memo-card" data-memo-id="<?= (int)$memo->id ?>">
            <header class="memo-title">
                <button type="button" class="toggle" aria-pressed="<?= $memo->isDone ? 'true' : 'false' ?>">
                    <?= $memo->isDone ? '完成' : '未完' ?>
                </button>
                <span><?= htmlspecialchars($memo->title) ?></span>
            </header>
            <?php if ($memo->contentMd): ?>
                <pre><?= htmlspecialchars($memo->contentMd) ?></pre>
            <?php endif; ?>
            <p class="timestamp">更新于 <?= date('Y-m-d H:i', $memo->updatedAt) ?></p>
            <ul class="subtasks">
                <?php foreach ($subtasks as $subtask): ?>
                    <li data-subtask-id="<?= (int)$subtask->id ?>">
                        <input type="checkbox" <?= $subtask->isDone ? 'checked' : '' ?>>
                        <span><?= htmlspecialchars($subtask->title) ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <form class="subtask-form">
                <input type="text" name="title" placeholder="添加子任务">
                <button type="submit">添加</button>
            </form>
            <div class="mindmap-actions" data-memo-id="<?= (int)$memo->id ?>" data-memo-title="<?= htmlspecialchars($memo->title) ?>">
                <?php $mindmaps = $bundle['mindmaps'] ?? []; ?>
                <?php if (!empty($mindmaps)): ?>
                    <?php $primaryMindmap = $mindmaps[0];
                    $prefix = $basePath ?: '';
                    $mindmapUrl = rtrim($prefix, '/') . '/mindmaps/' . $primaryMindmap->id;
                    if ($mindmapUrl[0] !== '/') { $mindmapUrl = '/' . $mindmapUrl; }
                    ?>
                    <a href="<?= htmlspecialchars($mindmapUrl) ?>">打开导图</a>
                    <span class="hint">已有 <?= count($mindmaps) ?> 张导图</span>
                    <button type="button" class="create-mindmap secondary">新建导图</button>
                <?php else: ?>
                    <button type="button" class="create-mindmap">创建思维导图</button>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</main>
<script>
const BASE_PATH = <?= json_encode($basePath) ?>;
const withBase = (path) => {
    if (!BASE_PATH) {
        return path;
    }
    if (!path.startsWith('/')) {
        path = '/' + path;
    }
    if (path === '/') {
        return BASE_PATH || '/';
    }
    return `${BASE_PATH}${path}`;
};

const createForm = document.getElementById('create-memo');
createForm?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const formData = new FormData(form);
    const payload = Object.fromEntries(formData.entries());
    const res = await fetch(withBase('/api/v1/memos'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload)
    });
    if (res.ok) {
        location.reload();
    } else {
        alert('创建失败');
    }
});

document.querySelectorAll('.memo-card .toggle').forEach(button => {
    button.addEventListener('click', async (event) => {
        const memoEl = event.currentTarget.closest('.memo-card');
        const memoId = memoEl?.dataset.memoId;
        if (!memoId) return;
        const res = await fetch(withBase(`/api/v1/memos/${memoId}/toggle`), { method: 'PATCH' });
        if (res.ok) {
            location.reload();
        }
    });
});

document.querySelectorAll('.memo-card .subtask-form').forEach(form => {
    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        const memoEl = form.closest('.memo-card');
        const memoId = memoEl?.dataset.memoId;
        if (!memoId) return;
        const input = form.querySelector('input[name="title"]');
        const title = input?.value.trim();
        if (!title) return;
        const res = await fetch(withBase(`/api/v1/memos/${memoId}/subtasks`), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ title })
        });
        if (res.ok) {
            location.reload();
        }
    });
});

document.querySelectorAll('.memo-card ul.subtasks input[type="checkbox"]').forEach(input => {
    input.addEventListener('change', async (event) => {
        const li = event.currentTarget.closest('li');
        const subtaskId = li?.dataset.subtaskId;
        if (!subtaskId) return;
        const res = await fetch(withBase(`/api/v1/subtasks/${subtaskId}/toggle`), { method: 'PATCH' });
        if (res.ok) {
            location.reload();
        }
    });
});

document.querySelectorAll('.mindmap-actions .create-mindmap').forEach(button => {
    button.addEventListener('click', async (event) => {
        const container = event.currentTarget.closest('.mindmap-actions');
        if (!container) {
            return;
        }
        const memoId = Number(container.dataset.memoId);
        const memoTitle = container.dataset.memoTitle || '新导图';
        const title = prompt('为新导图命名', memoTitle + ' 导图');
        if (title === null) {
            return;
        }
        const payload = { title: title.trim() === '' ? memoTitle + ' 导图' : title.trim() };
        const res = await fetch(withBase(`/api/v1/memos/${memoId}/mindmaps`), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        });
        if (res.ok) {
            const data = await res.json();
            const mindmapId = data.mindmap.id;
            window.location.href = withBase(`/mindmaps/${mindmapId}`);
        } else {
            alert('导图创建失败');
        }
    });
});
</script>
</body>
</html>
