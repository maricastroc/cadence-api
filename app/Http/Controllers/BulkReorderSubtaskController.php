<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Subtask;
use App\Models\Task;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BulkReorderSubtaskController extends Controller
{
    use AuthorizesRequests;

    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'taskId' => ['required', 'integer', 'exists:tasks,id'],
            'subtasks' => ['required', 'array'],
            'subtasks.*.id' => ['required', 'integer', 'exists:subtasks,id'],
            'subtasks.*.order' => ['required', 'integer', 'min:1'],
        ]);

        // All subtasks belong to the one task, so authorize once against the
        // parent instead of loading + authorizing every subtask in a loop.
        $task = Task::findOrFail($validated['taskId']);
        $this->authorize('update', $task);

        $ids = array_column($validated['subtasks'], 'id');

        // Persist every new order in a single UPDATE via `CASE id WHEN ..`,
        // instead of a findOrFail + save per row (which was ~3N queries). The
        // ids/orders are validated integers, so the interpolation is safe. The
        // task_id guard keeps the write scoped to this task's subtasks.
        $cases = implode(' ', array_map(
            static fn (array $s): string => sprintf('WHEN %d THEN %d', $s['id'], $s['order']),
            $validated['subtasks'],
        ));

        DB::transaction(function () use ($ids, $cases, $task): void {
            Subtask::whereIn('id', $ids)
                ->where('task_id', $task->id)
                ->update(['order' => DB::raw("CASE id {$cases} END")]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Subtasks reordered successfully.',
        ]);
    }
}
