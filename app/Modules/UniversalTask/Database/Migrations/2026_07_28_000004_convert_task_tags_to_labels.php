<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_label', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('task_label_id')->constrained('task_labels')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_id', 'task_label_id']);
        });

        if (Schema::hasColumn('tasks', 'tags')) {
            DB::table('tasks')
                ->select('id', 'tags')
                ->whereNotNull('tags')
                ->orderBy('id')
                ->chunkById(100, function ($tasks) {
                    foreach ($tasks as $task) {
                        $tags = json_decode($task->tags, true);
                        if (!is_array($tags)) {
                            continue;
                        }

                        foreach ($tags as $tag) {
                            $name = trim((string) $tag);
                            if ($name === '') {
                                continue;
                            }

                            $slug = Str::slug($name);
                            if ($slug === '') {
                                continue;
                            }

                            $label = DB::table('task_labels')->where('slug', $slug)->first();
                            if (!$label) {
                                $labelId = DB::table('task_labels')->insertGetId([
                                    'name' => Str::headline($name),
                                    'slug' => $slug,
                                    'color' => '#2563eb',
                                    'is_active' => true,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            } else {
                                $labelId = $label->id;
                            }

                            DB::table('task_label')->updateOrInsert(
                                ['task_id' => $task->id, 'task_label_id' => $labelId],
                                ['created_at' => now(), 'updated_at' => now()]
                            );
                        }
                    }
                });

            Schema::table('tasks', function (Blueprint $table) {
                $table->dropColumn('tags');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasColumn('tasks', 'tags')) {
            Schema::table('tasks', function (Blueprint $table) {
                $table->json('tags')->nullable()->after('completed_at');
            });
        }

        DB::table('tasks')->orderBy('id')->chunkById(100, function ($tasks) {
            foreach ($tasks as $task) {
                $labels = DB::table('task_label')
                    ->join('task_labels', 'task_label.task_label_id', '=', 'task_labels.id')
                    ->where('task_label.task_id', $task->id)
                    ->pluck('task_labels.name')
                    ->values()
                    ->all();

                DB::table('tasks')->where('id', $task->id)->update([
                    'tags' => $labels ? json_encode($labels) : null,
                ]);
            }
        });

        Schema::dropIfExists('task_label');
    }
};
