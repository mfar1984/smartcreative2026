# Design — Sistem Tournament

## Ringkasan

Sistem ini menjalankan pertandingan atas pendaftaran event yang sudah ada, dan menerbitkan keputusannya ke laman awam.

Idea tengahnya satu sahaja: **empat keluarga pemarkahan yang berbeza semuanya berakhir sebagai satu senarai kedudukan.** Bracket menghasilkan pemenang yang mara. Battle royale menghasilkan mata terkumpul. Race menghasilkan masa. Judged menghasilkan markah hakim. Kesemuanya diringkaskan menjadi baris berperingkat, dan barisan berperingkat itulah yang laman awam papar.

Kerana itu sistem ini tidak dibina sebagai empat modul sukan. Ia dibina sebagai satu enjin dengan **profil pemarkahan sebagai data**. Menambah Pickleball atau Aerobic bermakna menambah satu baris dalam `point_rules`, bukan menambah kod.

Keputusan reka bentuk yang paling menentukan: `event_registrations` yang sudah ada ialah unit peserta. Untuk event pasukan ia satu skuad; untuk event individu ia seorang. Tiada jadual pasukan baharu.

---

## Lapisan

```
┌──────────────────────────────────────────────────────────────┐
│  SKRIN ADMIN                                                 │
│  Tournaments · Matches · Standings · Point Rules             │
│  Hall of Fame · Settings                                     │
└───────────────────────┬──────────────────────────────────────┘
                        │
┌───────────────────────▼──────────────────────────────────────┐
│  CONTROLLER                                                  │
│  TournamentController      MatchController                   │
│  StandingController        PointRuleController               │
│  HallOfFameController      TournamentSettingsController      │
└───────────────────────┬──────────────────────────────────────┘
                        │
┌───────────────────────▼──────────────────────────────────────┐
│  SUPPORT — di sini semua keputusan dibuat                    │
│                                                              │
│  ScoringEngine        komponen profil → mata                 │
│  StandingsCalculator  match → kedudukan + tie-break          │
│  DrawGenerator        entrant → match  (5 penjana)           │
│  StageAdvancer        peringkat siap → peringkat berikut     │
│  TournamentProgress   langkah mana, apa yang menghalang      │
│  EntrantImporter      pendaftaran → entrant                  │
└───────────────────────┬──────────────────────────────────────┘
                        │
┌───────────────────────▼──────────────────────────────────────┐
│  MODEL                                                       │
│  Tournament · TournamentEntrant · TournamentStage            │
│  TournamentGroup · TournamentMatch · TournamentMatchEntrant  │
│  TournamentStanding · TournamentChampion · PointRule         │
│                                                              │
│  guna semula: Event · EventRegistration · EventParticipant   │
└──────────────────────────────────────────────────────────────┘
```

Semua logik pemarkahan duduk dalam `app/Support`, bukan dalam controller dan bukan dalam model. Sebabnya: ia perlu diuji tanpa HTTP, dan ia dipanggil dari tiga tempat berbeza (simpan markah, betulkan markah, kira semula selepas profil diedit).

---

## Model data

### `point_rules`

```
id
name              string      "PMPL / PMGC Official"
kind              string      battle_royale | bracket | race | judged
squad_size        smallint    null untuk sukan tanpa skuad        (D2)
components        json        senarai komponen mata
inputs            json        medan yang borang markah minta
tiebreak          json        senarai kunci komponen, ikut keutamaan
is_active         boolean
created_by        fk users
timestamps
```

`components` — setiap komponen satu objek:

```json
[
  { "key": "placement", "label": "Placement", "type": "table",
    "source": "placement",
    "values": {"1":10,"2":6,"3":5,"4":4,"5":3,"6":2,"7":1,"8":1} },

  { "key": "kills", "label": "Kills", "type": "per_unit",
    "source": "kills", "value": 1 },

  { "key": "wwcd", "label": "WWCD", "type": "bonus",
    "when": {"source":"placement","equals":1}, "value": 0 },

  { "key": "squad_penalty", "label": "Squad Penalty", "type": "penalty_table",
    "source": "players_present",
    "values": {"4":0,"3":-1,"2":-2,"1":-3}, "disqualify_at": 0 }
]
```

Lima jenis, dan lima ini sahaja menampung sebelas sukan:

| type | Cara ia mengira | Contoh |
|------|-----------------|--------|
| `table` | `values[input]` atau 0 | tempat 3 → 5 mata |
| `per_unit` | `input × value` | 8 kill × 1 → 8 mata |
| `bonus` | syarat benar → `value`, dan `count` naik 1 | WWCD 0 mata tapi dikira untuk tie-break |
| `penalty_table` | `values[input]`, negatif | 3 pemain → −1 |
| `aggregate` | himpun markah hakim | 5 hakim, buang tertinggi dan terendah, purata |

