# Requirements — Sistem Tournament

## Pengenalan

Sistem ini menjalankan pertandingan selepas pendaftaran event selesai, dan menerbitkan keputusannya ke laman awam sebagai Hall of Fame dan Ranking.

Ia mesti menampung sebelas sukan yang dinamakan pengguna (PUBG, PUBG Mobile, Mobile Legends, Run, Bicycle, Tennis, Badminton, Pickleball, Course Event, Aerobic Event) dan sukan lain yang belum diketahui, **tanpa menambah kod untuk setiap sukan baharu**. Sebelas sukan itu jatuh ke dalam empat keluarga pemarkahan: bracket lawan-lawan, battle royale mata terkumpul, race masa atau kedudukan, dan judged markah hakim.

Keperluan paling keras daripada pengguna: **berbilang kejohanan mesti boleh berjalan serentak**. Satu kejohanan PUBG dan satu kejohanan Mobile Legends mesti boleh hidup pada masa yang sama, masing-masing dengan format dan pemarkahan sendiri. Sistem tidak boleh sekali-kali hanya boleh diguna oleh satu kejohanan pada satu masa.

Keperluan kedua: **aliran kerja mesti jelas langkah demi langkah**, daripada cipta kejohanan, masuk atau import peserta, sampai selesai dan terbit.

Peserta pertandingan ialah `event_registrations` yang sudah ada. Untuk event pasukan itu satu skuad; untuk event individu itu seorang. Tiada jadual pasukan baharu dicipta.

### Apa yang sudah siap dan tidak termasuk dalam skop dokumen ini

Kumpulan sidebar `Tournament` dengan ikon trophy, enam item menu, 17 permission dalam 6 modul matrix, dan enam skrin kosong yang guna `x-admin.settings-shell` sudah dibina dan diuji 200. Dokumen ini menentukan apa yang mengisi skrin itu.

---

## Keputusan yang masih menunggu pengguna

Dua belas perkara ini belum dijawab. Setiap satu ada **cadangan lalai** yang saya akan guna kalau tiada bantahan, dan setiap satu ditandakan dalam requirement yang berkenaan.

| # | Soalan | Cadangan lalai |
|---|--------|----------------|
| D1 | Penalti skuad tak cukup: setiap match atau sekali sahaja? | Setiap match. Skuad hadir 3 orang dalam 2 match = -2 keseluruhan. |
| D2 | Saiz skuad penuh datang dari mana? | Diset dalam Point Rule (`squad_size`), bukan `min_players` event, kerana satu event boleh ada dua divisyen bersaiz berbeza. |
| D3 | DQ sebab 0 pemain: skop match atau kejohanan? | Match itu sahaja. Kekal dalam jadual bertanda DQ pada match itu. DQ seluruh kejohanan mesti tindakan manual berasingan. |
| D4 | Point Rules: item sidebar sendiri atau tab dalam Settings? | Kekal item sendiri. Sebelas sukan bermakna sebelas atau lebih profil, dan itu senarai diurus bukan satu borang. |
| D5 | Berbilang kejohanan dalam SATU event? | Ya, dibenarkan. Contoh Main Event dan Ladies Division atas event PUBG yang sama. |
| D6 | Role Referee diperlukan? | Ya, disediakan sebagai role terseed. |
| D7 | Screenshot bukti wajib sebelum match ditutup? | Wajib boleh ditetapkan per kejohanan dalam Settings. Lalai: tidak wajib. |
| D8 | Hall of Fame terbit automatik? | Tidak. Wajib tekan Publish, kerana ia menukar laman awam. |
| D9 | Ranking awam hidup semasa kejohanan? | Boleh ditetapkan dalam Settings. Lalai: hidup. |
| D10 | Kaedah seeding? | Tiga pilihan disediakan: manual, rawak, ikut urutan pendaftaran. Lalai manual. |
| D11 | BO3/BO5: rekod setiap game atau siri sahaja? | Siri sahaja (`games_won`). Rekod per game jadi peningkatan kemudian. |
| D12 | Jadual masa dengan buffer dijana automatik? | Ya, dijana dengan buffer daripada Settings, dan boleh diubah manual selepas itu. |

