<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_and_feedback_preserve_revisions(): void
    {
        Queue::fake();
        $file = UploadedFile::fake()->createWithContent('batch.json', file_get_contents(base_path('../fixtures/fresh-document-analysis-9-v4.json')));
        $task = $this->postJson('/api/tasks', ['title' => '模型对比', 'prompt' => '比较附件中的两个模型', 'files' => [$file]])->assertCreated()->json();
        $this->getJson('/api/tasks')->assertOk()->assertJsonCount(1);
        $this->getJson('/api/tasks/'.$task['id'])->assertOk()->assertJsonPath('revisions.0.status', 'queued');
        $this->postJson('/api/tasks/'.$task['id'].'/feedback', ['feedback' => 'more details'])->assertStatus(409);
        DB::table('revisions')->update(['status' => 'completed']);
        $this->postJson('/api/tasks/'.$task['id'].'/feedback', ['feedback' => 'more details'])->assertCreated();
        $this->getJson('/api/tasks/'.$task['id'])->assertJsonCount(2, 'revisions');
    }

    public function test_missing_prompt_rejected(): void
    {
        Queue::fake();
        $this->postJson('/api/tasks', ['file' => UploadedFile::fake()->createWithContent('bad.json', '{"cases":[]}')])->assertUnprocessable();
    }

    public function test_prompt_only_and_arbitrary_attachments_are_supported(): void
    {
        Queue::fake();
        $this->postJson('/api/tasks', ['title' => '服务检查', 'prompt' => '请说明可用工具是否支持服务状态检查'])
            ->assertCreated();
        $response = $this->postJson('/api/tasks', ['title' => '文本任务', 'prompt' => '总结附件', 'files' => [UploadedFile::fake()->createWithContent('notes.txt', 'meeting notes')]])->assertCreated();
        $detail = $this->getJson('/api/tasks/'.$response->json('id'))->assertOk();
        $detail->assertJsonPath('prompt', '总结附件')->assertJsonPath('attachments.0.name', 'notes.txt');
    }

    public function test_unknown_artifact_not_exposed(): void
    {
        $this->getJson('/api/tasks/missing/revisions/1/artifacts/json')->assertNotFound();
    }
}
