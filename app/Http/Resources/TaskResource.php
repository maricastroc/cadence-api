<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'column_id' => $this->column_id,
            // Guard with whenLoaded: on the active-board payload each task is
            // already nested under its column, so `column` is not eager-loaded
            // and reading $this->column->name here fired one SELECT per task
            // (N+1). The key is omitted unless the relation is explicitly loaded.
            'column_name' => $this->whenLoaded('column', fn () => $this->column->name),
            'uuid' => $this->uuid,
            'name' => $this->name,
            'description' => $this->description,
            'order' => $this->order,
            'due_date' => $this->due_date,
            'is_completed' => (bool) $this->is_completed,
            'subtasks' => SubtaskResource::collection($this->whenLoaded('subtasks')),
            'tags' => TagResource::collection($this->whenLoaded('tags')),
        ];
    }
}
