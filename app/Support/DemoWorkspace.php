<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Board;
use App\Models\Column;
use App\Models\Subtask;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Owns the shared "try it" demo account and its sample workspace.
 *
 * The app is fully server-side per authenticated user, so a demo needs a real
 * user with real rows. We keep a single shared account and rebuild its boards
 * from scratch on every entry — that guarantees each visitor lands on a clean,
 * populated workspace and that nothing a previous visitor did sticks around.
 */
final class DemoWorkspace
{
    public const EMAIL = 'demo@cadence.app';

    public const NAME = 'Demo User';

    /**
     * Resolve the demo user (creating it on first use) and reseed its boards.
     */
    public static function provision(): User
    {
        $user = User::firstOrCreate(
            ['email' => self::EMAIL],
            // No one logs in with this password — entry is through demoLogin —
            // so it just needs to be unguessable.
            ['name' => self::NAME, 'password' => Str::random(40)],
        );

        self::reset($user);

        return $user;
    }

    /**
     * Wipe the user's workspace and rebuild it from the blueprint.
     */
    public static function reset(User $user): void
    {
        DB::transaction(function () use ($user): void {
            // Mass deletes skip Eloquent events (so Board's "activate a sibling
            // on delete" hook never fires) and lean on the DB foreign keys to
            // cascade columns -> tasks -> subtasks -> task_tag for us.
            Board::query()->where('user_id', $user->id)->delete();
            Tag::query()->where('user_id', $user->id)->delete();

            $blueprint = self::blueprint();
            $now = now()->toDateTimeString();

            // Rebuild the whole workspace with a handful of bulk inserts instead
            // of a create() per row. The old row-by-row approach fired hundreds
            // of round-trips (plus a `SELECT max(order)` per column/task/subtask
            // from the models' ordering hooks), which blew PHP's 30s limit over
            // a high-latency DB link. Here every level is one INSERT; we generate
            // uuids/orders up front and re-read the ids to wire up children.

            // The frontend resolves a tag's colour by *name* (see getTagHex), so
            // the stored colour must be a palette name, not a hex value.
            $tagRows = [];
            foreach ($blueprint['tags'] as $name => $colorName) {
                $tagRows[] = [
                    'name' => $name,
                    'color' => $colorName,
                    'user_id' => $user->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
            if ($tagRows !== []) {
                Tag::insert($tagRows);
            }
            $tagIds = Tag::query()->where('user_id', $user->id)->pluck('id', 'name');

            // Build every child row in memory first, tagging each with a uuid and
            // its parent's uuid so we can resolve foreign keys after each insert.
            $columnRows = [];
            $taskRows = [];
            $subtaskRows = [];
            $pivotPlan = []; // taskUuid => [tagName, ...]

            foreach ($blueprint['boards'] as $boardData) {
                // Boards are few, and `is_active` lives on a string column behind
                // a boolean cast — let Eloquent handle that one; the volume (and
                // the old N+1) is all in the columns/tasks/subtasks below.
                $board = Board::create([
                    'name' => $boardData['name'],
                    'user_id' => $user->id,
                    'is_active' => $boardData['active'],
                ]);

                $columnOrder = 0;
                foreach ($boardData['columns'] as $columnData) {
                    $columnUuid = (string) Str::uuid();
                    $columnRows[] = [
                        'name' => $columnData['name'],
                        'board_id' => $board->id,
                        'uuid' => $columnUuid,
                        'order' => ++$columnOrder,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];

                    $taskOrder = 0;
                    foreach ($columnData['tasks'] ?? [] as $taskData) {
                        $taskUuid = (string) Str::uuid();
                        $taskRows[] = [
                            'name' => $taskData['name'],
                            'description' => $taskData['description'] ?? null,
                            'status' => null,
                            'is_completed' => $taskData['completed'] ?? false,
                            'due_date' => isset($taskData['due'])
                                ? now()->addDays($taskData['due'])->toDateTimeString()
                                : null,
                            'order' => ++$taskOrder,
                            'uuid' => $taskUuid,
                            'created_at' => $now,
                            'updated_at' => $now,
                            '_column_uuid' => $columnUuid,
                        ];

                        $subtaskOrder = 0;
                        foreach ($taskData['subtasks'] ?? [] as [$subtaskName, $isCompleted]) {
                            $subtaskRows[] = [
                                'name' => $subtaskName,
                                'is_completed' => $isCompleted,
                                'order' => ++$subtaskOrder,
                                'uuid' => (string) Str::uuid(),
                                'created_at' => $now,
                                'updated_at' => $now,
                                '_task_uuid' => $taskUuid,
                            ];
                        }

                        if (! empty($taskData['tags'])) {
                            $pivotPlan[$taskUuid] = $taskData['tags'];
                        }
                    }
                }
            }

            // Columns -> read back their ids by uuid.
            if ($columnRows !== []) {
                Column::insert($columnRows);
            }
            $columnIdByUuid = Column::query()
                ->whereIn('uuid', array_column($columnRows, 'uuid'))
                ->pluck('id', 'uuid');

            // Tasks -> resolve column_id from the map, insert, read back ids.
            foreach ($taskRows as &$taskRow) {
                $taskRow['column_id'] = $columnIdByUuid[$taskRow['_column_uuid']];
                unset($taskRow['_column_uuid']);
            }
            unset($taskRow);
            if ($taskRows !== []) {
                Task::insert($taskRows);
            }
            $taskIdByUuid = Task::query()
                ->whereIn('uuid', array_column($taskRows, 'uuid'))
                ->pluck('id', 'uuid');

            // Subtasks -> resolve task_id, insert.
            foreach ($subtaskRows as &$subtaskRow) {
                $subtaskRow['task_id'] = $taskIdByUuid[$subtaskRow['_task_uuid']];
                unset($subtaskRow['_task_uuid']);
            }
            unset($subtaskRow);
            if ($subtaskRows !== []) {
                Subtask::insert($subtaskRows);
            }

            // task_tag pivot -> resolve both ids, insert.
            $pivotRows = [];
            foreach ($pivotPlan as $taskUuid => $tagNames) {
                foreach ($tagNames as $tagName) {
                    $pivotRows[] = [
                        'task_id' => $taskIdByUuid[$taskUuid],
                        'tag_id' => $tagIds[$tagName],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
            if ($pivotRows !== []) {
                DB::table('task_tag')->insert($pivotRows);
            }
        });
    }

    /**
     * The sample data. Kept intentionally rich so the demo shows off the app:
     * several full columns, a healthy mix of overdue / due-soon / future / done
     * dates, partially completed subtasks and colour-coded tags.
     *
     * `due` is a day offset from now (negative = overdue); omit it for no date.
     * `completed` marks the whole task done. Each subtask is a [name, isDone]
     * pair. Tag colours are palette *names* (resolved by the frontend's
     * getTagHex), not hex values — the palette has 8 colours, so 8 tags max.
     *
     * @return array{tags: array<string, string>, boards: array<int, array<string, mixed>>}
     */
    private static function blueprint(): array
    {
        return [
            'tags' => [
                'Design' => 'Aqua Blue',
                'Feature' => 'Lavender',
                'Bug' => 'Vivid Red',
                'Research' => 'Mint Green',
                'DevOps' => 'Golden Yellow',
                'Docs' => 'Blue',
                'Frontend' => 'Soft Pink',
                'Backend' => 'Rose Red',
            ],
            'boards' => [
                [
                    'name' => 'Platform Launch',
                    'active' => true,
                    'columns' => [
                        [
                            'name' => 'Backlog',
                            'tasks' => [
                                [
                                    'name' => 'Research transactional email providers',
                                    'description' => 'Compare Resend, Postmark and Brevo for deliverability and price.',
                                    'due' => -3,
                                    'tags' => ['Research', 'Backend'],
                                ],
                                [
                                    'name' => 'Explore an activity log',
                                    'description' => 'A per-board feed of recent changes.',
                                    'due' => 25,
                                    'tags' => ['Feature'],
                                ],
                                [
                                    'name' => 'Evaluate a mobile app shell',
                                    'due' => 30,
                                    'tags' => ['Research', 'Frontend'],
                                ],
                                [
                                    'name' => 'Add board templates',
                                    'description' => 'Let users spin up a board from a preset (sprint, content calendar…).',
                                    'due' => 18,
                                    'tags' => ['Feature'],
                                    'subtasks' => [
                                        ['Define preset format', false],
                                        ['Template picker UI', false],
                                    ],
                                ],
                                [
                                    'name' => 'Investigate offline support',
                                    'due' => 40,
                                    'tags' => ['Research'],
                                ],
                            ],
                        ],
                        [
                            'name' => 'Todo',
                            'tasks' => [
                                [
                                    'name' => 'Design the settings panel',
                                    'description' => 'Account, theme and notification preferences in one place.',
                                    'due' => 6,
                                    'tags' => ['Design', 'Feature'],
                                    'subtasks' => [
                                        ['Audit the current settings', true],
                                        ['Wireframe the layout', true],
                                        ['Hi-fi mockups', false],
                                        ['Dark mode pass', false],
                                    ],
                                ],
                                [
                                    'name' => 'Add board search to the sidebar',
                                    'description' => 'Filter boards by name as the list grows.',
                                    'due' => 12,
                                    'tags' => ['Feature', 'Frontend'],
                                    'subtasks' => [
                                        ['Debounced input', false],
                                        ['Empty state', false],
                                    ],
                                ],
                                [
                                    'name' => 'Build the tag manager',
                                    'due' => 9,
                                    'tags' => ['Feature', 'Frontend'],
                                    'subtasks' => [
                                        ['Create / edit / delete', false],
                                        ['Per-board usage counts', false],
                                    ],
                                ],
                                [
                                    'name' => 'Add keyboard shortcuts',
                                    'description' => 'Search focus, quick-add task, close dialogs.',
                                    'due' => 14,
                                    'tags' => ['Feature'],
                                ],
                                [
                                    'name' => 'Create empty-state illustrations',
                                    'due' => 7,
                                    'tags' => ['Design'],
                                ],
                            ],
                        ],
                        [
                            'name' => 'In Progress',
                            'tasks' => [
                                [
                                    'name' => 'Build the onboarding flow',
                                    'description' => 'First-run experience backed by a seeded sample board.',
                                    'due' => 1,
                                    'tags' => ['Feature'],
                                    'subtasks' => [
                                        ['Welcome screen', true],
                                        ['Seed sample data', true],
                                        ['Guided tooltip tour', false],
                                    ],
                                ],
                                [
                                    'name' => 'Fix drag-and-drop offset on Safari',
                                    'description' => 'Cards land one slot below the drop target.',
                                    'due' => 0,
                                    'tags' => ['Bug', 'Frontend'],
                                    'subtasks' => [
                                        ['Reproduce on Safari', true],
                                        ['Patch sensor activation', false],
                                    ],
                                ],
                                [
                                    'name' => 'Implement column reordering',
                                    'due' => 3,
                                    'tags' => ['Feature', 'Frontend'],
                                    'subtasks' => [
                                        ['Horizontal sortable context', true],
                                        ['Persist new order', false],
                                    ],
                                ],
                                [
                                    'name' => 'Wire up the REST API for tasks',
                                    'due' => 5,
                                    'tags' => ['Backend', 'Feature'],
                                    'subtasks' => [
                                        ['CRUD endpoints', true],
                                        ['Reorder + move', false],
                                    ],
                                ],
                                [
                                    'name' => 'Add optimistic updates',
                                    'description' => 'Update the UI immediately and reconcile with the server.',
                                    'due' => 2,
                                    'tags' => ['Frontend'],
                                ],
                            ],
                        ],
                        [
                            'name' => 'In Review',
                            'tasks' => [
                                [
                                    'name' => 'Add real-time updates with WebSocket',
                                    'description' => 'Live board sync so collaborators see moves instantly.',
                                    'due' => 4,
                                    'tags' => ['Feature', 'Backend'],
                                    'subtasks' => [
                                        ['Socket server', true],
                                        ['Reconnect logic', false],
                                    ],
                                ],
                                [
                                    'name' => 'Review the auth middleware',
                                    'due' => 2,
                                    'tags' => ['Backend', 'Bug'],
                                ],
                                [
                                    'name' => 'Polish the loading states',
                                    'due' => 6,
                                    'tags' => ['Design', 'Frontend'],
                                ],
                                [
                                    'name' => 'Audit accessibility',
                                    'description' => 'Focus order, ARIA labels and colour contrast.',
                                    'due' => 8,
                                    'tags' => ['Research', 'Frontend'],
                                    'subtasks' => [
                                        ['Keyboard navigation', true],
                                        ['Contrast pass', false],
                                    ],
                                ],
                                [
                                    'name' => 'Code review: boards context',
                                    'due' => 1,
                                    'tags' => ['Frontend'],
                                ],
                            ],
                        ],
                        [
                            'name' => 'Done',
                            'tasks' => [
                                [
                                    'name' => 'Migrate auth to httpOnly cookies',
                                    'description' => 'Move the Sanctum token out of localStorage to mitigate XSS.',
                                    'due' => -10,
                                    'completed' => true,
                                    'tags' => ['Feature', 'Backend'],
                                    'subtasks' => [
                                        ['Set the cookie on login', true],
                                        ['Promote cookie to a Bearer header', true],
                                        ['Drop the localStorage token', true],
                                    ],
                                ],
                                [
                                    'name' => 'Set up the CI pipeline',
                                    'description' => 'Lint, test and build on every pull request.',
                                    'due' => -5,
                                    'completed' => true,
                                    'tags' => ['DevOps'],
                                    'subtasks' => [
                                        ['Lint step', true],
                                        ['Test step', true],
                                        ['Build step', true],
                                    ],
                                ],
                                [
                                    'name' => 'Implement light / dark theme',
                                    'due' => -8,
                                    'completed' => true,
                                    'tags' => ['Design', 'Frontend'],
                                    'subtasks' => [
                                        ['Theme tokens', true],
                                        ['Persist preference', true],
                                    ],
                                ],
                                [
                                    'name' => 'Add the boards sidebar',
                                    'due' => -12,
                                    'completed' => true,
                                    'tags' => ['Frontend'],
                                ],
                                [
                                    'name' => 'Set up the database schema',
                                    'due' => -15,
                                    'completed' => true,
                                    'tags' => ['Backend', 'DevOps'],
                                    'subtasks' => [
                                        ['Boards / columns / tasks', true],
                                        ['Subtasks / tags', true],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    'name' => 'Marketing Site',
                    'active' => false,
                    'columns' => [
                        [
                            'name' => 'Ideas',
                            'tasks' => [
                                [
                                    'name' => 'Write the launch blog post',
                                    'due' => 20,
                                    'tags' => ['Docs'],
                                ],
                                [
                                    'name' => 'Record a 60s demo video',
                                    'description' => 'Walk through creating a board, a task and dragging it across columns.',
                                    'tags' => ['Design'],
                                ],
                                [
                                    'name' => 'Draft the pricing page copy',
                                    'due' => 22,
                                    'tags' => ['Docs'],
                                ],
                                [
                                    'name' => 'Plan a Product Hunt launch',
                                    'due' => 28,
                                    'tags' => ['Research'],
                                ],
                                [
                                    'name' => 'Collect early testimonials',
                                    'due' => 35,
                                    'tags' => ['Docs'],
                                ],
                            ],
                        ],
                        [
                            'name' => 'In Progress',
                            'tasks' => [
                                [
                                    'name' => 'Design the landing hero',
                                    'description' => 'Above-the-fold section with the tagline and a clear call to action.',
                                    'due' => 4,
                                    'tags' => ['Design'],
                                    'subtasks' => [
                                        ['Copy draft', true],
                                        ['Hero illustration', false],
                                    ],
                                ],
                                [
                                    'name' => 'Build the features section',
                                    'due' => 6,
                                    'tags' => ['Frontend'],
                                    'subtasks' => [
                                        ['Layout', true],
                                        ['Feature icons', false],
                                    ],
                                ],
                                [
                                    'name' => 'Set up analytics',
                                    'due' => 3,
                                    'tags' => ['DevOps'],
                                ],
                                [
                                    'name' => 'Write SEO meta tags',
                                    'due' => 5,
                                    'tags' => ['Docs', 'Frontend'],
                                ],
                                [
                                    'name' => 'Create social preview images',
                                    'due' => 7,
                                    'tags' => ['Design'],
                                ],
                            ],
                        ],
                        [
                            'name' => 'Shipped',
                            'tasks' => [
                                [
                                    'name' => 'Reserve the domain',
                                    'due' => -14,
                                    'completed' => true,
                                    'tags' => ['DevOps'],
                                    'subtasks' => [
                                        ['Buy the domain', true],
                                        ['Point the DNS', true],
                                    ],
                                ],
                                [
                                    'name' => 'Set up the landing repo',
                                    'due' => -16,
                                    'completed' => true,
                                    'tags' => ['DevOps', 'Frontend'],
                                ],
                                [
                                    'name' => 'Choose a font pairing',
                                    'due' => -18,
                                    'completed' => true,
                                    'tags' => ['Design'],
                                ],
                                [
                                    'name' => 'Wireframe the homepage',
                                    'due' => -20,
                                    'completed' => true,
                                    'tags' => ['Design'],
                                    'subtasks' => [
                                        ['Sketch sections', true],
                                        ['Low-fi in Figma', true],
                                    ],
                                ],
                                [
                                    'name' => 'Set up the newsletter',
                                    'due' => -12,
                                    'completed' => true,
                                    'tags' => ['Feature'],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }
}
