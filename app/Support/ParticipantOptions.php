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
