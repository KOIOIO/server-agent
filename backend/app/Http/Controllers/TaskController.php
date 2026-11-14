<?php

namespace App\Http\Controllers;

use App\Jobs\RunTask;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TaskController extends Controller
{
    public function index()
    {
        return DB::table('tasks')->latest()->limit(100)->get()->map(function ($t) {
            $t->latest_revision = DB::table('revisions')->where('task_id', $t->id)->orderByDesc('number')->first();

            return $t;
        });
    }

    public function show(string $id)
    {
        $t = DB::table('tasks')->where('id', $id)->first();
        abort_unless($t, 404);
        $t->attachments = $t->attachments ? json_decode($t->attachments, true) : [];
        $t->revisions = DB::table('revisions')->where('task_id', $id)->orderByDesc('number')->get()->map(function ($r) {
            $r->result = $r->result ? json_decode($r->result, true) : null;

            return $r;
        });

        return response()->json($t);
    }

    public function store(Request $request)
    {
        $request->validate(['title' => 'required|string|max:200', 'prompt' => 'required|string|max:50000', 'files' => 'sometimes|array|max:10', 'files.*' => 'required|file|max:25600']);
        $files = $request->file('files', []);
        abort_if(array_sum(array_map(fn ($file) => $file->getSize(), $files)) > 25 * 1024 * 1024, 422, '附件总大小不能超过 25 MiB');
        $id = (string) Str::uuid();
        $attachments = [];
        foreach ($files as $index => $file) {
            $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
            $relative = 'attachments/'.($index + 1).'-'.preg_replace('/[^a-zA-Z0-9._-]/', '_', $name);
            Storage::disk('local')->put("tasks/$id/$relative", file_get_contents($file->getRealPath()));
            $attachments[] = ['name' => $name, 'path' => $relative, 'size' => $file->getSize()];
        }
        $path = "tasks/$id/input.json";
        $input = ['prompt' => $request->input('prompt'), 'attachments' => $attachments];
        Storage::disk('local')->put($path, json_encode($input, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $revision = DB::transaction(function () use ($id, $path, $request, $attachments) {
            DB::table('tasks')->insert(['id' => $id, 'title' => $request->input('title'), 'prompt' => $request->input('prompt'), 'attachments' => json_encode($attachments), 'batch_no' => null, 'case_count' => 0, 'input_path' => $path, 'created_at' => now(), 'updated_at' => now()]);

            return DB::table('revisions')->insertGetId(['task_id' => $id, 'number' => 1, 'status' => 'queued', 'created_at' => now(), 'updated_at' => now()]);
        });
        RunTask::dispatch($revision);

        return response()->json(['id' => $id, 'revision' => 1, 'status' => 'queued'], 201);
    }

    public function feedback(Request $request, string $id)
    {
        $request->validate(['feedback' => 'required|string|max:10000']);
        $revision = DB::transaction(function () use ($request, $id) {
            abort_unless(DB::table('tasks')->where('id', $id)->lockForUpdate()->first(), 404);
            $last = DB::table('revisions')->where('task_id', $id)->orderByDesc('number')->first();
            abort_unless(in_array($last->status, ['completed', 'failed', 'needs_user_input', 'failed_review']), 409, '当前任务仍在运行');

            return DB::table('revisions')->insertGetId(['task_id' => $id, 'number' => $last->number + 1, 'status' => 'queued', 'feedback' => $request->input('feedback'), 'created_at' => now(), 'updated_at' => now()]);
        });
        RunTask::dispatch($revision);

        return response()->json(['revision_id' => $revision, 'status' => 'queued'], 201);
    }

    public function artifact(string $id, int $number, string $format)
    {
        abort_unless(in_array($format, ['json', 'markdown']), 404);
        $r = DB::table('revisions')->where('task_id', $id)->where('number', $number)->first();
        abort_unless($r && $r->status === 'completed', 404);

        return response($format === 'json' ? $r->result : $r->markdown)->header('Content-Type', $format === 'json' ? 'application/json' : 'text/markdown; charset=UTF-8')->header('Content-Disposition', 'attachment; filename="report-'.$number.'.'.($format === 'json' ? 'json' : 'md').'"');
    }
}
