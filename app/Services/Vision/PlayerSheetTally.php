<?php

namespace App\Services\Vision;

/**
 * One player line inside a team block: the handle printed on the screen, and the
 * eliminations printed beside it.
 *
 * The handle is the only identity on that screen. There is no real name and no
 * account id, so it is all the matching has to work with.
 */
class PlayerSheetTally
{
    public function __construct(
        public readonly string $name,
        public readonly int $eliminations,
    ) {
    }
}
