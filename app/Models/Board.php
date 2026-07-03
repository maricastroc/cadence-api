<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Traits\HasUuid;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

class Board extends Model
{
    use HasFactory, HasUuid;

    protected $fillable = ['name', 'is_active', 'user_id', 'uuid'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    #[\Override]
    public static function boot(): void
    {
        parent::boot();

        static::deleting(function (Board $board): void {
            $previousBoard = self::where('user_id', $board->user_id)
                ->where('id', '!=', $board->id)
                ->where('created_at', '<', $board->created_at)
                ->orderByDesc('created_at')
                ->first();

            if (! $previousBoard) {
                $previousBoard = self::where('user_id', $board->user_id)
                    ->where('id', '!=', $board->id)
                    ->where('created_at', '>', $board->created_at)
                    ->orderBy('created_at')
                    ->first();
            }

            if ($previousBoard) {
                $previousBoard->activate();
            }
        });
    }

    public static function createWithColumns(array $data, $userId): Board
    {
        return DB::transaction(function () use ($data, $userId) {
            $board = self::create([
                'name' => $data['name'],
                'user_id' => $userId,
                'is_active' => true,
            ]);

            if (! empty($data['columns'])) {
                foreach ($data['columns'] as $columnData) {
                    $board->columns()->create([
                        'name' => $columnData['name'],
                    ]);
                }
            }

            $board->deactivateOtherBoards();

            return $board;
        });
    }

    public function updateWithColumns(array $data): Board
    {
        return DB::transaction(function () use ($data): static {
            $this->update([
                'name' => $data['name'] ?? $this->name,
                'is_active' => true,
            ]);

            if (isset($data['columns'])) {
                $this->syncColumns($data['columns']);
            } else {
                $this->columns()->delete();
            }

            $this->deactivateOtherBoards();

            return $this->load('columns.tasks.subtasks');
        });
    }

    protected function syncColumns(array $columnsData): void
    {
        $existingColumnIds = $this->columns()->pluck('id')->toArray();
        $updatedColumnIds = [];

        // The incoming array order is the source of truth for column ordering,
        // so persist a 1-based `order` from each column's position. (No unique
        // index on `order`, so reassigning sequentially is collision-free.)
        foreach (array_values($columnsData) as $index => $columnData) {
            $order = $index + 1;

            if (isset($columnData['id'])) {
                $column = $this->columns()->find($columnData['id']);

                if ($column) {
                    $column->update(['name' => $columnData['name'], 'order' => $order]);
                    $updatedColumnIds[] = $column->id;
                }
            } else {
                $newColumn = $this->columns()->create([
                    'name' => $columnData['name'],
                    'order' => $order,
                ]);
                $updatedColumnIds[] = $newColumn->id;
            }
        }

        $columnsToDelete = array_diff($existingColumnIds, $updatedColumnIds);

        if ($columnsToDelete !== []) {
            $this->columns()->whereIn('id', $columnsToDelete)->delete();
        }
    }

    public static function getActiveBoard(int $userId): ?self
    {
        return self::where('user_id', $userId)
            ->where('is_active', true)
            ->with(['columns.tasks.subtasks', 'columns.tasks.tags', 'user'])
            ->first();
    }

    public function activate(): void
    {
        $this->update(['is_active' => true]);
        $this->deactivateOtherBoards();
    }

    public function deactivateOtherBoards(): void
    {
        self::where('user_id', $this->user_id)
            ->where('id', '!=', $this->id)
            ->update(['is_active' => false]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function columns(): HasMany
    {
        // Eager-loading is left to the call site (getActiveBoard / setActive /
        // updateWithColumns load the task tree explicitly). Baking `tasks.subtasks`
        // in here made every columns() touch — pluck('id'), find(), reorder — drag
        // the whole task+subtask tree along for nothing.
        return $this->hasMany(Column::class)->orderBy('order');
    }
}