`inputs` — medan yang borang markah papar, dan cara ia disahkan:

```json
[
  { "key":"placement", "label":"Placement", "type":"integer",
    "min":1, "required":true, "unique_in_match":true },
  { "key":"kills", "label":"Kills", "type":"integer",
    "min":0, "required":true },
  { "key":"players_present", "label":"Players", "type":"integer",
    "min":0, "max_from":"squad_size", "required":true }
]
```

Ini yang membolehkan satu borang untuk semua sukan. Borang membaca `inputs` dan melukis medan; tiada `if ($sport === 'pubg')` di mana-mana.

`tiebreak` — `["wwcd","placement","kills"]`.

### `tournaments`

```
id
event_id          fk events
name              string      "Main Event" | "Ladies Division"
format            string      single_elim | group_single_elim | double_elim
                              battle_royale | race | judged
point_rule_id     fk point_rules
status            string      setup | ongoing | completed | published
settings          json        salinan Tournament Settings masa dicipta
seeding_method    string      manual | random | registration      (D10)
draw_generated_at timestamp   null
created_by        fk users
timestamps

index (event_id, status)
index (status)
```

Berbilang baris per `event_id` dibenarkan. Itulah D5, dan itulah yang membolehkan Main Event dan Ladies Division hidup serentak atas event yang sama.

`settings` disalin masa cipta, bukan dirujuk. Sebabnya sama seperti campaign menyalin badan mesej: menukar buffer lalai bulan depan tidak boleh mengubah kejohanan yang sedang berjalan.

### `tournament_entrants`

```
id
tournament_id     fk
event_registration_id  fk event_registrations
seed              smallint    null sebelum seeding
status            string      active | eliminated | disqualified | withdrawn
added_by_hand     boolean     benar bila operator pilih sendiri walaupun belum bayar
reason            string      null, sebab DQ atau tarik diri
timestamps

unique (tournament_id, event_registration_id)
unique (tournament_id, seed)
```

`unique (tournament_id, event_registration_id)` menguatkuasakan R4.5 pada peringkat pangkalan data, bukan hanya pada peringkat kod.

### `tournament_stages`

```
id
tournament_id     fk
name              string      "Group Stage" | "Playoff" | "Grand Final"
type              string      group | bracket | lobby | heat
sequence          smallint    1, 2, 3
advance_count     smallint    berapa mara dari setiap group
match_count       smallint    untuk lobby, berapa match per lobby
best_of           json        {"1":1,"2":3,"3":5} round → BO
status            string      pending | ongoing | completed
timestamps

unique (tournament_id, sequence)
```

Beberapa peringkat berjenis berbeza dalam satu kejohanan. Itulah cara Single Elimination sampai Top 4 kemudian Double Elimination untuk playoff berfungsi, dan itulah nasihat anda sendiri yang saya ikut.

### `tournament_groups`

```
id
tournament_stage_id  fk
name                 string    "Group A" | "Lobby 1"
timestamps
```

### `tournament_matches`

```
id
tournament_id        fk        didenormalkan supaya query kedudukan tidak perlu join
tournament_stage_id  fk
tournament_group_id  fk        null untuk bracket
round                smallint  null untuk lobby
position             smallint  kedudukan dalam round
bracket_side         string    upper | lower | final | null
best_of              smallint  1 | 3 | 5
map                  string    null
scheduled_at         datetime  null
status               string    scheduled | awaiting_result | completed | walkover
winner_entrant_id    fk        null, hanya untuk bracket
resolution           string    null | walkover | forfeit
reason               string    null
scored_by            fk users  null
scored_at            timestamp null
timestamps

index (tournament_id, status)
index (tournament_stage_id, round, position)
```

`tournament_id` didenormalkan dengan sengaja. Setiap kiraan kedudukan menapis ikut kejohanan, dan tanpa lajur ini setiap satu perlu join dua peringkat naik ke `tournaments`.

### `tournament_match_entrants`

```
id
tournament_match_id  fk
tournament_entrant_id fk
inputs               json      apa yang operator taip
points               decimal   DIKIRA daripada inputs, tidak pernah ditaip
is_disqualified      boolean   dari penalty_table disqualify_at
component_points     json      pecahan per komponen, untuk paparan dan tie-break
timestamps

unique (tournament_match_id, tournament_entrant_id)
```

