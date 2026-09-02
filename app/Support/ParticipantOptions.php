<?php

namespace App\Support;

class ParticipantOptions
{
    public const ROLE_MANAGER = 'manager';
    public const ROLE_PLAYER = 'player';

    /**
     * Neutral role used when an event takes one person per registration.
     *
     * The platform runs games, courses and conferences alike, so somebody
     * attending a training session is a participant, not a player.
     */
    public const ROLE_PARTICIPANT = 'participant';

    public const ROLES = [
        self::ROLE_PARTICIPANT => 'Participant',
        self::ROLE_MANAGER => 'Manager',
        self::ROLE_PLAYER => 'Player',
    ];

    /**
     * Roles that exist on a squad registration.
     */
    public const TEAM_ROLES = [
        self::ROLE_MANAGER => 'Manager',
        self::ROLE_PLAYER => 'Player',
    ];

    /**
     * Shown when a manager also holds a playing place.
     *
     * Not a role of its own. It is the manager role with also_plays set, kept as a
     * label so nothing is tempted to store it in the role column, where it would
     * be missed by every query that looks for a manager or for a player.
     */
    public const LABEL_MANAGER_PLAYER = 'Manager & Player';

    /**
     * The positions the person registering a squad may choose for themselves.
     *
     * Key => [role, also_plays, label]. One map so the form, the request and the
     * writer agree, rather than the combination being reassembled at each.
     */
    public const POSITIONS = [
        'manager_player' => [self::ROLE_MANAGER, true, 'Manager and Player'],
        'manager_only' => [self::ROLE_MANAGER, false, 'Manager only'],
        'player_only' => [self::ROLE_PLAYER, false, 'Player only'],
    ];

    /**
     * Position key => label, for a select.
     *
     * @return array<string, string>
     */
    public static function positionLabels(): array
    {
        $labels = [];

        foreach (self::POSITIONS as $key => [$role, $alsoPlays, $label]) {
            $labels[$key] = $label;
        }

        return $labels;
    }

    /**
     * The position key a stored row corresponds to.
     *
     * Used to re-select the right option when a submission comes back with
     * errors, so the visitor is not silently reset to the default.
     */
    public static function positionKeyFor(?string $role, bool $alsoPlays): string
    {
        foreach (self::POSITIONS as $key => [$candidateRole, $candidatePlays, $label]) {
            if ($candidateRole === $role && $candidatePlays === $alsoPlays) {
                return $key;
            }
        }

        return 'manager_only';
    }

    /**
     * The only role an individual registration can carry.
     */
    public const INDIVIDUAL_ROLES = [
        self::ROLE_PARTICIPANT => 'Participant',
    ];

    public const GENDERS = [
        'male' => 'Male',
        'female' => 'Female',
    ];

    /**
     * Follows the categories used on Malaysian official forms, with a free
     * "Other" so nobody is forced into a box that does not fit.
     */
    public const RACES = [
        'malay' => 'Malay',
        'chinese' => 'Chinese',
        'indian' => 'Indian',
        'bumiputera-sabah' => 'Bumiputera Sabah',
        'bumiputera-sarawak' => 'Bumiputera Sarawak',
        'orang-asli' => 'Orang Asli',
        'other' => 'Other',
    ];

    public const STATES = [
        'Johor',
        'Kedah',
        'Kelantan',
        'Melaka',
        'Negeri Sembilan',
        'Pahang',
        'Perak',
        'Perlis',
        'Pulau Pinang',
        'Sabah',
        'Sarawak',
        'Selangor',
        'Terengganu',
        'W.P. Kuala Lumpur',
        'W.P. Labuan',
        'W.P. Putrajaya',
    ];

    /**
     * Kept short on purpose: this is a Malaysian event platform, so the
     * neighbours people actually register from are listed and the rest is
     * covered by "Other".
     */
    public const COUNTRIES = [
        'Malaysia',
        'Singapore',
        'Brunei',
        'Indonesia',
        'Thailand',
        'Philippines',
        'Vietnam',
        'Other',
    ];

    public static function isRole(?string $value): bool
    {
        return array_key_exists((string) $value, self::ROLES);
    }
}