---

## Requirement 1 — Berbilang kejohanan serentak

**User story:** Sebagai penganjur, saya mahu menjalankan kejohanan PUBG dan Mobile Legends pada masa yang sama, supaya sistem ini tidak terhad kepada satu kejohanan sahaja.

### Acceptance Criteria

1. THE system SHALL allow any number of tournaments to exist with status `ongoing` at the same time.
2. WHERE two tournaments belong to different events, THE system SHALL keep their entrants, stages, matches, standings and point rules entirely separate.
3. WHERE two tournaments belong to the same event, THE system SHALL still keep them separate, so an event may run an Open division and a Ladies division concurrently. *(D5)*
4. THE system SHALL allow two concurrent tournaments to use different formats and different point rules.
5. THE system SHALL NOT hold any tournament state in configuration, session or a singleton row; every query SHALL be scoped by `tournament_id`.
6. WHEN a score is entered for a match, THE system SHALL recalculate standings for that match's tournament only, and SHALL NOT touch any other tournament.
7. WHEN the operator opens Matches or Standings, THE system SHALL require a tournament to be chosen, and SHALL show which tournament is being viewed at all times.

---

## Requirement 2 — Profil pemarkahan yang boleh dikonfigurasi

**User story:** Sebagai penganjur, saya mahu menetapkan sendiri nilai WWCD, placement, kill dan susunan tie-break, supaya menambah sukan baharu tidak memerlukan kod baharu.

### Acceptance Criteria

1. THE system SHALL store scoring as reusable named profiles in `point_rules`, each with a `kind` of `battle_royale`, `bracket`, `race` or `judged`.
2. THE system SHALL store each profile's scoring as an ordered list of components, and SHALL support these component types:
   - `table` — a position maps to points
   - `per_unit` — each unit earns points
   - `bonus` — a condition earns points, and may be worth zero while still being counted for tie-break
   - `penalty_table` — a shortfall subtracts points
   - `aggregate` — combine judge marks by sum, mean, or trimmed mean dropping the highest and lowest
3. THE system SHALL store the tie-break order as an ordered list of component keys.
4. THE system SHALL store which inputs the score entry form must ask for, as part of the profile.
5. THE system SHALL NOT hardcode any placement value, kill value, penalty value or tie-break order anywhere in application code.
6. WHEN a new sport is required, THE system SHALL allow it to be added by creating a profile alone, with no code change, provided its scoring uses the component types above.
7. THE system SHALL ship a seeded profile named for PMPL with placement 1st=10, 2nd=6, 3rd=5, 4th=4, 5th=3, 6th=2, 7th=1, 8th=1, 9th to 16th=0, each kill worth 1, a WWCD bonus worth 0 counted for tie-break, a squad penalty of 3 players=-1, 2=-2, 1=-3, 0=DQ, and tie-break order WWCD then placement then kill.
8. THE profile SHALL carry `squad_size` for the penalty table to be measured against. *(D2)*
9. IF a profile is in use by any tournament, THEN THE system SHALL refuse to delete it and SHALL say which tournaments use it.
10. WHEN a profile is edited while a tournament using it is `ongoing`, THE system SHALL warn that standings already recorded will be recalculated, and SHALL require confirmation.

---

## Requirement 3 — Cipta kejohanan dengan aliran berperingkat yang jelas

**User story:** Sebagai penganjur kali pertama, saya mahu tahu saya berada di langkah mana dan apa yang menghalang langkah berikutnya, supaya saya tidak tersekat.

### Acceptance Criteria