`inputs` sebagai JSON, bukan lajur tetap untuk placement, kills, sets_won, finish_time dan judge_scores. Sebabnya: menambah sukan tidak boleh bermakna menambah migration. Sebelas sukan dengan lajur tetap bermakna dua belas lajur yang sembilan daripadanya sentiasa null.

`component_points` menyimpan pecahan supaya jadual kedudukan boleh papar lajur Kill, Place, WWCD dan Pen tanpa mengira semula setiap kali halaman dibuka.

### `tournament_proofs`

```
id
tournament_match_id  fk
path                 string
uploaded_by          fk users
timestamps
```

### `tournament_standings`

```
id
tournament_id        fk
tournament_stage_id  fk
tournament_group_id  fk        null
tournament_entrant_id fk
played               smallint
won                  smallint
lost                 smallint
component_totals     json      {"placement":18,"kills":22,"wwcd":1,"squad_penalty":-2}
total_points         decimal
rank                 smallint
is_disqualified      boolean
advances             boolean    dalam had advance_count
timestamps

unique (tournament_stage_id, tournament_group_id, tournament_entrant_id)
index (tournament_id, rank)
```

Jadual ini **sentiasa dikira semula daripada match, tidak pernah ditambah**. Ini datang daripada pepijat sebenar dalam modul Campaign: kaunter yang ditambah kehilangan tulisan bila dua proses berjalan serentak. Mengira lebih berat tetapi tidak boleh hanyut.

### `tournament_champions`

```
id
tournament_id        fk
tournament_entrant_id fk
rank                 smallint  1 | 2 | 3
display_name         string    DISALIN dari team_name
total_points         decimal   DISALIN
component_totals     json      DISALIN
published_at         timestamp
published_by         fk users
timestamps

unique (tournament_id, rank)
```

Setiap medan yang bermakna **disalin, bukan dirujuk**. Ini juga datang daripada pepijat Campaign: rekod apa yang berlaku mesti tidak berubah bila sumbernya diedit kemudian. Membetulkan kill satu match tiga bulan selepas hadiah diberi tidak boleh menukar juara yang sudah diumumkan.

---

## ScoringEngine

Satu kelas, satu tanggungjawab: input mentah masuk, mata keluar.

```php
final class ScoringEngine
{
    /**
     * @param  array<string, mixed>  $inputs   apa yang operator taip
     * @return array{points: float, components: array<string, float>,
     *               counts: array<string, int>, disqualified: bool}
     */
    public function score(PointRule $rule, array $inputs): array
}
```

Setiap jenis komponen dikendalikan oleh satu kaedah kecil. `counts` diasingkan daripada `components` kerana `bonus` boleh bernilai sifar mata tetapi masih perlu dikira untuk tie-break — itulah tepatnya WWCD dalam format PMPL.

Untuk `race`, enjin menerima masa dan menerbitkan placement sebelum menilai komponen `table`. Untuk `judged`, ia menghimpun markah hakim mengikut kaedah yang dinamakan dalam komponen `aggregate`.

Enjin ini tidak tahu apa itu PUBG. Ia hanya tahu lima jenis komponen.

---

## DrawGenerator

Antara muka yang sama, lima pelaksanaan.

```php
interface DrawGenerator
{
    /** @param Collection<TournamentEntrant> $entrants */
    public function generate(TournamentStage $stage, Collection $entrants): void;
}
```

### SingleEliminationGenerator

Saiz bracket = kuasa dua terkecil ≥ bilangan entrant. Bye diberi kepada seed tertinggi.

```
11 entrant → bracket 16 → 5 bye kepada seed 1-5

Pasangan round 1 dibina dengan corak seed standard:
  1 v 16    8 v 9     4 v 13    5 v 12
  2 v 15    7 v 10    3 v 14    6 v 11

Slot 12-16 kosong, jadi seed 1-5 mara tanpa bermain.
```

Corak itu memastikan seed 1 dan seed 2 hanya boleh berjumpa di final.

### GroupStageGenerator

Round robin dalam setiap kumpulan. Entrant dibahagi ikut seed secara ular supaya kumpulan seimbang:

```
8 entrant, 2 kumpulan:
  Group A:  seed 1, 4, 5, 8
  Group B:  seed 2, 3, 6, 7

Setiap kumpulan: 6 perlawanan, setiap entrant main 3 kali.
```

### Pasangan silang selepas group stage

```
A1 v B2      bukan A1 v A2
B1 v A2

Entrant dari kumpulan sama tidak berjumpa di separuh akhir.
```

