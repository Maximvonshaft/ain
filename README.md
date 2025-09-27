# Memo Suite (Laravel-inspired)

由于网络限制无法直接安装 Laravel 内核，本项目将原始 `memo.php` 单文件应用迁移为模块化的 Laravel 风格结构。代码保持 SQLite 存储与 AJAX 交互，便于后续替换为正式的 Laravel 11 应用。

## 目录结构概览

```
app/                # 控制器、服务、仓储、模型等分层
bootstrap/          # 简易启动流程与自动加载
config/             # 应用与数据库配置（支持 .env）
public/index.php    # 前端控制器
resources/views/    # Blade 风格的 PHP 视图
routes/             # Web 与 API 路由定义
storage/            # 上传、日志、缓存目录（预留）
legacy/             # 保留原始 memo.php 版本
```

## 快速开始

```bash
cp .env.example .env
php bin/migrate          # 初始化 SQLite 数据库与表结构
php -S localhost:8000 -t public
```

访问 http://localhost:8000 可体验基础备忘录、子任务、思维导图等功能。

### 思维导图模块

- 在备忘录卡片底部点击“创建思维导图”即可为该备忘录生成导图，并跳转到画布页。
- 画布支持拖拽节点、双指/按钮缩放、连接/断开节点、重命名与删除操作，移动端会自动切换为底部滚动工具栏。
- REST API 位于 `/api/v1/mindmaps/...`，其中：
  - `PATCH /api/v1/mindmaps/{id}/nodes` 支持批量新增/更新/删除节点；
  - `PATCH /api/v1/mindmaps/{id}/edges` 用于批量维护连线；
  - `POST /api/v1/memos/{memo}/mindmaps` 为指定备忘录创建导图。
  所有 API 自动应用 `APP_BASE_PATH` 前缀，可直接部署在子目录环境。

### 部署在子目录

如需将应用部署在 `https://domain.com/memo/` 等子目录下：

1. 在 `.env` 中将 `APP_URL` 设置为完整地址（例如 `https://domain.com/memo`）。
2. 若服务器无法正确传递 `REQUEST_URI`，可以额外指定 `APP_BASE_PATH=memo` 强制前缀。
3. Web 服务器需要把 `/memo/...` 的请求重写到 `public/index.php`，例如：

   ```nginx
   location /memo/ {
       try_files $uri $uri/ /memo/index.php?$query_string;
   }
   ```

前端的 AJAX 与后端路由都会自动套用配置的子目录前缀，无需再手工调整。

## 后续迁移建议

- 将当前自定义容器替换为官方 Laravel 容器，复用现有分层代码。
- 引入真正的路由、中间件、Eloquent ORM 与队列。
- 接入 Markdown 渲染、附件上传、标签检索等高级功能。