1. THE system SHALL present tournament setup as an ordered sequence of steps: choose event, name and format, choose point rule, add entrants, seed, generate the draw, then play.
2. THE system SHALL show, on the tournament detail screen, which steps are complete, which step is current, and what is blocking the next step.
3. WHERE a step cannot yet be started, THE system SHALL state the reason in plain words rather than disabling a control without explanation.
4. THE system SHALL NOT allow the draw to be generated before at least two entrants exist.
5. THE system SHALL NOT allow a tournament to move to `ongoing` before its draw has been generated.
6. WHEN the operator creates a tournament, THE system SHALL require an event, a name, a format and a point rule whose `kind` is compatible with that format.
7. IF the chosen point rule's `kind` does not match the chosen format, THEN THE system SHALL refuse to save and SHALL say which profiles are compatible.
8. THE system SHALL set a new tournament's status to `setup`.
9. THE system SHALL copy the current Tournament Settings into the tournament's own `settings` when it is created, so later changes to the shared defaults do not alter a tournament already under way.

---

## Requirement 4 — Peserta: import dan urus

**User story:** Sebagai penganjur, saya mahu menarik masuk pendaftaran yang sudah bayar sebagai peserta kejohanan, supaya saya tidak menaip semula senarai pasukan.

### Acceptance Criteria

1. THE system SHALL offer to import entrants from the `event_registrations` of the tournament's event.
2. THE system SHALL only offer registrations whose status is confirmed and whose payment status is paid.
3. WHERE a registration is unpaid or cancelled, THE system SHALL exclude it from the import and SHALL state how many were excluded and why.
4. THE system SHALL allow an entrant to be added by hand for a registration the operator selects deliberately, including an unpaid one, and SHALL record that it was added by hand.
5. THE system SHALL NOT allow the same `event_registration_id` to appear twice in one tournament.
6. THE system SHALL allow an entrant to be removed while the tournament is in `setup`.
7. IF the tournament is `ongoing` or later, THEN THE system SHALL refuse to remove an entrant and SHALL direct the operator to withdrawal or disqualification instead.
8. THE system SHALL show, for each entrant, the team name or person name, the number of players on the registration, the payment status, and the seed.
9. WHERE the event requires an in-game name, THE system SHALL show each player's IGN and SHALL flag entrants with any player missing one.

---

## Requirement 5 — Seeding

**User story:** Sebagai penganjur, saya mahu mengatur seeding supaya pasukan kuat tidak berjumpa di pusingan awal.

### Acceptance Criteria

1. THE system SHALL support three seeding methods: manual ordering, random draw, and registration order. *(D10)*
2. THE system SHALL default to manual ordering.
3. WHEN random draw is chosen, THE system SHALL assign every active entrant a unique seed and SHALL record that the draw was random, by whom and when.
4. THE system SHALL keep seeds unique and contiguous from 1 within one tournament.
5. THE system SHALL allow seeds to be changed while the tournament is in `setup`.
6. IF the draw has already been generated, THEN THE system SHALL refuse to change seeds until the draw is discarded.

---

## Requirement 6 — Peringkat dan jana undian

**User story:** Sebagai penganjur, saya mahu sistem menjana bracket, kumpulan atau lobi supaya saya tidak menyusun puluhan perlawanan dengan tangan.

### Acceptance Criteria

1. THE system SHALL allow a tournament to hold several stages in sequence, each of type `group`, `bracket`, `lobby` or `heat`, so that a group stage may be followed by a bracket.
2. THE system SHALL generate, for `single_elim`, a bracket seeded so that seed 1 meets the lowest seed, seed 2 the second lowest, and so on.
3. WHERE the entrant count is not a power of two, THE system SHALL award byes to the highest seeds so that the first full round is a power of two.
4. THE system SHALL generate, for `group_single_elim`, round-robin fixtures within each group, and SHALL then pair the qualifiers across groups so that A1 meets B2 and B1 meets A2.
5. THE system SHALL generate, for `double_elim`, an upper bracket, a lower bracket and a grand final, where losing once moves an entrant to the lower bracket and losing twice eliminates it.
6. THE system SHALL generate, for `battle_royale`, lobbies of at most 16 entrants and the configured number of matches per lobby.
7. THE system SHALL generate, for `race` and `judged`, a single match per heat holding every entrant in that heat.
8. THE system SHALL apply the configured best-of per round when generating bracket fixtures.
9. THE system SHALL apply the configured map rotation when generating battle royale matches.
10. THE system SHALL schedule generated matches using the buffer from settings, and SHALL allow every scheduled time to be edited afterwards. *(D12)*
11. IF a draw already exists for a stage, THEN THE system SHALL refuse to generate it again, and SHALL require the existing draw to be discarded first.
12. THE system SHALL NOT allow a draw to be discarded once any match in that stage has a recorded result.
13. WHEN a stage completes, THE system SHALL advance the top `advance_count` entrants of each group into the next stage, ordered by the tie-break rules.
14. THE system SHALL record who generated a draw and when.