Untuk empat kumpulan: A1vB2, C1vD2, B1vA2, D1vC2.

### DoubleEliminationGenerator

Upper bracket, lower bracket, grand final. Yang kalah di upper turun ke lower. Kalah dua kali tersingkir.

Digunakan sebagai **peringkat kedua** bagi Top 4, bukan dari round satu. Itu nasihat anda dan sistem menguatkuasakannya: penjana ini menolak lebih daripada lapan entrant dan mencadangkan Single Elimination dahulu.

### LobbyGenerator

Battle royale. Entrant dibahagi kepada lobi bersaiz maksimum 16, ikut seed secara ular. Setiap lobi mendapat `match_count` perlawanan, dan peta diberikan daripada rotasi dalam settings.

```
32 entrant → Lobby 1 (16) + Lobby 2 (16)
3 match setiap lobi
Peta: Erangel, Miramar, Erangel
```

### HeatGenerator

Race dan judged. Satu match per heat, memegang setiap entrant dalam heat itu. Untuk perlumbaan biasa, satu heat sahaja dan satu match.

---

## StandingsCalculator

```php
public function recalculate(Tournament $tournament): void
```

Satu aliran, dalam satu transaksi:

```
1  Baca setiap tournament_match_entrants bagi kejohanan itu
   yang matchnya berstatus completed atau walkover

2  Kumpul ikut stage, group, entrant
   Jumlahkan component_points, dan counts untuk komponen bonus

3  total_points = jumlah semua komponen, penalti sudah negatif
   Penalti dikira setiap match yang skuadnya kurang         (D1)

4  Susun: total_points menurun, kemudian setiap kunci tiebreak
   mengikut urutan dalam profil

5  Tandakan entrant yang seri sebenar selepas semua tiebreak habis

6  Tandakan advances = benar untuk advance_count teratas

7  Tulis semula tournament_standings dengan upsert
```

Langkah 4 memanggil profil, bukan senarai tertanam. Untuk PMPL urutannya WWCD, placement, kill. Untuk perlumbaan basikal ia `finish_time`. Untuk aerobic ia markah hakim kemudian tolakan.

Entrant DQ kekal dalam jadual bertanda DQ, tidak dibuang (D3, R8.8).

---

## TournamentProgress

Ini yang menjawab keperluan aliran anda. Satu kelas menjawab satu soalan: langkah mana, dan apa yang menghalang langkah berikutnya.

```php
final class TournamentProgress
{
    /** @return array<int, array{label: string, done: bool, current: bool,
     *                           blocker: string|null}> */
    public function steps(Tournament $tournament): array
}
```

Tujuh langkah:

```
┌─────────────────────────────────────────────────────────────────┐
│  ✓  1  Kejohanan dicipta       event, format, profil mata       │
│  ✓  2  Peserta dimasukkan      12 entrant                       │
│  ✓  3  Seed disusun            kaedah manual                    │
│  ▸  4  Undian dijana           ← ANDA DI SINI                   │
│        Menghalang: belum dijana. Tekan Generate Draw.           │
│     5  Markah dimasukkan       0 daripada 15 match              │
│     6  Peringkat tamat         Group Stage, Playoff             │
│     7  Podium diterbitkan      perlu Publish                    │
└─────────────────────────────────────────────────────────────────┘
```

Setiap langkah yang belum boleh dimulakan membawa `blocker` bertulis. Skrin memaparkannya sebagai teks, bukan menyembunyikan butang tanpa penjelasan (R3.3).

---

## Mesin keadaan

### Kejohanan

```
   setup ──── undian dijana ────► ongoing
     ▲                              │
     │                              │ setiap match selesai
  discard                           ▼
  draw                          completed
                                    │
                                    │ Publish
                                    ▼
                                published
                                    │
                                    │ Unpublish
                                    ▼
                                completed
```

`setup` boleh diedit sepenuhnya. `ongoing` menolak perubahan entrant dan seed. `published` menolak pembetulan markah sampai ditarik semula (R10.5).

### Perlawanan

```
  scheduled ──► awaiting_result ──► completed
      │                                 ▲
      └──────── walkover ───────────────┘
```

`awaiting_result` bermaksud sudah dimainkan tetapi markah belum masuk. Itulah tab yang pengadil pandang pada hari kejohanan.

---

## Skrin

Enam skrin sidebar sudah dibina sebagai shell. Reka bentuk ini mengisinya.

### Tournaments

Tab `Setup · Ongoing · Completed · Published` daripada `status`.

Jadual: nama, event, format, bilangan entrant, peringkat semasa. Butang `New Tournament` di bawah `tournaments.create`.

