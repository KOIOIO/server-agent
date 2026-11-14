# 双 MCP Demo 实施计划

设计：两个独立 stdio 进程，使用官方 Python MCP SDK。数据服务只读固定 fixtures 根目录；模型服务只调用配置中的 provider，密钥保留在进程中。Agent 负责跨服务传递规范化 case。模型服务保存原始回答，不自行宣称评分或胜负。

1. 先写失败测试：真实九 case 解码提取、路径穿越、损坏 Base64、模型 HTTP 请求和错误隔离。
2. 实现数据读取与格式识别，显式报告大小/MIME 不一致，不静默丢弃样本。
3. 实现 provider 配置、限时调用、结果持久化和错误脱敏。
4. 注册两个 MCP 服务，测试真实 stdio initialize/list_tools/call_tool。
5. 提供环境配置、Codex 配置示例和运行说明。真实 API 验证等待密钥及两个模型 ID。
