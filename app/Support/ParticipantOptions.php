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
     * Every ethnic group a Malaysian entrant is likely to name, with a free
     * "Other" so nobody is forced into a box that does not fit.
     *
     * Flat on purpose: callers do RACES[$key] and Rule::in(array_keys(RACES)),
     * so the grouping a dropdown needs lives beside it in RACE_GROUPS rather
     * than being nested in here.
     *
     * The old umbrella buckets are in RACES_LEGACY, not here. They became
     * redundant the moment the groups under them were listed, so they are no
     * longer offered — but entries already carrying one stay valid and
     * readable, see RACES_LEGACY.
     */
    public const RACES = [
        // Utama
        'malay' => 'Malay',
        'chinese' => 'Chinese',
        'indian' => 'Indian',

        // Sarawak
        'iban' => 'Iban',
        'bidayuh' => 'Bidayuh',
        'melanau' => 'Melanau',
        'melayu-sarawak' => 'Melayu Sarawak',
        'kedayan' => 'Kedayan',
        'bisaya' => 'Bisaya',

        // Orang Ulu, Sarawak
        'kayan' => 'Kayan',
        'kenyah' => 'Kenyah',
        'kelabit' => 'Kelabit',
        'lun-bawang' => 'Lun Bawang',
        'penan' => 'Penan',
        'punan' => 'Punan',
        'berawan' => 'Berawan',
        'sekapan' => 'Sekapan',
        'kejaman' => 'Kejaman',
        'lahanan' => 'Lahanan',
        'tanjong' => 'Tanjong',
        'kanowit' => 'Kanowit',
        'saban' => 'Saban',
        'sian' => 'Sian',
        'ukit' => 'Ukit',
        'bukitan' => 'Bukitan',
        'tatau' => 'Tatau',
        'vaie' => 'Vaie',
        'narom' => 'Narom',
        'miriek' => 'Miriek',
        'lisum' => 'Lisum',
        'lugat' => 'Lugat',
        'sihan' => 'Sihan',
        'baketan' => 'Baketan',

        // Sabah
        'kadazan' => 'Kadazan',
        'dusun' => 'Dusun',
        'kadazandusun' => 'KadazanDusun',
        'murut' => 'Murut',
        'bajau' => 'Bajau',
        'rungus' => 'Rungus',
        'orang-sungai' => 'Orang Sungai',
        'lundayeh' => 'Lundayeh',
        'suluk' => 'Suluk',
        'iranun' => 'Iranun',
        'tidong' => 'Tidong',
        'kimaragang' => 'Kimaragang',
        'lotud' => 'Lotud',
        'tatana' => 'Tatana',
        'paitan' => 'Paitan',
        'rumanau' => 'Rumanau',
        'brunei' => 'Brunei',
        'ubian' => 'Ubian',
        'idahan' => 'Idahan',
        'bonggi' => 'Bonggi',
        'tombonuo' => 'Tombonuo',

        // Orang Asli, Negrito
        'kensiu' => 'Kensiu',
        'kintaq' => 'Kintaq',
        'lanoh' => 'Lanoh',
        'jahai' => 'Jahai',
        'mendriq' => 'Mendriq',
        'bateq' => 'Bateq',

        // Orang Asli, Senoi
        'temiar' => 'Temiar',
        'semai' => 'Semai',
        'semoq-beri' => 'Semoq Beri',
        'jah-hut' => 'Jah Hut',
        'mah-meri' => 'Mah Meri',
        'che-wong' => 'Che Wong',

        // Orang Asli, Melayu Asli
        'temuan' => 'Temuan',
        'semelai' => 'Semelai',
        'jakun' => 'Jakun',
        'orang-kanaq' => 'Orang Kanaq',
        'orang-kuala' => 'Orang Kuala',
        'orang-seletar' => 'Orang Seletar',

        // Lain-lain
        'siam' => 'Siam',
        'portugis' => 'Portugis (Kristang)',
        'peranakan' => 'Peranakan',
        'eurasian' => 'Eurasian',

        // Last, always.
        'other' => 'Other',
    ];

    /**
     * Retired keys that are still in the database.
     *
     * Production holds real entries against these two, so they are accepted at
     * validation on the admin screens and still resolve to a label. Dropping
     * them from the map would print a raw slug on screen and make those rows
     * impossible to save. They are never offered as a new choice: the groups
     * that replaced them are in RACES.
     */
    public const RACES_LEGACY = [
        'bumiputera-sabah' => 'Bumiputera Sabah',
        'bumiputera-sarawak' => 'Bumiputera Sarawak',
    ];

    /**
     * How the list is broken up in a dropdown. Group label => race keys.
     *
     * Rendering only. The keys are looked up in RACES for their labels, so a
     * label is written once and this stays a running order.
     */
    public const RACE_GROUPS = [
        'Utama' => ['malay', 'chinese', 'indian'],

        'Sarawak' => ['iban', 'bidayuh', 'melanau', 'melayu-sarawak', 'kedayan', 'bisaya'],

        'Orang Ulu (Sarawak)' => [
            'kayan', 'kenyah', 'kelabit', 'lun-bawang', 'penan', 'punan', 'berawan',
            'sekapan', 'kejaman', 'lahanan', 'tanjong', 'kanowit', 'saban', 'sian',
            'ukit', 'bukitan', 'tatau', 'vaie', 'narom', 'miriek', 'lisum', 'lugat',
            'sihan', 'baketan',
        ],

        'Sabah' => [
            'kadazan', 'dusun', 'kadazandusun', 'murut', 'bajau', 'rungus',
            'orang-sungai', 'lundayeh', 'suluk', 'iranun', 'tidong', 'kimaragang',
            'lotud', 'tatana', 'paitan', 'rumanau', 'brunei', 'ubian', 'idahan',
            'bonggi', 'tombonuo',
        ],

        'Orang Asli - Negrito' => ['kensiu', 'kintaq', 'lanoh', 'jahai', 'mendriq', 'bateq'],

        'Orang Asli - Senoi' => ['temiar', 'semai', 'semoq-beri', 'jah-hut', 'mah-meri', 'che-wong'],

        'Orang Asli - Melayu Asli' => [
            'temuan', 'semelai', 'jakun', 'orang-kanaq', 'orang-kuala', 'orang-seletar',
        ],

        'Lain-lain' => ['siam', 'portugis', 'peranakan', 'eurasian'],
    ];

    /**
     * The whole dropdown, ready to render: group label => [key => label].
     *
     * Anything RACE_GROUPS does not claim lands under the '' key, to be drawn as
     * a plain option with no heading. That is "Other", and it comes out last
     * because it is last in RACES.
     *
     * $current is the value the record already holds. When it is one of the
     * retired keys, that one option is added so the select can show it as
     * selected — a select without its own stored value renders blank and then
     * rewrites the row on save. Pass nothing on a create form and no retired
     * option appears.
     *
     * @return array<string, array<string, string>>
     */
    public static function raceGroups(?string $current = null): array
    {
        $groups = [];

        foreach (self::RACE_GROUPS as $group => $keys) {
            foreach ($keys as $key) {
                $groups[$group][$key] = self::RACES[$key];
            }
        }

        if ($current !== null && array_key_exists($current, self::RACES_LEGACY)) {
            $groups['Rekod Lama'] = [$current => self::RACES_LEGACY[$current]];
        }

        $loose = array_diff_key(self::RACES, array_flip(array_merge(...array_values(self::RACE_GROUPS))));

        if ($loose !== []) {
            $groups[''] = $loose;
        }

        return $groups;
    }

    /**
     * Everything a new choice may be, for Rule::in. The retired keys are not here.
     *
     * @return array<int, string>
     */
    public static function raceKeys(): array
    {
        return array_keys(self::RACES);
    }

    /**
     * The same, plus the retired keys, for the admin screens that have to be able
     * to re-save a row already carrying one.
     *
     * @return array<int, string>
     */
    public static function raceKeysWithLegacy(): array
    {
        return array_merge(array_keys(self::RACES), array_keys(self::RACES_LEGACY));
    }

    /**
     * The label for any stored key, current or retired. Null when it is neither,
     * so the caller decides what to show instead.
     */
    public static function raceLabel(?string $key): ?string
    {
        if ($key === null) {
            return null;
        }

        return self::RACES[$key] ?? self::RACES_LEGACY[$key] ?? null;
    }

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
