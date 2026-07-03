<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * On PostgreSQL, `foreignId()->constrained()` creates the FK constraint but
 * does NOT create an index on the FK column (unlike MySQL/InnoDB). Every
 * relationship load and reorder query was therefore a sequential scan. These
 * composite indexes cover the hot access patterns: the (fk, order) pairs match
 * both the eager-load (WHERE fk IN (...)) and the ORDER BY / reorder range
 * scans in one shot.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boards', function (Blueprint $table): void {
            $table->index(['user_id', 'is_active'], 'boards_user_id_is_active_index');
        });

        Schema::table('columns', function (Blueprint $table): void {
            $table->index(['board_id', 'order'], 'columns_board_id_order_index');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->index(['column_id', 'order'], 'tasks_column_id_order_index');
        });

        Schema::table('subtasks', function (Blueprint $table): void {
            $table->index(['task_id', 'order'], 'subtasks_task_id_order_index');
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->index('user_id', 'tags_user_id_index');
        });

        Schema::table('task_tag', function (Blueprint $table): void {
            // Composite PK already covers task_id-first lookups; this covers the
            // reverse (tag_id) direction.
            $table->index('tag_id', 'task_tag_tag_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('boards', function (Blueprint $table): void {
            $table->dropIndex('boards_user_id_is_active_index');
        });

        Schema::table('columns', function (Blueprint $table): void {
            $table->dropIndex('columns_board_id_order_index');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropIndex('tasks_column_id_order_index');
        });

        Schema::table('subtasks', function (Blueprint $table): void {
            $table->dropIndex('subtasks_task_id_order_index');
        });

        Schema::table('tags', function (Blueprint $table): void {
            $table->dropIndex('tags_user_id_index');
        });

        Schema::table('task_tag', function (Blueprint $table): void {
            $table->dropIndex('task_tag_tag_id_index');
        });
    }
};
