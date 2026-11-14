<?php

namespace Tests\Feature;

use App\Jobs\RunTask;
use App\Services\AgentOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RunTaskTest extends TestCase
{
    use RefreshDatabase;

    private function revision(): int
    {
        Queue::fake();
        $this->postJson('/api/tasks', ['title' => '通用任务', 'prompt' => '总结附件', 'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'hello')]])->assertCreated();

        return DB::table('revisions')->value('id');
    }

    public function test_cli_failure_marks_failed_instead_of_completed(): void
    {
        $id = $this->revision();
        config(['agents.codex' => '/nonexistent/codex']);
        (new RunTask($id))->handle(new AgentOrchestrator);
        $this->assertDatabaseHas('revisions', ['id' => $id, 'status' => 'failed', 'result' => null]);
    }

    public function test_role_pipeline_persists_report_without_real_api_calls(): void
    {
        $id = $this->revision();
        $script = storage_path('framework/testing/fake-codex.php');
        if (! is_dir(dirname($script))) {
            mkdir(dirname($script), 0700, true);
        }
        file_put_contents($script, <<<'SCRIPT'
#!/usr/bin/env php
<?php
$index=array_search('-o',$argv);$output=$argv[$index+1];
if (!in_array('--dangerously-bypass-approvals-and-sandbox',$argv) || in_array('--sandbox',$argv)) { exit(43); }
if (getenv('CODEX_PROVIDER_API_KEY') !== 'test-relay-key' || !in_array('model_provider="task_provider"',$argv) || !in_array('model_providers.task_provider.base_url="https://relay.example/v1"',$argv) || in_array('test-relay-key',$argv)) { exit(42); }
file_put_contents($output,json_encode(['status'=>'pass','findings'=>'verified','markdown'=>'# Verified report','results'=>[['name'=>'summary','details'=>'Test evidence only']]]));
SCRIPT);
        chmod($script, 0700);
        config(['agents.codex' => $script, 'agents.provider_key' => 'test-relay-key', 'agents.base_url' => 'https://relay.example/v1', 'agents.model' => 'gpt-6-astra']);
        try {
            (new RunTask($id))->handle(new AgentOrchestrator);
            $this->assertDatabaseHas('revisions', ['id' => $id, 'status' => 'completed']);
            $task = DB::table('revisions')->find($id)->task_id;
            $this->get('/api/tasks/'.$task.'/revisions/1/artifacts/markdown')->assertOk()->assertSee('# Verified report');
        } finally {
            unlink($script);
        }
    }

    public function test_commander_can_request_input_or_reject_after_bounded_rework(): void
    {
        foreach (['needs_user_input', 'revise'] as $decision) {
            $id = $this->revision();
            $script = storage_path('framework/testing/decision-codex.php');
            file_put_contents($script, "#!/usr/bin/env php\n<?php\n".'$output=$argv[array_search("-o",$argv)+1]; $status=basename($output)==="commander-final.json" ? '.var_export($decision, true).' : "pass"; file_put_contents($output,json_encode(["status"=>$status,"findings"=>"Need more evidence","markdown"=>"","results"=>[]]));');
            chmod($script, 0700);
            config(['agents.codex' => $script]);
            try {
                (new RunTask($id))->handle(new AgentOrchestrator);
                $this->assertDatabaseHas('revisions', ['id' => $id, 'status' => $decision === 'revise' ? 'failed_review' : 'needs_user_input', 'error' => 'Need more evidence']);
            } finally {
                unlink($script);
            }
        }
    }

    public function test_job_does_not_rerun_claimed_revision(): void
    {
        $id = $this->revision();
        DB::table('revisions')->where('id', $id)->update(['status' => 'completed']);
        $runner = \Mockery::mock(AgentOrchestrator::class);
        $runner->shouldNotReceive('run');
        (new RunTask($id))->handle($runner);
    }
}