---

## Requirement 7 — Masuk markah

**User story:** Sebagai kakitangan di meja pengadil, saya mahu satu tempat yang jelas untuk memasukkan markah dengan cepat pada hari kejohanan.

### Acceptance Criteria

1. THE system SHALL provide match score entry from the Matches screen, reachable in one press from the match row.
2. THE system SHALL generate the score entry form from the tournament's point rule inputs, and SHALL NOT carry a separate hardcoded form per sport.
3. WHERE the profile kind is `battle_royale`, THE form SHALL ask for placement, kills and players present for every entrant in the match.
4. WHERE the profile kind is `bracket`, THE form SHALL ask for the series result for each of the two sides. *(D11)*
5. WHERE the profile kind is `race`, THE form SHALL ask for a finishing time per entrant, and SHALL derive placement from those times.
6. WHERE the profile kind is `judged`, THE form SHALL ask for each judge's mark and any deductions, and SHALL show the aggregate it computed.
7. THE system SHALL show the computed points for every entrant before the form is saved, so the operator can check them against the game screen.
8. THE system SHALL compute points and SHALL NOT accept a typed points total.
9. THE system SHALL validate that placements within one battle royale match are unique.
10. THE system SHALL validate that players present does not exceed the profile's `squad_size`.
11. THE system SHALL allow a result screenshot to be attached, and WHERE settings require proof, THE system SHALL refuse to close the match without one. *(D7)*
12. THE system SHALL record who entered the score and when.
13. THE score entry form SHALL be operable by keyboard alone, with every field labelled, and SHALL move focus in reading order down the entrant list.
14. WHEN a score is saved, THE system SHALL recalculate that tournament's standings within the same request, and SHALL NOT depend on a queue worker.

---

## Requirement 8 — Standings dan tie-break

**User story:** Sebagai penganjur, saya mahu kedudukan dikira sendiri daripada perlawanan supaya tiada nombor bercanggah.

### Acceptance Criteria

1. THE system SHALL compute standings from match results, and SHALL NOT allow any standings figure to be typed.
2. THE system SHALL recompute standings by counting from source rows rather than incrementing stored counters.
3. THE system SHALL show, per entrant, matches played, points per match, and the total for every component the profile defines.
4. THE system SHALL apply the short squad penalty once per match in which the squad was short. *(D1)*
5. THE system SHALL evaluate tie-breaks in the profile's configured order.
6. WHERE two entrants remain equal after every tie-break is exhausted, THE system SHALL show them as genuinely tied and SHALL say that the organiser must decide.
7. THE system SHALL mark the cut line where entrants advance to the next stage.
8. THE system SHALL keep a disqualified entrant visible in the table marked DQ rather than removing it. *(D3)*
9. THE system SHALL allow standings to be exported as CSV under `tournaments.standings.export`.
10. THE Standings screen SHALL present one tab per stage of the chosen tournament, and SHALL show no tab bar when the tournament has no stages.

---

## Requirement 9 — Walkover, forfeit, DQ dan tarik diri

**User story:** Sebagai penganjur, saya mahu merekod pasukan yang tidak hadir atau melanggar peraturan tanpa merosakkan bracket.

### Acceptance Criteria

