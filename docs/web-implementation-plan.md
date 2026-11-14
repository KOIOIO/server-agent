# Vue + Laravel 任务工作台

目标：实现上传、持久化任务/版本、数据库队列、Codex 三角色执行、Markdown/JSON 交付及反馈。
架构：Vue 通过 /api 调用 Laravel；Laravel Job 调用 Codex CLI，显式挂载现有两个 MCP。SQLite 用于单机开发。CLI 无密钥时明确失败，不生成假结果。

1. 初始化 Laravel、Vue/Vite，使用真实依赖并验证构建。
2. API 测试先行：JSON 上传、列表、详情、反馈和产物权限路径。
3. 持久化任务与 revisions；队列驱动编排，保存角色产物和状态，控制执行时限。
4. 前端实现上传、轮询、错误提示、版本选择与下载。
5. 测试 API、队列失败路径、前端构建和已有 MCP 回归；记录运行与部署限制。
