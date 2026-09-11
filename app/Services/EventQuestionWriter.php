<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Support\Facades\DB;

/**
 * Writes the question list submitted on the event form.
 *
 * Rows carrying an id are updated in place rather than replaced, so the answers
 * already given stay attached to the question they were given to. Rows the
 * organiser dropped are deleted, and the answers survive that: the foreign key
 * nulls rather than cascades, and each answer keeps its own copy of the wording.
 *
 * Simpler than ShopVariantWriter, which has to shuffle labels around a unique
 * index. There is no unique constraint on a question title, because two boxes
 * reading "I agree" is odd but not wrong, and refusing it would be this
 * application deciding how somebody words their own form.
 */
class EventQuestionWriter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows  from EventRequest::questionRows()
     */
    public function sync(Event $event, array $rows): void
    {
        DB::transaction(function () use ($event, $rows) {
            $keptIds = collect($rows)
                ->pluck('id')
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->all();

            /*
             | Whatever is left was removed from the form. The when() guard matters:
             | with an empty kept list the whereKeyNot is skipped so everything goes,
             | which is right when the organiser cleared the list.
             */
            $event->questions()
                ->when($keptIds !== [], fn ($query) => $query->whereKeyNot($keptIds))
                ->delete();

            $event->unsetRelation('questions');

            foreach ($rows as $position => $row) {
                $attributes = [
                    'title' => $row['title'],
                    'body' => filled($row['body'] ?? null) ? $row['body'] : null,
                    'is_required' => (bool) ($row['is_required'] ?? false),

                    // Display order follows the order of the form, so the list comes
                    // back as it was left without anything to drag.
                    'sort_order' => $position,
                ];

                $question = null;

                if (! empty($row['id'])) {
                    /*
                     | Scoped through the relation, so an id belonging to another
                     | event cannot be written even if validation were bypassed.
                     */
                    $question = $event->questions()->whereKey($row['id'])->first();
                }

                if ($question !== null) {
                    $question->fill($attributes)->save();
                } else {
                    $event->questions()->create($attributes);
                }
            }
        });

        // The relation was loaded before the write on an edit, so it is dropped to
        // stop a stale list rendering on the page that follows.
        $event->unsetRelation('questions');
    }
}
