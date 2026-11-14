<?php

return ['base_url' => env('CODEX_BASE_URL'), 'model' => env('CODEX_MODEL', 'gpt-6-astra'), 'provider_key' => env('CODEX_PROVIDER_API_KEY'), 'deepseek_key' => env('DEEPSEEK_API_KEY'), 'codex' => env('CODEX_BIN', 'codex'), 'python' => env('MCP_PYTHON', base_path('../.venv/bin/python')), 'root' => realpath(base_path('..')), 'timeout' => (int) env('AGENT_TIMEOUT', 600)];