1. THE system SHALL allow a match to be recorded as a walkover in favour of one side.
2. WHEN a walkover is recorded in a bracket, THE system SHALL advance the present side and SHALL mark the absent side eliminated.
3. THE system SHALL allow an entrant to be marked forfeit for lateness, using the lateness allowance from settings.
4. THE system SHALL allow an entrant to be disqualified from the whole tournament as a deliberate, separate action from a zero-player match DQ. *(D3)*
5. WHEN an entrant is disqualified from the tournament, THE system SHALL keep its recorded results in place, SHALL mark it DQ in standings, and SHALL award walkovers in its remaining bracket matches.
6. THE system SHALL allow an entrant to withdraw, and SHALL treat its remaining matches the same as a disqualification for the purpose of the opponent.
7. THE system SHALL require a reason for every walkover, forfeit, disqualification and withdrawal, and SHALL record who did it and when.
8. WHERE a zero-player squad is recorded in a battle royale match, THE system SHALL mark that match DQ for that entrant and SHALL leave the entrant active for later matches. *(D3)*

---

## Requirement 10 — Pembetulan markah selepas ditutup

**User story:** Sebagai penganjur, saya mahu membetulkan markah yang salah dimasukkan, dan saya mahu tahu apa yang berubah akibatnya.

### Acceptance Criteria

1. THE system SHALL allow a closed match's score to be corrected under `tournaments.matches.score`.
2. WHEN a score is corrected, THE system SHALL recompute standings for that tournament and SHALL recompute which entrants advance.
3. WHERE a correction changes who advanced from a completed stage, THE system SHALL refuse the correction and SHALL state that later stages must be discarded first.
4. THE system SHALL record every correction with the previous value, the new value, who made it and when.
5. WHERE the tournament's podium is already published, THE system SHALL refuse the correction until the podium is withdrawn. *(D8)*

---

## Requirement 11 — Hall of Fame

**User story:** Sebagai penganjur, saya mahu menerbitkan podium ke laman awam, dan saya mahu ia kekal walaupun markah dibetulkan kemudian.

### Acceptance Criteria

1. THE system SHALL list tournaments whose every match is complete as awaiting publish.
2. THE system SHALL require an explicit Publish press under `tournaments.halloffame.publish`, and SHALL NOT publish automatically. *(D8)*
3. WHEN a podium is published, THE system SHALL copy the top three entrants' names and totals into `tournament_champions` and SHALL freeze them.
4. THE system SHALL NOT rebuild a published podium from standings, so that correcting a match months later cannot silently change an announced result.
5. THE system SHALL show, before publishing, exactly which podium will be frozen and SHALL state that corrections afterwards will not change it.
6. THE system SHALL allow a published podium to be withdrawn, and SHALL record who withdrew it and when.
7. THE system SHALL record who published each podium and when.

---

## Requirement 12 — Halaman awam

**User story:** Sebagai pelawat laman web, saya mahu melihat juara terdahulu dan kedudukan semasa.

### Acceptance Criteria

1. THE system SHALL publish `/hall-of-fame` showing published champions grouped by year and event, read from the frozen `tournament_champions`.
2. THE system SHALL publish `/events/{slug}/ranking` showing standings for that event's tournaments.
3. WHERE settings say rankings are live, THE public ranking SHALL show standings while the tournament is still `ongoing`, and SHALL state how many matches of how many are complete. *(D9)*
4. WHERE settings say rankings are not live, THE public ranking SHALL show nothing until the podium is published.
5. THE public pages SHALL NOT show any personal detail beyond the team name or competitor name and the figures that make up the score.
6. THE public pages SHALL NOT require authentication.

---

## Requirement 13 — Tournament Settings

**User story:** Sebagai penganjur, saya mahu menetapkan cara hari kejohanan dijalankan sekali sahaja, dan setiap kejohanan baharu mewarisinya.

### Acceptance Criteria

1. THE system SHALL provide settings for the buffer between matches, defaulting to 15 minutes.
2. THE system SHALL provide settings for the lateness allowed before a forfeit, defaulting to 10 minutes.
3. THE system SHALL provide settings for the default best-of per round.
4. THE system SHALL provide a setting for whether a result screenshot is required before a match may be closed, defaulting to not required. *(D7)*
5. THE system SHALL provide map pools per game, and the rotation used when generating a stage.
6. THE system SHALL provide a setting for whether public rankings are live, defaulting to live. *(D9)*
7. THE system SHALL provide a device rule setting recording whether a tournament is mobile only or allows emulators.
8. WHEN a tournament is created, THE system SHALL copy these settings into that tournament, so a later change to the defaults does not alter a tournament already under way.
9. THE system SHALL allow a tournament's own copy of these settings to be edited while it is in `setup`.
10. THE Settings screen SHALL present the tabs Match Day, Maps and Public Display.

