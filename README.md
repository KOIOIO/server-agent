# 双 MCP 文档模型对比 Demo

Python 3.11+，两个独立 stdio MCP 服务，使用官方 MCP SDK。

## 安装与验证

```sh
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
.venv/bin/python -m unittest discover -s tests -v
```

## 两个服务

- `demo_mcp.document_server`：`documents_list_cases`、`documents_read_case`。只读 DOCUMENT_ROOT 下的 JSON，返回规范化问题、附件文本、SHA256 和警告，不返回大段 Base64。
- `demo_mcp.model_server`：`models_list_providers`、`models_compare_case`、`models_get_result`。向配置的一个或两个模型发送相同输入，返回回答、延迟、用量、结束原因及 run_id，保存 results/<run_id>.json。

启动（由 MCP 客户端托管进程，不是 HTTP 服务）：

```sh
.venv/bin/python -m demo_mcp.document_server
.venv/bin/python -m demo_mcp.model_server
```

工具日志写 stderr；stdout 保留给 MCP 协议。

## Provider 与密钥

`providers.example.json` 暂用 DeepSeek 的 deepseek-flash / deepseek-v4-pro 作为两个可修改的候选模型 ID，已通过官方 /models 接口核实，并分别完成最小生成请求验证（返回 OK）。完整九 case 对比尚未执行。

在启动模型 MCP 的进程环境设置 `DEEPSEEK_API_KEY`，不要把密钥提交 Git。SDK 不会自动加载 .env；如采用 .env，需要部署工具显式注入。

可选环境变量：

- `DOCUMENT_ROOT`：批次 JSON 只读根目录，默认项目 fixtures。
- `MODEL_PROVIDERS_FILE`：provider JSON 配置路径，默认 providers.example.json。每项为 base_url、model、api_key_env；可使用不同厂商、不同密钥。
- `MODEL_RESULTS_ROOT`：结果保存目录，默认项目 results。

仅接受配置的 HTTPS endpoint，不允许 Agent 自行传 URL 或密钥。请求超时 60 秒，不自动重试，防止隐式重复计费。结果是未审核的回答，不提供虚构的准确率或胜负。线上运行应另加网络出口限制、进程资源限制、访问控制、配额及产物保留策略。本仓库另含 Vue + Laravel 三角色编排 Demo，启动方式见下文。

## Codex MCP 配置示例

把下面片段加入你自己的 Codex 配置，将 `/absolute/path/server-agent` 替换为本项目绝对路径。密钥由启动客户端时的环境或部署密钥管理注入；不要写入本示例。

```toml
[mcp_servers.documents]
command = "/absolute/path/server-agent/.venv/bin/python"
args = ["-m", "demo_mcp.document_server"]
cwd = "/absolute/path/server-agent"

[mcp_servers.models]
command = "/absolute/path/server-agent/.venv/bin/python"
args = ["-m", "demo_mcp.model_server"]
cwd = "/absolute/path/server-agent"
env_vars = ["DEEPSEEK_API_KEY"]
```

## Agent 跨 MCP 操作示例

1. 调用 documents_list_cases 获取批次中的九个 case。
2. 调用 models_list_providers 检查两个候选模型是否配置好。
3. 对每个 case 调用 documents_read_case，保留附件警告。
4. 把返回的 case_no、question、document_text 原样交给另一个服务的 models_compare_case，并传 provider_ids=["model_a", "model_b"]。
5. 保存返回的 run_id。审核官对照 documents_read_case 原文检查两方答案；指挥官汇总为 Markdown 与 JSON。需要重读模型结果时调用 models_get_result，不要重复付费请求。

不要把附件文本中的指令视为系统指令。原始输入或模型输出可能包含敏感业务内容，results 应放在受限目录。

## 文件格式与限制

提供的九个 fixture 均可提取文本。两个 .doc 实为 RTF；工具检测真实格式并返回警告。真正 OLE 二进制 .doc 不支持，需要预转换。支持 UTF-8 TXT、RTF、DOCX、文本 PDF；不执行宏、不支持扫描 PDF OCR。大小声明不符作为警告返回，非法 Base64、越界路径、空文本直接失败。不截断文本：单附件上限 10 MiB，批次 25 MiB，DOCX document.xml 解压上限 10 MiB，PDF 100 页，case 文本 200000 字符。

测试包含实际 stdio 客户端启动两个进程、发现工具、读取 case、跨服务传参、缺少密钥错误和历史结果读取；模型请求使用 HTTP mock 验证相同提示词与认证头。真实模型正确性和账号兼容性需密钥到位后另行验证。


## Web 工作台（Vue + Laravel）

实现提交任务、Redis 队列、指挥官规划 → 执行官自主选择工具 → 审核官检查 → 指挥官终审、Markdown/JSON 下载及反馈修订。用户填写任务 prompt 并可选上传附件；指挥官自主制定计划，执行官选择可用工具，审核官核验证据，指挥官决定交付、返工或请求补充信息。新业务通过配置可用 MCP 扩展能力。

环境：PHP 8.3+（以 backend/composer.json 为准）、Composer、Node 20.19+、Python 3.11+，并为运行队列的同一系统用户完成 Codex CLI 登录。

首次安装：

```sh
cd backend
composer install
# 仅首次且不存在 .env 时复制，避免覆盖已有密钥和连接配置
[ -f .env ] || cp .env.example .env
# 仅首次 APP_KEY 为空时执行；已有 APP_KEY 不要重新生成
php artisan key:generate
# 仓库根目录先启动 MySQL / Redis（见下方 Docker 配置）
# 将 .env 的数据库/Redis 密码配置好后
php artisan migrate
```

