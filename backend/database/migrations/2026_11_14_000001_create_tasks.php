<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('title');
            $t->string('batch_no')->nullable();
            $t->unsignedInteger('case_count');
            $t->string('input_path');
            $t->timestamps();
        });
        Schema::create('revisions', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('task_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('number');
            $t->string('status')->default('queued');
            $t->text('feedback')->nullable();
            $t->text('error')->nullable();
            $t->json('result')->nullable();
            $t->text('markdown')->nullable();
            $t->timestamps();
            $t->unique(['task_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
        Schema::dropIfExists('tasks');
    }
};
