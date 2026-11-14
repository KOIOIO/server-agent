<?php

namespace App\Jobs;

use App\Services\AgentOrchestrator;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class RunTask implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 5000;

    public function __construct(public int $revisionId) {}

    public function handle(AgentOrchestrator $runner): void
    {
        if (! DB::table('revisions')->where('id', $this->revisionId)->where('status', 'queued')->update(['status' => 'planning', 'updated_at' => now()])) {
            return;
        }
        try {
            $runner->run($this->revisionId);
        } catch (\Throwable $e) {
            DB::table('revisions')->where('id', $this->revisionId)->update(['status' => 'failed', 'error' => 'Agent 执行失败，请检查服务端日志及 CLI/MCP 配置。', 'updated_at' => now()]);
            report($e);
        }
    }

    public function failed(?\Throwable $e): void
    {
        DB::table('revisions')->where('id', $this->revisionId)->update(['status' => 'failed', 'error' => 'Worker 超时或异常退出。', 'updated_at' => now()]);
    }
}