Halaman detail membawa tab sendiri: `Progress · Entrants · Stages · Settings`. Tab Progress memapar tujuh langkah di atas.

### Matches

Tab `Scheduled · Awaiting Result · Completed`.

Filter bar wajib memilih kejohanan, kerana R1.7 menuntut operator sentiasa tahu kejohanan mana yang dipandang. Filter tambahan: peringkat, kumpulan.

Setiap baris membawa satu butang `Enter Score` di bawah `tournaments.matches.score`.

### Borang markah

Dijana daripada `inputs` profil. Satu Blade, satu gelung.

```
PUBG                            BADMINTON            RUN
placement, kills, players       skor setiap set      masa setiap peserta
16 baris                        2 baris              semua peserta

AEROBIC
markah hakim 1-5, tolakan
1 baris per peserta
```

Mata yang dikira dipapar sebelum disimpan (R7.7), supaya operator boleh membandingkan dengan skrin permainan. Boleh dikendalikan dengan papan kekunci sahaja, fokus bergerak menurun mengikut senarai entrant (R7.13).

### Standings

Tab ialah peringkat kejohanan yang dipilih. Tiada tab bar bila tiada peringkat.

Lajur dibina daripada `components` profil. PUBG memapar Kill, Place, WWCD, Pen. Badminton memapar Sets. Aerobic memapar markah hakim.

Garis pemisah ditanda pada had `advance_count`. Eksport CSV di bawah `tournaments.standings.export`.

### Point Rules

Tab `Battle Royale · Race · Bracket · Judged` daripada `kind`.

Borang edit memapar jadual placement, nilai kill, jadual penalti dan susunan tie-break sebagai medan. Excel anda menjadi satu baris di sini.

### Hall of Fame

Tab `Awaiting Publish · Published`.

Menekan Publish memapar dialog yang menyenaraikan podium yang akan dibekukan, dan menyatakan bahawa pembetulan selepas itu tidak akan mengubahnya (R11.5).

### Settings

Tab `Match Day · Maps · Public Display`. Satu borang setiap tab.

---

## Halaman awam

```
/hall-of-fame               tournament_champions       dibekukan
/events/{slug}/ranking      tournament_standings       hidup
```

Ranking awam menghormati tetapan `public_rankings_live`. Bila hidup, ia menyatakan berapa match daripada berapa sudah selesai, supaya pelawat tahu ia belum akhir.

Kedua-duanya tidak memapar apa-apa maklumat peribadi selain nama pasukan atau nama peserta dan angka yang membentuk markah (R12.5).

---

## Permission

Tujuh belas permission sudah diseed dalam kumpulan `Tournament`, enam modul. Reka bentuk ini tidak menambah apa-apa, cuma menggunakannya:

```
tournaments.view / create / update / delete
tournaments.matches.view / generate / score
tournaments.standings.view / export
tournaments.rules.view / create / update / delete
tournaments.halloffame.view / publish
tournaments.settings.view / update
```

Role `Referee` akan diseed dengan `admin.access`, `tournaments.view`, `tournaments.matches.view`, `tournaments.matches.score`, `tournaments.standings.view` (D6).

Setiap aksi yang dicapai melalui borang disemak dua kali: sekali pada route, sekali dalam controller. Ini datang daripada lubang sebenar dalam modul Campaign, di mana butang Send Now memintas `campaigns.send` kerana borang menghantar ke route yang berpagar `campaigns.create` sahaja.

---

## Pengendalian ralat

| Keadaan | Kelakuan |
|---------|----------|
| Undian dijana dua kali | Ditolak. Peringkat sudah ada undian; buang dahulu. |
| Buang undian yang ada keputusan | Ditolak. Sebutkan berapa match sudah bermarkah. |
| Profil diedit sedang kejohanan hidup | Amaran + pengesahan, kemudian kira semula. |
| Profil dibuang sedang diguna | Ditolak. Senaraikan kejohanan yang menggunakannya. |
| Placement berulang dalam satu match | Ditolak dengan nama medan yang bertindih. |
| Pemain hadir melebihi `squad_size` | Ditolak. |
| Betulkan markah selepas peringkat tamat | Ditolak jika ia mengubah siapa mara. Peringkat kemudian mesti dibuang dahulu. |
| Betulkan markah selepas terbit | Ditolak sampai podium ditarik semula. |
| Kurang dua entrant | Generate Draw tidak boleh ditekan, dengan sebab bertulis. |
| Profil tidak sepadan dengan format | Ditolak, senaraikan profil yang sepadan. |