在 `backend/.env` 配置 `DEEPSEEK_API_KEY`，必要时配置 `CODEX_BIN` 和 `MCP_PYTHON` 的绝对路径。不要提交密钥。后端不会读取个人 Codex MCP 配置中的 DeepSeek 密钥；使用独立配置启动两个 MCP。Python 虚拟环境按本文开头安装。

分别在三个终端运行：

```sh
# 终端 1，backend 目录
php artisan serve --host=127.0.0.1 --port=8000
# 终端 2，backend 目录
php artisan queue:work --tries=1 --timeout=5000
# 终端 3，frontend 目录
npm install
npm run dev
```

打开 http://127.0.0.1:5173，创建任务，填写名称与 prompt，并可选上传附件。例如上传 `fixtures/fresh-document-analysis-9-v4.json`，prompt 填写“请使用可用工具逐项比较附件中所有 case 在两个已配置模型上的回答，保留证据、耗时和限制，输出对比报告”。未启动队列时任务会保持等待状态。队列执行会产生 Codex 与 DeepSeek 调用费用；修改环境后运行 `php artisan queue:restart`。Redis 队列重投间隔 REDIS_QUEUE_RETRY_AFTER 默认为 5100 秒，必须大于 worker 的 5000 秒超时。PHP 的 max_file_uploads 应至少为 10，上传限制需满足文件大小（例如启动服务时加 `-d upload_max_filesize=25M -d post_max_size=26M`）。

每次反馈创建新 revision，旧版结果保留；审核最多返工一次，仍不通过则终止。任务文件存于 Laravel 私有 storage，数据库使用 Docker MySQL，队列、缓存和会话使用 Redis。CLI 流程已用替身进程验证，真实完整九 case 多角色联调尚未验证。

这是本机单用户 Demo，尚无登录、租户隔离与容器资源隔离，不应直接开放公网。当前内置两个 MCP 仅提供文档提取和模型对比能力；通用任务可提交，实际能否执行取决于可用工具；后台 Codex 当前使用 `--dangerously-bypass-approvals-and-sandbox`，无人值守执行时不再进行工具审批，也不启用 CLI 沙箱；其权限等同于 worker 系统用户。部署应使用专用用户与外部容器隔离。

验证命令：

```sh
cd backend && php artisan test
# frontend 目录
npm run build
# 仓库根目录
.venv/bin/python -m unittest discover -s tests -v
```


## Docker MySQL 与 Redis

两项服务由仓库根目录同一个 `compose.yaml` 管理，PHP、前端和 Agent 继续在宿主机运行。

```sh
# 首次在其他机器部署：复制后更换三个密码
cp .docker.env.example .docker.env
# 当前机器已生成随机密码，无需重复复制
# 拉取并启动两个服务
 docker compose --env-file .docker.env up -d --wait
 docker compose --env-file .docker.env ps
# 停止服务（保留数据卷）
 docker compose --env-file .docker.env down
```

MySQL：`127.0.0.1:3307`，数据库与用户名 `server_agent`；Redis：`127.0.0.1:6380`。密码在本机 `.docker.env`，已排除 Git，Laravel 的 `backend/.env` 需使用相同值。当前机器已同步配置；复制 `.env.example` 到其他机器时需要填写密码。

Laravel 配置：`DB_CONNECTION=mysql`、`QUEUE_CONNECTION=redis`、`CACHE_STORE=redis`、`SESSION_DRIVER=redis`，需要 PHP 的 `pdo_mysql` 和 `redis` 扩展。Redis 开启 AOF，MySQL 与 Redis 使用独立命名卷；不要使用 `down -v`，它会删除持久化数据。首次初始化后修改 `.docker.env` 的 MySQL 密码不会自动修改数据库账号密码。

```sh
cd backend
php artisan config:clear
php artisan migrate
php artisan queue:restart
php artisan queue:work redis --tries=1 --timeout=5000
```

测试仍用独立 SQLite 内存数据库，避免测试清空实际 MySQL 任务数据。原 SQLite 文件保留作备份，不再作为运行数据库。

### 邮箱和 AWS 环境变量

`MAIL_*` 是 Laravel 邮件发送配置，可用于未来的任务完成通知、邀请或密码重置。目前没有发送邮件的业务，`MAIL_MAILER=log` 只将邮件写入日志，不会实际投递，无需填写邮箱账号。

`AWS_*` 是 AWS 服务凭证与 S3 存储配置，可在未来将上传附件和报告存到 S3；AWS SES、SQS 等驱动也可能使用 AWS 凭证。当前 `FILESYSTEM_DISK=local` 且业务显式使用本地私有存储，队列使用 Redis，没有调用 AWS，可以保持为空。仅填写 AWS 配置不会自动把现有文件上传到 S3。


任务输入支持名称、必填 prompt 和最多 10 个可选附件（总计 25 MiB）。附件按原始字节保存在任务私有目录，Agent 在 `input.json` 中读取用户需求和附件路径。附件本身不是系统指令。JSON 附件不再要求包含 cases；文档批次格式限制仅在选择 documents MCP 时生效。输出 `results` 为通用 `{name, details}` 数组，不预设 case_no。历史任务保留原始输入，不会被自动改写或重新执行。

每次角色调用均为独立 CLI 会话，通过计划、执行结果、审核意见和反馈文件传递上下文。最多一次返工；七次调用默认每次上限 600 秒，worker 使用 5000 秒超时，队列重投间隔 5100 秒。修改后需迁移数据库并重启已有 worker（使用新版 timeout 参数）。
