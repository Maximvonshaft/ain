# Memo 重构迁移

本仓库进入迁移阶段，现已完成第一阶段：在现有项目中搭建 Laravel 12 骨架并保留原有单体应用以便对照与回归测试。

## 目录结构概览

- `app/`、`bootstrap/`、`config/`、`public/` 等：全新的 Laravel 应用骨架。
- `legacy-app/`：原始的单体 Memo 应用代码与资源，后续模块会逐步迁移至 Laravel 体系。
- `legacy-app/memo.sqlite`：遗留系统沿用的 SQLite 数据库文件（初始为空，可在运行时自动创建或由迁移脚本导入）。
- `pint.json`：代码风格配置，排除遗留目录以避免格式化差异干扰迁移。

## 本地开发流程

1. 复制环境配置：
   ```bash
   cp .env.example .env
   ```
2. 安装依赖：
   ```bash
   composer install
   npm install
   ```
3. 生成应用密钥并准备数据库：
   ```bash
   php artisan key:generate
   # Laravel 默认数据库位于 database/database.sqlite
   ```
4. 启动开发服务器：
   ```bash
   php artisan serve
   ```

## 环境变量说明

`.env` 中已同步原有应用的关键设置：

- `APP_NAME=Memo`、`APP_TIMEZONE=Asia/Shanghai`：保持遗留系统的应用名与时区。
- `LEGACY_DATABASE_PATH`：指向遗留应用的 SQLite 文件路径，`DB_DATABASE` 默认引用该值，便于新旧系统共用数据。
- `LEGACY_UPLOAD_PATH` 与 `LEGACY_UPLOAD_MAX_BYTES`：约束附件上传目录和大小，为后续迁移上传功能做准备。

如需在本地创建独立数据库，可将 `DB_DATABASE` 调整为 `database/database.sqlite`，并执行 `php artisan migrate` 初始化结构。

## 常用指令

- 代码风格检查：`./vendor/bin/pint`
- 自动化测试：`composer test`

## 下一步规划

在 Laravel 骨架上逐步拆分路由、控制器与数据访问逻辑，引入框架自带的服务提供者、路由与 ORM，最终替换遗留运行器。