Semua penolakan kembali dengan input kekal dan sebab dalam perkataan biasa. Tiada satu pun meninggalkan baris separuh siap.

---

## Prestasi

Kiraan kedudukan menyentuh setiap `tournament_match_entrants` bagi satu kejohanan. Untuk 32 pasukan × 5 match itu 160 baris — tiada masalah.

Untuk kejohanan besar, `component_points` disimpan per baris supaya jadual kedudukan boleh dipapar tanpa menjalankan enjin lagi. Enjin hanya berjalan bila markah ditulis atau dibetulkan.

Penjanaan undian dan kiraan kedudukan berjalan dalam request yang memintanya, tidak melalui queue. Sambungan queue di sini `database` tanpa worker yang pasti berjalan, dan operator yang menekan butang mesti melihat hasilnya (R17.4). Penjanaan bracket 64 pasukan menulis 63 baris; itu selamat dalam satu request.

---

## Strategi ujian

**Unit, tanpa HTTP dan tanpa pangkalan data di mana boleh:**

- `ScoringEngine` — setiap jenis komponen, dan gabungannya. Termasuk kes sebenar anda: tempat 3, 8 kill, 3 pemain = 12 mata.
- `StandingsCalculator` — tie-break WWCD kemudian placement kemudian kill; seri sebenar selepas semua habis; penalti dikira setiap match.
- Setiap `DrawGenerator` — bilangan match betul, bye kepada seed tertinggi, pasangan silang selepas group stage, seed 1 dan 2 hanya berjumpa di final.
- `TournamentProgress` — langkah semasa dan penghalangnya bagi setiap keadaan.

**Feature, dengan pangkalan data:**

- Dua kejohanan `ongoing` serentak, format berbeza. Simpan markah pada satu, sahkan kedudukan yang satu lagi tidak berubah. Ini ujian yang mengunci R1.
- Import entrant menolak pendaftaran belum bayar dan menyatakan bilangannya.
- Setiap permission menolak pengguna yang tidak memilikinya, pada route dan dalam controller.
- Podium yang diterbitkan tidak berubah selepas markah dibetulkan.
- Setiap enam skrin memapar keadaan kosong yang betul.

---

## Markah personal pemain

Dua ledger yang tidak pernah bersentuh. Ini bukan dua pandangan atas satu nombor; ia dua nombor berbeza dengan peraturan berbeza dan pemenang berbeza.

```
LEDGER PASUKAN                      LEDGER PEMAIN
─────────────────────────────       ─────────────────────────────
tournament_match_entrants           tournament_match_players
  point_rules.components              point_rules.player_components
  point_rules.tiebreak                point_rules.player_tiebreak
        │                                   │
        v                                   v
tournament_standings                tournament_player_standings
        │                                   │
        v                                   v
tournament_champions                tournament_player_awards
  Johan kejohanan                     MVP · Top Fragger

        ╳  tiada aliran antara dua lajur ini, ke mana-mana arah
```

`StandingsCalculator` hanya membaca `TournamentMatchEntrant`. Jadual pemain tidak kelihatan olehnya, jadi ia tidak berubah satu baris pun, dan 48 ujian yang sudah lulus tidak terjejas. Itu bukan kebetulan; itulah sebab reka bentuk ini dipilih.

### `tournament_match_players`

```
id
tournament_match_entrant_id  fk      pasukan mana, match mana
event_participant_id         fk      orang sebenar
took_part                    boolean kehadiran, bukan markah
inputs                       json    apa yang operator taip
points                       decimal DIKIRA daripada player_components
component_points             json    pecahan per komponen
component_counts             json    untuk player_tiebreak
recorded_by                  fk users
timestamps

unique (tournament_match_entrant_id, event_participant_id)
index (event_participant_id)
```

Digantung pada `tournament_match_entrant_id`, bukan pada `tournament_match_id` + participant. Tiga sebab: baris pasukan-match itu sudah tahu pasukan mana dan match mana, jadi tiada lajur berulang; membuang fixture cascade bersih dalam satu langkah (R18.23); dan memapar jumlah pemain bersebelahan angka pasukan menjadi satu query satu parent, bukan join tiga hala.

`took_part` diasingkan daripada `inputs` dengan sengaja. Ia fakta kehadiran, bukan markah. Pemain yang hadir tetapi tiada kill masih berbeza daripada pemain yang tidak main.

### `tournament_player_standings`