---

## Requirement 14 — Permission dan role

**User story:** Sebagai pentadbir, saya mahu pengadil boleh memasukkan markah tanpa boleh menyentuh apa-apa lagi.

### Acceptance Criteria

1. THE system SHALL enforce the seventeen already-seeded Tournament permissions on every route, and SHALL check them again in the controller for any action reached from a form.
2. THE system SHALL gate draw generation behind `tournaments.matches.generate`, separately from `tournaments.create`.
3. THE system SHALL gate score entry behind `tournaments.matches.score`.
4. THE system SHALL gate publishing behind `tournaments.halloffame.publish`.
5. THE system SHALL seed a `Referee` role holding `admin.access`, `tournaments.view`, `tournaments.matches.view`, `tournaments.matches.score` and `tournaments.standings.view` and nothing else. *(D6)*
6. WHERE an account lacks a screen's view permission, THE sidebar SHALL hide exactly that item, and SHALL hide the whole Tournament group only when every item is hidden.
7. WHERE an account lacks a write permission, THE screen SHALL omit the control rather than showing one that fails on press.

---

## Requirement 15 — Audit dan jejak

**User story:** Sebagai pentadbir, saya mahu tahu siapa mengubah markah bila timbul pertikaian.

### Acceptance Criteria

1. THE system SHALL write an audit record for every score entry and correction, holding the match, the entrant, the previous value, the new value, the actor and the time.
2. THE system SHALL write an audit record for draw generation and for discarding a draw.
3. THE system SHALL write an audit record for every walkover, forfeit, disqualification and withdrawal, with its reason.
4. THE system SHALL write an audit record for publishing and withdrawing a podium.
5. THE system SHALL write an audit record for creating, editing and deleting a point rule.

---

## Requirement 16 — Keadaan kosong dan kebolehcapaian

**User story:** Sebagai pengguna baharu, saya mahu setiap skrin memberitahu saya apa yang perlu dibuat dahulu.

### Acceptance Criteria

1. WHERE a screen has no data, THE system SHALL state what is missing and what the next step is, and SHALL NOT show only an empty table.
2. THE Tournaments screen SHALL say that a tournament is built on an event's paid entries.
3. THE Matches screen SHALL say that fixtures appear once a draw has been generated.
4. THE Standings screen SHALL say that standings fill in as results are entered.
5. THE Point Rules screen SHALL say that this is where placement, kill values and tie-break order are set.
6. THE Hall of Fame screen SHALL say that a tournament arrives once every match is complete.
7. THE system SHALL NOT render a link or button whose destination does not exist.
8. Every table SHALL carry column headers with a scope, every form control SHALL carry a label, and status SHALL be conveyed by text and not by colour alone.
9. Every screen SHALL follow the existing standard: `x-admin.settings-shell` where tabs exist, `x-admin.page-card` where they do not, with a `section-intro` above a bordered white card holding the filter bar, the table and a footer.

---

## Requirement 17 — Ketahanan pengiraan

**User story:** Sebagai penganjur, saya mahu angka yang betul walaupun dua pengadil menyimpan markah pada masa yang sama.

### Acceptance Criteria

1. THE system SHALL compute every derived figure by counting source rows, and SHALL NOT increment a stored counter.
2. WHEN two score entries for the same tournament are saved at the same moment, THE system SHALL produce the same standings as if they had been saved one after the other.
3. THE system SHALL write a match result and its recomputed standings in one transaction.
4. THE system SHALL complete score entry, draw generation and publishing within the request that asked for them, and SHALL NOT leave the operator waiting on a queue worker.
5. WHERE a generation would exceed a safe request time, THE system SHALL say plainly that it has been queued and what must be running for it to finish.

