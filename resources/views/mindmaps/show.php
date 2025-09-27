<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars(($mindmap['title'] ?? '思维导图') . ' · ' . $appName) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
    <style>
        :root {
            color-scheme: light dark;
            --bg-dark: #020617;
            --bg-panel: rgba(15, 23, 42, 0.85);
            --text-primary: #e2e8f0;
            --text-muted: #94a3b8;
            --accent: #38bdf8;
            --accent-strong: #6366f1;
            --danger: #ef4444;
        }
        body {
            margin: 0;
            font-family: "SF Pro Display", "PingFang SC", "Microsoft Yahei", system-ui, sans-serif;
            background: radial-gradient(circle at top, rgba(56, 189, 248, 0.1), transparent 45%), var(--bg-dark);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        header {
            padding: 1rem 1.5rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(12px);
            position: sticky;
            top: 0;
            z-index: 20;
        }
        header h1 {
            margin: 0;
            font-size: 1.25rem;
            display: flex;
            align-items: center;
            gap: 0.75rem;
        }
        header a.back {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
        }
        header .meta {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        main {
            flex: 1;
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        #canvas-container {
            flex: 1;
            position: relative;
            overflow: hidden;
            background: linear-gradient(135deg, rgba(148, 163, 184, 0.08), rgba(30, 41, 59, 0.25));
        }
        #mindmap-canvas {
            position: absolute;
            inset: 0;
            touch-action: none;
            cursor: grab;
        }
        #mindmap-canvas.grabbing {
            cursor: grabbing;
        }
        #canvas-surface {
            position: absolute;
            top: 0;
            left: 0;
            transform-origin: 0 0;
        }
        #nodes-layer {
            position: absolute;
            inset: 0;
        }
        .mindmap-node {
            position: absolute;
            border-radius: 1rem;
            padding: 0.75rem 1rem;
            background: var(--bg-panel);
            border: 1px solid rgba(148, 163, 184, 0.35);
            box-shadow: 0 18px 30px -20px rgba(15, 23, 42, 0.9);
            display: flex;
            flex-direction: column;
            gap: 0.35rem;
            min-width: 120px;
            color: inherit;
            user-select: none;
            touch-action: none;
            transition: box-shadow 0.2s ease;
        }
        .mindmap-node.selected {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.25);
        }
        .mindmap-node strong {
            font-size: 1rem;
            font-weight: 600;
        }
        .mindmap-node .coords {
            font-size: 0.7rem;
            color: var(--text-muted);
        }
        svg#edge-layer {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            pointer-events: none;
        }
        svg#edge-layer path {
            stroke: rgba(148, 163, 184, 0.45);
            stroke-width: 2;
            fill: none;
        }
        svg#edge-layer path.active {
            stroke: var(--accent);
        }
        .toolbar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            padding: 1rem 1.5rem;
            background: rgba(15, 23, 42, 0.75);
            backdrop-filter: blur(12px);
            border-top: 1px solid rgba(148, 163, 184, 0.12);
        }
        .toolbar button {
            border: none;
            border-radius: 999px;
            padding: 0.55rem 1.25rem;
            font-weight: 600;
            background: rgba(51, 65, 85, 0.75);
            color: var(--text-primary);
            display: inline-flex;
            align-items: center;
            gap: 0.35rem;
            cursor: pointer;
            transition: background 0.2s ease;
        }
        .toolbar button.primary {
            background: linear-gradient(135deg, var(--accent), var(--accent-strong));
            color: var(--bg-dark);
        }
        .toolbar button.danger {
            background: rgba(239, 68, 68, 0.85);
            color: #fee2e2;
        }
        .toolbar button[disabled] {
            opacity: 0.35;
            cursor: not-allowed;
        }
        .toolbar button:not([disabled]):hover {
            background: rgba(148, 163, 184, 0.2);
        }
        .toolbar button.primary:not([disabled]):hover {
            background: linear-gradient(135deg, var(--accent), #22d3ee);
        }
        .toolbar button.danger:not([disabled]):hover {
            background: rgba(248, 113, 113, 0.85);
        }
        .status-bar {
            padding: 0 1.5rem 1rem;
            font-size: 0.85rem;
            color: var(--text-muted);
            display: flex;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .empty-state {
            flex: 1;
            display: grid;
            place-items: center;
            text-align: center;
            padding: 2rem;
            color: var(--text-muted);
        }
        .empty-state a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 600;
        }
        #toast {
            position: fixed;
            bottom: 1.5rem;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(15, 23, 42, 0.9);
            padding: 0.75rem 1.25rem;
            border-radius: 999px;
            border: 1px solid rgba(148, 163, 184, 0.25);
            box-shadow: 0 20px 30px -20px rgba(15, 23, 42, 0.9);
            font-size: 0.9rem;
            opacity: 0;
            pointer-events: none;
            transition: opacity 0.2s ease;
            z-index: 50;
        }
        #toast.visible {
            opacity: 1;
        }
        @media (max-width: 768px) {
            header {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            header h1 {
                font-size: 1.05rem;
            }
            .toolbar {
                position: sticky;
                bottom: 0;
                flex-wrap: nowrap;
                overflow-x: auto;
                padding: 0.75rem 1rem;
                gap: 0.5rem;
            }
            .toolbar button {
                flex: 0 0 auto;
                padding: 0.5rem 1rem;
            }
            .mindmap-node {
                padding: 0.65rem 0.75rem;
                min-width: 140px;
            }
        }
    </style>
</head>
<body data-mindmap-id="<?= (int)$mindmapId ?>">
<?php $basePath = isset($basePath) ? (string)$basePath : ''; ?>
<header>
    <a class="back" href="<?= htmlspecialchars(($basePath ?: '') . '/') ?>">← 返回备忘录</a>
    <h1><?= htmlspecialchars($mindmap['title'] ?? '思维导图不存在') ?></h1>
    <?php if ($mindmap): ?>
        <div class="meta">节点 <?= count($mindmap['nodes']) ?> · 连线 <?= count($mindmap['edges']) ?></div>
    <?php endif; ?>
</header>
<main>
    <?php if (!$mindmap): ?>
        <section class="empty-state">
            <div>
                <p>未找到这张导图，可能已被删除。</p>
                <p><a href="<?= htmlspecialchars(($basePath ?: '') . '/') ?>">返回备忘录列表</a></p>
            </div>
        </section>
    <?php else: ?>
        <section id="canvas-container">
            <div id="mindmap-canvas">
                <div id="canvas-surface"></div>
            </div>
        </section>
        <div class="toolbar">
            <button id="add-node" class="primary">新增节点</button>
            <button id="rename-node" disabled>重命名</button>
            <button id="delete-node" class="danger" disabled>删除节点</button>
            <button id="connect-nodes">连接节点</button>
            <button id="disconnect-nodes">移除连线</button>
            <button id="zoom-in">放大</button>
            <button id="zoom-out">缩小</button>
            <button id="reset-view">重置视图</button>
        </div>
        <div class="status-bar">
            <span id="selection-status">请选择一个节点以开始编辑。</span>
            <span id="mode-status"></span>
        </div>
    <?php endif; ?>
</main>
<div id="toast"></div>
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
const INITIAL_MINDMAP = <?= json_encode($mindmap) ?>;
if (INITIAL_MINDMAP) {
    const mindmapId = INITIAL_MINDMAP.id;
    const canvas = document.getElementById('mindmap-canvas');
    const surface = document.getElementById('canvas-surface');
    const statusText = document.getElementById('selection-status');
    const modeStatus = document.getElementById('mode-status');
    const toastEl = document.getElementById('toast');

    let storedViewport = null;
    if (INITIAL_MINDMAP.viewport) {
        try {
            const parsed = JSON.parse(INITIAL_MINDMAP.viewport);
            if (parsed && typeof parsed.x === 'number' && typeof parsed.y === 'number' && typeof parsed.scale === 'number') {
                storedViewport = parsed;
            }
        } catch (error) {
            console.warn('Failed to parse viewport', error);
        }
    }

    const state = {
        mindmap: INITIAL_MINDMAP,
        nodes: new Map(INITIAL_MINDMAP.nodes.map(node => [node.id, {...node}])),
        edges: new Map(INITIAL_MINDMAP.edges.map(edge => [edge.id, {...edge}])),
        selectedNodeId: null,
        viewport: storedViewport ?? { x: 80, y: 60, scale: 1 },
        mode: null,
        connectStart: null,
    };

    const edgeLayer = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    edgeLayer.setAttribute('id', 'edge-layer');
    const nodesLayer = document.createElement('div');
    nodesLayer.id = 'nodes-layer';
    surface.append(edgeLayer, nodesLayer);

    const applyViewport = () => {
        surface.style.width = `${state.mindmap.canvas_w}px`;
        surface.style.height = `${state.mindmap.canvas_h}px`;
        surface.style.transform = `translate(${state.viewport.x}px, ${state.viewport.y}px) scale(${state.viewport.scale})`;
    };

    const toCanvasCoords = (clientX, clientY) => {
        const rect = canvas.getBoundingClientRect();
        return {
            x: (clientX - rect.left - state.viewport.x) / state.viewport.scale,
            y: (clientY - rect.top - state.viewport.y) / state.viewport.scale,
        };
    };

    const updateStatus = () => {
        const node = state.selectedNodeId ? state.nodes.get(state.selectedNodeId) : null;
        if (node) {
            statusText.textContent = `已选中节点：${node.text}（x: ${node.x.toFixed(0)}, y: ${node.y.toFixed(0)}）`;
        } else {
            statusText.textContent = '请选择一个节点以开始编辑。';
        }
        modeStatus.textContent = state.mode === 'connect'
            ? '连接模式：依次点选两个节点即可连线'
            : state.mode === 'disconnect'
                ? '移除模式：依次点选已有连线的两个节点即可断开'
                : '';
        document.getElementById('rename-node').disabled = !node;
        document.getElementById('delete-node').disabled = !node;
    };

    const showToast = (message) => {
        if (!toastEl) return;
        toastEl.textContent = message;
        toastEl.classList.add('visible');
        setTimeout(() => toastEl.classList.remove('visible'), 2200);
    };

    const renderEdges = () => {
        edgeLayer.innerHTML = '';
        const nodes = state.nodes;
        state.edges.forEach(edge => {
            const from = nodes.get(edge.from_node_id);
            const to = nodes.get(edge.to_node_id);
            if (!from || !to) {
                return;
            }
            const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
            const startX = from.x + from.width / 2;
            const startY = from.y + from.height / 2;
            const endX = to.x + to.width / 2;
            const endY = to.y + to.height / 2;
            const deltaX = (endX - startX) * 0.4;
            const deltaY = (endY - startY) * 0.4;
            const d = `M ${startX} ${startY} C ${startX + deltaX} ${startY + deltaY}, ${endX - deltaX} ${endY - deltaY}, ${endX} ${endY}`;
            path.setAttribute('d', d);
            edgeLayer.appendChild(path);
        });
    };

    const renderNodes = () => {
        nodesLayer.innerHTML = '';
        state.nodes.forEach(node => {
            const el = document.createElement('div');
            el.className = 'mindmap-node';
            if (node.id === state.selectedNodeId) {
                el.classList.add('selected');
            }
            el.dataset.nodeId = String(node.id);
            el.style.transform = `translate(${node.x}px, ${node.y}px)`;
            el.style.width = `${node.width}px`;
            el.style.height = `${node.height}px`;
            const title = document.createElement('strong');
            title.textContent = node.text;
            const coords = document.createElement('div');
            coords.className = 'coords';
            coords.textContent = `(${Math.round(node.x)}, ${Math.round(node.y)})`;
            el.append(title, coords);

            el.addEventListener('pointerdown', (event) => {
                event.stopPropagation();
                const pointerId = event.pointerId;
                const nodeId = Number(el.dataset.nodeId);
                const selectedChanged = state.selectedNodeId !== nodeId;
                state.selectedNodeId = nodeId;
                if (state.mode === 'connect') {
                    if (state.connectStart === null) {
                        state.connectStart = nodeId;
                        showToast('请选择要连接的目标节点');
                    } else if (state.connectStart !== nodeId) {
                        createEdge(state.connectStart, nodeId);
                        state.mode = null;
                        state.connectStart = null;
                    }
                } else if (state.mode === 'disconnect') {
                    if (state.connectStart === null) {
                        state.connectStart = nodeId;
                        showToast('选择另一个节点以移除连线');
                    } else if (state.connectStart !== nodeId) {
                        removeEdge(state.connectStart, nodeId);
                        state.mode = null;
                        state.connectStart = null;
                    }
                }
                updateStatus();
                renderNodes();
                if (selectedChanged) {
                    renderEdges();
                }

                const startPointer = toCanvasCoords(event.clientX, event.clientY);
                const nodeStart = { x: node.x, y: node.y };
                canvas.setPointerCapture(pointerId);

                const moveHandler = (moveEvent) => {
                    const point = toCanvasCoords(moveEvent.clientX, moveEvent.clientY);
                    node.x = point.x - (startPointer.x - nodeStart.x);
                    node.y = point.y - (startPointer.y - nodeStart.y);
                    renderNodes();
                    renderEdges();
                };

                const upHandler = async () => {
                    canvas.releasePointerCapture(pointerId);
                    canvas.removeEventListener('pointermove', moveHandler);
                    canvas.removeEventListener('pointerup', upHandler);
                    await persistNodes([{ id: node.id, x: node.x, y: node.y }]);
                    updateStatus();
                };

                canvas.addEventListener('pointermove', moveHandler);
                canvas.addEventListener('pointerup', upHandler);
            });

            nodesLayer.appendChild(el);
        });
    };

    const queueViewportSave = (() => {
        let timer = null;
        return () => {
            clearTimeout(timer);
            timer = setTimeout(async () => {
                try {
                    const res = await fetch(withBase(`/api/v1/mindmaps/${mindmapId}`), {
                        method: 'PATCH',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ viewport: JSON.stringify(state.viewport) }),
                    });
                    if (!res.ok) {
                        throw new Error('Failed to persist viewport');
                    }
                    state.mindmap.viewport = JSON.stringify(state.viewport);
                } catch (error) {
                    console.error('保存视图失败', error);
                }
            }, 400);
        };
    })();

    const render = () => {
        applyViewport();
        renderNodes();
        renderEdges();
        updateStatus();
    };

    const persistNodes = async (items) => {
        try {
            const res = await fetch(withBase(`/api/v1/mindmaps/${mindmapId}/nodes`), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ upsert: items }),
            });
            if (!res.ok) {
                throw new Error('更新节点失败');
            }
            const data = await res.json();
            hydrate(data.mindmap);
        } catch (error) {
            console.error(error);
            showToast('保存节点失败');
        }
    };

    const persistEdges = async (changes) => {
        try {
            const res = await fetch(withBase(`/api/v1/mindmaps/${mindmapId}/edges`), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(changes),
            });
            if (!res.ok) {
                throw new Error('更新连线失败');
            }
            const data = await res.json();
            hydrate(data.mindmap);
        } catch (error) {
            console.error(error);
            showToast('保存连线失败');
        }
    };

    const hydrate = (mindmap) => {
        state.mindmap = mindmap;
        state.nodes = new Map(mindmap.nodes.map(node => [node.id, {...node}]));
        state.edges = new Map(mindmap.edges.map(edge => [edge.id, {...edge}]));
        render();
    };

    const createNode = async () => {
        const current = state.selectedNodeId ? state.nodes.get(state.selectedNodeId) : null;
        const title = prompt('请输入节点标题', current ? `${current.text} 子节点` : '新节点');
        if (title === null) {
            return;
        }
        const nodePayload = {
            text: title.trim() === '' ? '新节点' : title.trim(),
            x: current ? current.x + current.width + 120 : 40,
            y: current ? current.y + current.height + 40 : 40,
            width: 200,
            height: 100,
            parent_id: current ? current.id : null,
        };
        try {
            const res = await fetch(withBase(`/api/v1/mindmaps/${mindmapId}/nodes`), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ upsert: [nodePayload] }),
            });
            if (!res.ok) {
                throw new Error('创建节点失败');
            }
            const data = await res.json();
            hydrate(data.mindmap);
            const created = data.mindmap.nodes[data.mindmap.nodes.length - 1];
            state.selectedNodeId = created.id;
            if (current) {
                await persistEdges({ upsert: [{ from_node_id: current.id, to_node_id: created.id }] });
            }
            render();
            showToast('节点创建成功');
        } catch (error) {
            console.error(error);
            showToast('节点创建失败');
        }
    };

    const renameNode = async () => {
        const node = state.selectedNodeId ? state.nodes.get(state.selectedNodeId) : null;
        if (!node) {
            return;
        }
        const title = prompt('请输入新的节点标题', node.text);
        if (title === null) {
            return;
        }
        node.text = title.trim() === '' ? node.text : title.trim();
        await persistNodes([{ id: node.id, text: node.text }]);
    };

    const deleteNode = async () => {
        const node = state.selectedNodeId ? state.nodes.get(state.selectedNodeId) : null;
        if (!node) {
            return;
        }
        if (!confirm('确认删除该节点及相关连线？')) {
            return;
        }
        try {
            const res = await fetch(withBase(`/api/v1/mindmaps/${mindmapId}/nodes`), {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ delete: [node.id] }),
            });
            if (!res.ok) {
                throw new Error('删除节点失败');
            }
            const data = await res.json();
            hydrate(data.mindmap);
            state.selectedNodeId = null;
            render();
            showToast('节点已删除');
        } catch (error) {
            console.error(error);
            showToast('节点删除失败');
        }
    };

    const createEdge = async (fromId, toId) => {
        const exists = Array.from(state.edges.values()).some(edge => (
            (edge.from_node_id === fromId && edge.to_node_id === toId) ||
            (edge.from_node_id === toId && edge.to_node_id === fromId)
        ));
        if (exists) {
            showToast('两节点之间已存在连线');
            return;
        }
        await persistEdges({ upsert: [{ from_node_id: fromId, to_node_id: toId }] });
        showToast('连线已创建');
    };

    const removeEdge = async (fromId, toId) => {
        const existing = Array.from(state.edges.values()).find(edge => {
            return (edge.from_node_id === fromId && edge.to_node_id === toId) || (edge.from_node_id === toId && edge.to_node_id === fromId);
        });
        if (!existing) {
            showToast('未找到两节点间的连线');
            return;
        }
        await persistEdges({ delete: [existing.id] });
        showToast('连线已删除');
    };

    document.getElementById('add-node').addEventListener('click', createNode);
    document.getElementById('rename-node').addEventListener('click', renameNode);
    document.getElementById('delete-node').addEventListener('click', deleteNode);
    document.getElementById('connect-nodes').addEventListener('click', () => {
        state.mode = state.mode === 'connect' ? null : 'connect';
        state.connectStart = null;
        updateStatus();
        showToast(state.mode === 'connect' ? '连接模式开启，请依次选择两个节点' : '连接模式已关闭');
    });
    document.getElementById('disconnect-nodes').addEventListener('click', () => {
        state.mode = state.mode === 'disconnect' ? null : 'disconnect';
        state.connectStart = null;
        updateStatus();
        showToast(state.mode === 'disconnect' ? '移除模式开启，请选择要断开的两个节点' : '移除模式已关闭');
    });

    const adjustZoom = (delta) => {
        state.viewport.scale = Math.min(2.5, Math.max(0.4, state.viewport.scale + delta));
        applyViewport();
        queueViewportSave();
    };

    document.getElementById('zoom-in').addEventListener('click', () => adjustZoom(0.1));
    document.getElementById('zoom-out').addEventListener('click', () => adjustZoom(-0.1));
    document.getElementById('reset-view').addEventListener('click', () => {
        state.viewport = { x: 80, y: 60, scale: 1 };
        applyViewport();
        queueViewportSave();
    });

    let panning = false;
    let panStart = { x: 0, y: 0 };
    let viewportStart = { x: 0, y: 0 };
    let panPointerId = null;

    canvas.addEventListener('pointerdown', (event) => {
        if (event.target.closest && event.target.closest('.mindmap-node')) {
            return;
        }
        panning = true;
        panPointerId = event.pointerId;
        canvas.classList.add('grabbing');
        canvas.setPointerCapture(panPointerId);
        panStart = { x: event.clientX, y: event.clientY };
        viewportStart = { ...state.viewport };
        event.preventDefault();
    });

    canvas.addEventListener('pointermove', (event) => {
        if (!panning || event.pointerId !== panPointerId) {
            return;
        }
        const deltaX = event.clientX - panStart.x;
        const deltaY = event.clientY - panStart.y;
        state.viewport.x = viewportStart.x + deltaX;
        state.viewport.y = viewportStart.y + deltaY;
        applyViewport();
    });

    const endPan = (event) => {
        if (!panning || (event && event.pointerId !== panPointerId)) {
            return;
        }
        panning = false;
        canvas.classList.remove('grabbing');
        if (panPointerId !== null) {
            try {
                canvas.releasePointerCapture(panPointerId);
            } catch (error) {
                // ignore release errors
            }
        }
        panPointerId = null;
        queueViewportSave();
    };

    canvas.addEventListener('pointerup', endPan);
    canvas.addEventListener('pointercancel', endPan);

    window.addEventListener('resize', () => {
        renderEdges();
    });

    render();
}
</script>
</body>
</html>