```
id
tournament_id           fk
tournament_stage_id     fk       null bermakna keseluruhan kejohanan
tournament_entrant_id   fk       pasukan dia mewakili
event_participant_id    fk
display_name            string   dicache untuk susunan dan carian
matches_played          smallint
component_totals        json     {"kills":18,"knocks":11,"damage":24800}
total_points            decimal
rank                    smallint
entrant_is_disqualified boolean  R18.20
timestamps

unique (tournament_stage_id, event_participant_id)
index (tournament_id, rank)
```

Dikira semula daripada baris sumber, tidak pernah ditambah. Sebab sama seperti `tournament_standings`.

### `tournament_player_awards`

```
id
tournament_id         fk
event_participant_id  fk
award_key             string   mvp | top_fragger | ... daripada profil
rank                  smallint
display_name          string   DISALIN
ign                   string   DISALIN
entrant_name          string   DISALIN
total_points          decimal  DISALIN
component_totals      json     DISALIN
published_at          timestamp
published_by          fk users
timestamps

unique (tournament_id, award_key, rank)
```

Disalin, bukan dirujuk. Sebab sama seperti `tournament_champions`: membetulkan satu match tiga bulan selepas trofi diberi tidak boleh menukar MVP yang sudah diumumkan.

### Perubahan pada ScoringEngine

Satu pengekstrakan, tiada logik baharu:

```php
// sebelum
public function score(PointRule $rule, array $inputs): array
{
    foreach ($rule->components ?? [] as $component) { ... }
}

// selepas
public function score(PointRule $rule, array $inputs): array
{
    return $this->scoreComponents($rule->components ?? [], $inputs);
}

public function scorePlayer(PointRule $rule, array $inputs): array
{
    return $this->scoreComponents($rule->player_components ?? [], $inputs);
}

private function scoreComponents(array $components, array $inputs): array
{
    // isi asal score(), tidak berubah
}
```

Lima jenis komponen yang sama menampung markah pemain tanpa jenis keenam. Bukti: `perUnit` sudah memulangkan `count` bersama `points`, jadi komponen `per_unit` dengan `value: 0` membawa kiraan tanpa memberi mata. Itulah cara Damage boleh menjadi tie-break MVP tanpa Damage menjadi markah — mekanisme yang sama yang membuatkan WWCD bernilai sifar tetapi tetap dikira.

### Profil pemain lalai untuk PMPL

```
player_components:
  kills    per_unit  value 1     ← ini yang jadi mata
  knocks   per_unit  value 0     ← dikira sahaja, untuk tie-break
  damage   per_unit  value 0     ← dikira sahaja, untuk tie-break

player_inputs:
  kills    integer  min 0  required
  knocks   integer  min 0  optional
  damage   integer  min 0  optional

player_tiebreak: ["kills", "damage", "knocks"]
```

Ini lalai yang boleh diubah sepenuhnya dari borang Point Rule. Ia bukan dalam kod.

### Borang markah

Baris pemain tertutup secara lalai. Satu chevron per entrant.

```
 COMPETITOR              PLACEMENT   KILLS   PLAYERS
 ────────────────────────────────────────────────────
 v STORM RIDERS             1         12    [ 4 ]
   ┌────────────────────────────────────────────────┐
   │  PERSONAL SCORE  (optional — ledger berasingan)│
   │                          KILLS  KNOCK  DAMAGE  │
   │  [x] AHMAD FAIZ   Faiz     5      3     1420   │
   │  [x] LIM WEI JIE  WJ_St    4      2     1180   │
   │  [x] RAJESH KUMAR RajS     2      4      960   │
   │  [x] NURUL AIN    NurS     1      1      540   │
   │  [ ] SITI ZUBAIDAH SitiZ      tidak main       │
   │  ────────────────────────────────────────────  │
   │  4 ditanda  [guna untuk Players]               │
   │  Jumlah kills pemain 12  ·  kills pasukan 12   │
   │  Markah personal dikira sendiri dan tidak      │
   │  masuk markah pasukan.                          │
   └────────────────────────────────────────────────┘
 > LANANG SNIPERS           2          8    [ 4 ]
```

Butang `guna untuk Players` hanya menaip `4` ke dalam medan Players. Ia tidak mengikatnya. Operator masih boleh menulis 3 selepas itu. Ini yang menguatkuasakan R18.12: tiada apa mengalir automatik daripada ledger optional ke ledger yang menentukan juara.

Baris `Jumlah kills pemain 12 · kills pasukan 12` ialah maklumat, bukan pengesahan. Tiada amaran bila ia tidak sama, kerana kalau dua ledger memang bebas maka tidak sama itu bukan kesilapan (R18.14). Ia dipapar supaya operator boleh perasan typo sendiri.