---

## Requirement 18 — Markah personal pemain

**User story:** Sebagai penganjur, saya mahu merekod markah setiap pemain secara berasingan daripada markah pasukan, supaya saya boleh mengumumkan MVP dan Top Fragger tanpa angka itu menjejaskan siapa juara.

Keperluan paling keras dalam requirement ini: **markah pasukan dan markah personal tidak boleh bercampur**. Ia dua ledger berasingan. Kalau ia bercampur, markah pemain tidak lagi optional, kerana keputusan kejohanan akan berubah bergantung pada sama ada operator sempat mengisinya atau tidak.

### Acceptance Criteria

1. THE system SHALL keep player scoring in a ledger entirely separate from team scoring, and SHALL NOT add a player's points into any team total, nor a team's points into any player total.
2. THE system SHALL allow a tournament to run from creation to a published podium without a single player figure being entered.
3. THE system SHALL store on each point rule a `track_players` setting of `off`, `optional` or `required`, defaulting to `off`.
4. WHERE `track_players` is `off`, THE score entry form SHALL NOT show any player row at all.
5. WHERE `track_players` is `required`, THE system SHALL refuse to close a match until every participating player of every entrant has a figure.
6. THE system SHALL store player scoring components on the point rule as `player_components`, using the same five component types as team scoring, and SHALL NOT require them to resemble the team components.
7. THE system SHALL store the inputs the player rows ask for as `player_inputs` on the point rule.
8. THE system SHALL store the player ranking order on the point rule as `player_tiebreak`.
9. THE system SHALL NOT hardcode any player point value, player input or player tie-break order anywhere in application code.
10. THE system SHALL offer, for each entrant in a match, only the `event_participants` of that entrant's registration whose `role` is `player`.
11. THE system SHALL allow the operator to mark which of those players took part in that match, and SHALL record that attendance.
12. THE system SHALL NOT derive the team's `players_present` input from that attendance automatically, because optional player data must never change a team's penalty.
13. THE system SHALL offer a control that copies the attendance count into the `players_present` field as a deliberate operator action, and the operator SHALL be able to overwrite the value afterwards.
14. THE system SHALL display the sum of a player input across one entrant's players beside that entrant's own figure as information only, and SHALL NOT warn on, nor refuse, a mismatch.
15. THE system SHALL compute player points from `player_components` and SHALL NOT accept a typed player points total.
16. THE system SHALL keep player rows collapsed by default, and SHALL allow one entrant to be expanded at a time.
17. THE system SHALL compute player standings per tournament and per stage by counting source rows, and SHALL NOT increment a stored counter.
18. THE system SHALL rank player standings in the order given by `player_tiebreak`.
19. WHERE a player is already recorded under another entrant in the same tournament, THE system SHALL refuse the entry and SHALL name the entrant that already holds that player.
20. WHERE an entrant is disqualified from the tournament, THE system SHALL leave its players' recorded personal figures in place, and SHALL mark them as belonging to a disqualified entrant in the player standings.
21. THE system SHALL allow player awards to be published, and SHALL copy the player name, the in-game name, the entrant name and the totals at publish rather than reading them live.
22. THE system SHALL allow player awards to be published independently of the team podium, and SHALL NOT require one before the other.
23. WHEN a match's result is cleared or its draw is discarded, THE system SHALL delete that match's player rows with it.
24. THE system SHALL gate player score entry behind `tournaments.matches.score`, and player award publishing behind `tournaments.halloffame.publish`.
25. THE system SHALL write an audit record for every player score entry, every correction, and every award publication and withdrawal.
26. THE public player leaderboard SHALL show the player name, the in-game name, the entrant name and the figures that make up the score, and SHALL NOT show IC number, address, phone, email or date of birth.
27. WHERE a tournament has no player figures recorded, THE player standings screen SHALL say that player scoring is optional and SHALL say where it is switched on.
28. WHERE the entrant is a single person rather than a squad, THE system SHALL treat player tracking as redundant and SHALL default `track_players` to `off` for `race` and `judged` profiles.