### Rescorer

Satu kelas yang tidak dirancang tetapi diperlukan, dan sebabnya patut dicatat.

`StandingsCalculator` dan `PlayerStandingsCalculator` menjumlahkan `component_points` yang **sudah disimpan** pada baris match. Itu betul untuk kelajuan, tetapi ia bermakna membina kedudukan semula tidak boleh perasan bahawa satu kill kini berharga dua mata: ia akan menjumlahkan nombor lama dan mendapat jawapan lama.

Jadi bila profil diedit, `inputs` mentah mesti dimasukkan semula ke dalam engine dahulu:

```
profil diedit
     │
     v
Rescorer         → inputs mentah masuk engine semula
     │              tulis points, component_points, component_counts
     v
StandingsCalculator      + PlayerStandingsCalculator
     │                          │
     v                          v
tournament_standings     tournament_player_standings
```

`inputs` ialah satu-satunya lajur yang operator taip dan yang tidak pernah diterbitkan daripada apa-apa. Semua yang lain boleh dibina semula daripadanya. Itulah sebab keduanya dipisahkan sejak awal, dan kelas ini yang menggunakan pemisahan itu.

Melangkau fixture yang belum dimainkan. Menulis keputusan sifar ke atasnya akan menukar match `scheduled` menjadi `completed` dengan semua orang kosong.

### Skrin dan halaman awam

`Standings` mendapat satu tab tambahan `Players`, muncul hanya bila `track_players` bukan `off`. Hall of Fame mendapat bahagian `Player Awards` yang boleh diterbitkan sendiri.

Halaman awam `/events/{slug}/ranking` mendapat satu jadual kedua di bawah ranking pasukan. Nama, IGN, pasukan dan angka markah sahaja — tiada IC, alamat, telefon, emel atau tarikh lahir (R18.26).

---

## Keputusan dan sebabnya

**Markah pemain ialah ledger kedua, bukan lajur tambahan pada ledger pasukan.** Kalau markah pemain menyumbang kepada markah pasukan, ia tidak lagi optional: podium akan berubah bergantung pada sama ada operator sempat mengisinya. Pukul 2 pagi selepas sepuluh match, operator mesti boleh melangkau data pemain dan kejohanan mesti masih tamat dengan juara yang betul. Pemisahan penuh itulah yang menjadikannya benar-benar optional.

**`players_present` tidak dikira automatik daripada checkbox kehadiran.** Ini cadangan pertama saya dan ia salah. Ia akan menjadikan penalti pasukan bergantung pada data pemain yang optional — tepat masalah yang cuba dielakkan. Ia kini butang yang operator tekan sendiri.

**Peserta ialah `event_registrations`, bukan jadual pasukan baharu.** Pasukan sudah ada di situ dengan nama dan logo, dan pemainnya dengan IGN. Jadual kedua bermakna dua sumber kebenaran dan penyelarasan antara keduanya.

**Komponen mata sebagai senarai, bukan lajur tetap.** Lajur tetap ialah PUBG dikodkan dengan nilai boleh tukar. Tennis tiada kill, Aerobic tiada placement. Sebelas sukan dengan lajur tetap bermakna dua belas lajur yang sembilan sentiasa null.

**Borang markah dijana daripada profil.** Satu borang setiap sukan bermakna sebelas borang, dan sukan kedua belas bermakna borang kedua belas. Menjananya bermakna sukan baharu adalah satu baris data.

**`inputs` sebagai JSON, bukan lajur.** Sama sebab. Menambah sukan tidak boleh memerlukan migration.

**Kedudukan dikira, tidak ditambah.** Datang daripada pepijat sebenar dalam Campaign: dua proses menambah lajur sama pada masa sama kehilangan satu tulisan.

**Podium dibekukan dengan menyalin.** Datang daripada pepijat sebenar yang sama: rekod apa yang berlaku mesti tidak berubah bila sumbernya diedit.

**`settings` disalin ke kejohanan masa dicipta.** Menukar buffer lalai bulan depan tidak boleh mengubah kejohanan yang sedang berjalan.

**Double Elimination hanya untuk Top 4.** Nasihat anda sendiri, dan penjana menguatkuasakannya dengan menolak lebih lapan entrant.

**Semua kiraan dalam request, bukan queue.** Queue di sini tiada worker yang pasti berjalan. Operator yang menekan Generate Draw mesti melihat bracketnya, bukan halaman yang mengatakan ia beratur.

**Permission disemak dua kali.** Route dan controller. Lubang sebenar dalam Campaign berlaku tepat kerana satu semakan sahaja.
