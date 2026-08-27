# Tasks — Sistem Tournament

Setiap fasa boleh dijalankan dan diuji sendiri. Fasa 1 tidak bergantung pada apa-apa, dan setiap fasa selepas itu bergantung hanya pada fasa sebelumnya. Sidebar, 17 permission dan enam skrin kosong sudah dibina, jadi tidak muncul di bawah.

---

## Fasa 1 — Asas: profil pemarkahan

Dibina dahulu kerana tiada apa-apa boleh mengira markah tanpanya, dan ia boleh diuji sepenuhnya tanpa kejohanan wujud.

- [x] 1. Migration `point_rules` dan model `PointRule`
  - Lajur: `name`, `kind`, `squad_size`, `components` json, `inputs` json, `tiebreak` json, `is_active`, `created_by`
  - Cast `components`, `inputs`, `tiebreak` kepada array
  - Pemalar `KIND_BATTLE_ROYALE`, `KIND_BRACKET`, `KIND_RACE`, `KIND_JUDGED`
  - Kaedah `component(string $key)` dan `input(string $key)` untuk pembacaan
  - _Requirements: 2.1, 2.3, 2.4, 2.8_

- [x] 2. `ScoringEngine` — komponen `table` dan `per_unit`
  - Ujian unit dahulu: tempat 3 memberi 5 mata; 8 kill memberi 8 mata; tempat 12 memberi 0
  - Kembalikan `points`, `components`, `counts`, `disqualified`
  - _Requirements: 2.2, 7.8_

- [x] 3. `ScoringEngine` — komponen `bonus` dan `penalty_table`
  - `bonus` menaikkan `counts` walaupun bernilai sifar mata, kerana WWCD PMPL berkelakuan begitu
  - `penalty_table` memulangkan nilai negatif, dan menetapkan `disqualified` pada `disqualify_at`
  - Ujian unit kes sebenar pengguna: tempat 3, 8 kill, 3 pemain hadir memberi 12 mata dengan `wwcd` count 0
  - Ujian unit: tempat 1, 12 kill, 4 pemain memberi 22 mata dengan `wwcd` count 1
  - _Requirements: 2.2, 8.4_

- [x] 4. `ScoringEngine` — komponen `aggregate` untuk sukan dinilai hakim
  - Kaedah `sum`, `mean`, `trimmed_mean` yang membuang tertinggi dan terendah
  - Ujian unit: hakim 8.5, 9.0, 8.0, 8.5, 9.5 dengan trimmed mean memberi 8.67
  - _Requirements: 2.2, 7.6_

- [x] 5. `ScoringEngine` — terbitkan placement daripada masa untuk `kind` race
  - Masa disusun menaik, placement diberi, kemudian komponen `table` dinilai
  - Ujian unit termasuk masa seri
  - _Requirements: 7.5_

- [x] 6. Seed empat profil permulaan
  - `PMPL / PMGC Official` dengan nilai tepat daripada Excel pengguna
  - `Mobile Legends BO3` bracket
  - `Badminton 3 Set` bracket dengan sets dan points
  - `Aerobic 5 Hakim` judged dengan trimmed mean
  - _Requirements: 2.7_

- [x] 7. Skrin Point Rules: senarai dengan empat tab
  - Tab `Battle Royale · Race · Bracket · Judged` daripada `kind`
  - Lajur: nama, nilai per kill, mata tempat pertama, ada penalti, bilangan kejohanan yang menggunakannya
  - Butang `New Point Rule` di bawah `tournaments.rules.create`
  - _Requirements: 2.1, 16.5_

- [x] 8. Borang cipta dan edit Point Rule
  - Jadual placement, nilai kill, jadual penalti, `squad_size`, susunan tie-break sebagai medan boleh susun
  - Pengesahan: kunci tie-break mesti merujuk komponen yang wujud
  - Amaran dan pengesahan bila diedit sedang ada kejohanan `ongoing` yang menggunakannya
  - Tolak pembuangan bila sedang diguna, dan senaraikan kejohanan itu
  - _Requirements: 2.5, 2.9, 2.10_

---

## Fasa 2 — Kejohanan dan peserta

- [x] 9. Migration `tournaments` dan `tournament_entrants`, dengan modelnya
  - `tournaments`: `event_id`, `name`, `format`, `point_rule_id`, `status`, `settings` json, `seeding_method`, `draw_generated_at`, `created_by`
  - `tournament_entrants`: `tournament_id`, `event_registration_id`, `seed`, `status`, `added_by_hand`, `reason`
  - Unique `(tournament_id, event_registration_id)` dan `(tournament_id, seed)`
  - Relasi ke `Event` dan `EventRegistration` yang sudah ada
  - _Requirements: 1.1, 1.5, 4.5_

- [x] 10. Borang cipta dan edit kejohanan
  - Medan: event, nama, format, profil mata, kaedah seeding
  - Tolak bila `kind` profil tidak sepadan dengan format, dan senaraikan profil yang sepadan
  - Salin Tournament Settings semasa ke `settings` kejohanan itu
  - Status permulaan `setup`
  - _Requirements: 3.6, 3.7, 3.8, 3.9_

- [x] 11. Skrin senarai Tournaments dengan empat tab
  - Tab `Setup · Ongoing · Completed · Published` daripada `status`
  - Lajur: nama, event, format, bilangan entrant, peringkat semasa
  - Filter: event, format, carian nama
  - Keadaan kosong menyatakan kejohanan dibina atas pendaftaran event yang sudah bayar
  - _Requirements: 1.1, 16.2_

- [x] 12. `EntrantImporter`
  - Tarik `event_registrations` yang berstatus disahkan dan sudah bayar
  - Kembalikan bilangan diimport dan bilangan ditolak dengan sebabnya
  - Tolak pendaftaran yang sudah menjadi entrant tanpa ralat
  - _Requirements: 4.1, 4.2, 4.3_

- [x] 13. Tab Entrants pada halaman detail kejohanan
  - Butang Import, dan tambah satu-satu dengan tanda `added_by_hand`
  - Lajur: seed, nama pasukan, bilangan pemain, status bayaran, status entrant
  - Tanda entrant yang ada pemain tanpa IGN bila event menuntut IGN
  - Buang entrant hanya semasa `setup`; selepas itu arahkan ke tarik diri atau DQ
  - _Requirements: 4.4, 4.6, 4.7, 4.8, 4.9_

- [x] 14. Seeding
  - Tiga kaedah: manual susun, cabutan rawak, ikut urutan pendaftaran
  - Seed unik dan berterusan dari 1
  - Rakam cabutan rawak: oleh siapa dan bila
  - Tolak perubahan seed selepas undian dijana
  - _Requirements: 5.1, 5.2, 5.3, 5.4, 5.5, 5.6_

- [x] 15. `TournamentProgress` dan tab Progress
  - Tujuh langkah dengan `done`, `current` dan `blocker` bertulis
  - Ujian unit setiap keadaan: tiada entrant, entrant tanpa seed, seed tanpa undian, dan seterusnya
  - Tab Progress memapar senarai bertanda, bukan butang kelabu tanpa sebab
  - _Requirements: 3.1, 3.2, 3.3, 3.4_

---

## Fasa 3 — Peringkat dan undian

- [x] 16. Migration `tournament_stages`, `tournament_groups`, `tournament_matches`, dengan modelnya
  - `tournament_matches` membawa `tournament_id` yang didenormalkan
  - Index `(tournament_id, status)` dan `(tournament_stage_id, round, position)`
  - _Requirements: 6.1, 1.5_

- [x] 17. Antara muka `DrawGenerator` dan `SingleEliminationGenerator`
  - Saiz bracket ialah kuasa dua terkecil yang mencukupi
  - Bye kepada seed tertinggi
  - Corak seed standard supaya seed 1 dan 2 hanya berjumpa di final
  - Terap `best_of` per round
  - Ujian unit: 16 entrant memberi 15 match; 11 entrant memberi 5 bye; seed 1 lawan seed terendah
  - _Requirements: 6.2, 6.3, 6.8_

- [x] 18. `GroupStageGenerator` dan pasangan silang
  - Pembahagian ular ikut seed supaya kumpulan seimbang
  - Round robin dalam setiap kumpulan
  - Pasangan knockout menyilang kumpulan: A1 lawan B2, B1 lawan A2
  - Ujian unit: 8 entrant 2 kumpulan memberi 12 match kumpulan; sahkan tiada pasangan sekumpulan di separuh akhir
  - _Requirements: 6.4_

- [x] 19. `LobbyGenerator` untuk battle royale
  - Lobi maksimum 16 entrant, pembahagian ular ikut seed
  - `match_count` match setiap lobi
  - Peta diberi daripada rotasi dalam settings
  - Ujian unit: 32 entrant memberi 2 lobi dan 6 match; rotasi 3 match memberi Erangel, Miramar, Erangel
  - _Requirements: 6.6, 6.9_

- [x] 20. `HeatGenerator` untuk race dan judged
  - Satu match per heat memegang setiap entrant dalam heat itu
  - _Requirements: 6.7_

- [x] 21. `DoubleEliminationGenerator`
  - Upper bracket, lower bracket, grand final
  - Tolak lebih lapan entrant dan cadangkan Single Elimination dahulu
  - Ujian unit: 4 entrant memberi UB1, UB2, LB1, LB2, Grand Final
  - _Requirements: 6.5_

- [x] 22. Tab Stages dan aksi Generate Draw
  - Tambah peringkat dengan jenis, `advance_count`, `best_of` per round
  - Generate Draw di bawah `tournaments.matches.generate`
  - Tolak penjanaan kedua; minta undian sedia ada dibuang dahulu
  - Tolak pembuangan undian bila ada match yang sudah bermarkah, dan sebut berapa
  - Tukar status kejohanan ke `ongoing` selepas undian pertama dijana
  - Rakam siapa menjana dan bila
  - _Requirements: 6.11, 6.12, 6.14, 3.5, 15.2_

- [x] 23. Penjadualan dengan buffer
  - Beri `scheduled_at` kepada setiap match dijana, menggunakan buffer daripada settings
  - Setiap masa boleh diedit selepas itu
  - _Requirements: 6.10_

---

## Fasa 4 — Markah dan kedudukan

- [x] 24. Migration `tournament_match_entrants`, `tournament_proofs`, `tournament_standings`, dengan modelnya
  - `tournament_match_entrants`: `inputs` json, `points`, `component_points` json, `is_disqualified`
  - `tournament_standings`: `component_totals` json, `total_points`, `rank`, `is_disqualified`, `advances`
  - Unique `(tournament_stage_id, tournament_group_id, tournament_entrant_id)`
  - _Requirements: 8.1, 8.2_

- [x] 25. Skrin Matches dengan tiga tab
  - Tab `Scheduled · Awaiting Result · Completed`
  - Filter bar wajib memilih kejohanan; kejohanan yang dipandang sentiasa dipapar
  - Filter tambahan: peringkat, kumpulan
  - Lajur: match, peringkat, peserta, peta, masa, dan satu butang Enter Score
  - Keadaan kosong menyatakan fixture muncul selepas undian dijana
  - _Requirements: 1.7, 7.1, 16.3_

- [x] 26. Borang markah dijana daripada `inputs` profil
  - Satu Blade, satu gelung atas `inputs`, tiada cabang per sukan
  - Papar mata yang dikira sebelum disimpan
  - Boleh dikendalikan dengan papan kekunci sahaja; setiap medan berlabel; fokus menurun ikut senarai entrant
  - _Requirements: 7.2, 7.3, 7.4, 7.5, 7.6, 7.7, 7.13_

- [x] 27. Simpan markah dengan pengesahan
  - Placement mesti unik dalam satu match
  - Pemain hadir tidak boleh melebihi `squad_size`
  - Lampirkan bukti screenshot; tolak penutupan tanpa bukti bila settings menuntutnya
  - Rakam siapa memasukkan dan bila
  - Tulis keputusan dan kedudukan yang dikira semula dalam satu transaksi, dalam request yang sama
  - _Requirements: 7.9, 7.10, 7.11, 7.12, 7.14, 17.3, 17.4_

- [x] 28. `StandingsCalculator`
  - Kira daripada baris sumber, jangan tambah kaunter
  - Penalti dikira setiap match yang skuadnya kurang
  - Tie-break mengikut urutan dalam profil
  - Tanda seri sebenar selepas semua tie-break habis
  - Tanda `advances` untuk `advance_count` teratas
  - Entrant DQ kekal dalam jadual bertanda DQ
  - Ujian unit: tie-break WWCD kemudian placement kemudian kill; seri sebenar; penalti berbilang match
  - _Requirements: 8.1, 8.2, 8.4, 8.5, 8.6, 8.7, 8.8, 17.1, 17.2_

- [x] 29. Skrin Standings
  - Tab ialah peringkat kejohanan yang dipilih; tiada tab bar bila tiada peringkat
  - Lajur dibina daripada `components` profil
  - Garis pemisah pada had `advance_count`
  - Eksport CSV di bawah `tournaments.standings.export`
  - Keadaan kosong menyatakan kedudukan terisi bila markah dimasukkan
  - _Requirements: 8.3, 8.9, 8.10, 16.4_

- [x] 30. `StageAdvancer`
  - Bila peringkat tamat, majukan `advance_count` teratas setiap kumpulan ke peringkat berikut
  - Guna urutan tie-break yang sama
  - _Requirements: 6.13_

- [x] 31. Walkover, forfeit, DQ dan tarik diri
  - Walkover memajukan pihak yang hadir dan menyingkirkan yang tidak
  - Forfeit sebab lewat menggunakan had daripada settings
  - DQ seluruh kejohanan sebagai tindakan berasingan daripada DQ satu match
  - DQ seluruh kejohanan mengekalkan keputusan lalu, menanda DQ dalam kedudukan, dan memberi walkover pada match berbaki
  - Tarik diri dilayan sama dari sudut lawan
  - Sebab wajib bagi setiap satu, dengan rakaman siapa dan bila
  - Skuad 0 pemain menanda DQ match itu sahaja dan mengekalkan entrant aktif
  - _Requirements: 9.1, 9.2, 9.3, 9.4, 9.5, 9.6, 9.7, 9.8, 15.3_

- [x] 32. Pembetulan markah selepas match ditutup
  - Kira semula kedudukan dan siapa mara
  - Tolak bila ia mengubah siapa mara dari peringkat yang sudah tamat, dan minta peringkat kemudian dibuang dahulu
  - Tolak bila podium sudah diterbitkan, sampai ia ditarik semula
  - Rakam nilai lama, nilai baharu, siapa dan bila
  - _Requirements: 10.1, 10.2, 10.3, 10.4, 10.5, 15.1_

---

## Fasa 5 — Hall of Fame dan halaman awam

- [x] 33. Migration `tournament_champions` dan modelnya
  - `display_name`, `total_points`, `component_totals` disalin, bukan dirujuk
  - Unique `(tournament_id, rank)`
  - _Requirements: 11.3, 11.4_

- [x] 34. Skrin Hall of Fame dengan dua tab
  - Tab `Awaiting Publish · Published`
  - Senaraikan kejohanan yang setiap matchnya sudah selesai
  - Dialog Publish menyenaraikan podium yang akan dibekukan dan menyatakan pembetulan selepas itu tidak mengubahnya
  - Publish dan Unpublish di bawah `tournaments.halloffame.publish`, dengan rakaman siapa dan bila
  - Keadaan kosong menyatakan kejohanan sampai ke sini bila semua match selesai
  - _Requirements: 11.1, 11.2, 11.5, 11.6, 11.7, 16.6, 15.4_

- [x] 35. Halaman awam `/hall-of-fame`
  - Baca `tournament_champions` yang dibekukan
  - Dikumpul ikut tahun dan event
  - Tiada maklumat peribadi selain nama pasukan atau peserta dan angka markah
  - Tiada pengesahan diperlukan
  - _Requirements: 12.1, 12.5, 12.6_

- [x] 36. Halaman awam `/events/{slug}/ranking`
  - Baca `tournament_standings`
  - Hormati tetapan `public_rankings_live`
  - Bila hidup, nyatakan berapa match daripada berapa sudah selesai
  - Bila tidak hidup, tiada apa dipapar sampai podium diterbitkan
  - _Requirements: 12.2, 12.3, 12.4, 12.5, 12.6_

---

## Fasa 6 — Settings, role dan pengesahan akhir

- [x] 37. Simpanan dan skrin Tournament Settings
  - Tab `Match Day · Maps · Public Display`
  - Match Day: buffer lalai 15 minit, had lewat 10 minit, `best_of` lalai per round, bukti wajib atau tidak
  - Maps: kolam peta per permainan dan rotasi
  - Public Display: ranking awam hidup atau tidak, peraturan peranti
  - Disalin ke kejohanan masa dicipta; salinan kejohanan boleh diedit semasa `setup`
  - _Requirements: 13.1, 13.2, 13.3, 13.4, 13.5, 13.6, 13.7, 13.8, 13.9, 13.10_

- [x] 38. Seed role `Referee`
  - `admin.access`, `tournaments.view`, `tournaments.matches.view`, `tournaments.matches.score`, `tournaments.standings.view` dan tiada yang lain
  - Ujian: sidebar Referee memapar tiga item Tournament sahaja
  - _Requirements: 14.5, 14.6_

- [x] 39. Semakan permission berganda dan penyembunyian kawalan
  - Setiap aksi yang dicapai melalui borang disemak pada route dan dalam controller
  - Kawalan tulis ditiadakan, bukan dipapar lalu gagal bila ditekan
  - Ujian: pengguna tanpa `tournaments.matches.score` ditolak walaupun menghantar borang terus
  - _Requirements: 14.1, 14.2, 14.3, 14.4, 14.7_

- [x] 40. Log audit menyeluruh
  - Masuk dan betulkan markah, jana dan buang undian, walkover dan forfeit dan DQ dan tarik diri, terbit dan tarik podium, cipta dan edit dan buang profil mata
  - _Requirements: 15.1, 15.2, 15.3, 15.4, 15.5_

- [x] 41. Ujian feature keserentakan
  - Dua kejohanan `ongoing` serentak dengan format berbeza
  - Simpan markah pada satu, sahkan kedudukan yang satu lagi tidak berubah sama sekali
  - Dua kejohanan atas event yang sama, sahkan entrant dan kedudukan berasingan
  - Ini ujian yang mengunci keperluan paling keras pengguna
  - _Requirements: 1.1, 1.2, 1.3, 1.4, 1.6_

- [x] 42. Pengesahan akhir dan pembersihan
  - Setiap enam skrin admin dan dua halaman awam memulangkan 200
  - Setiap keadaan kosong memapar teks yang betul
  - Tiada pautan atau butang yang menuju destinasi yang tidak wujud
  - Jalankan `npm run build`, kosongkan cache, buang setiap fail dan data ujian
  - _Requirements: 16.1, 16.7, 16.8, 16.9_

---

## Fasa 7 — Markah personal pemain

Ledger kedua yang berasingan sepenuhnya daripada ledger pasukan. Fasa ini tidak mengubah `StandingsCalculator`, `tournament_standings`, `tournament_champions` atau borang markah pasukan. Kalau salah satu daripadanya berubah, reka bentuk telah dilanggar.

Susunan sengaja: engine dan pengiraan dahulu supaya boleh diuji tanpa UI, baru borang.

- [x] 43. Refactor `ScoringEngine`: keluarkan `scoreComponents()`
  - Pindahkan isi `score()` ke `private scoreComponents(array $components, array $inputs)`
  - `score()` menghantar `$rule->components`; tambah `scorePlayer()` menghantar `$rule->player_components`
  - Tiada logik kira berubah
  - Sahkan 17 ujian unit `ScoringEngineTest` masih lulus tanpa diubah — ini yang membuktikan refactor selamat
  - _Requirements: 18.6, 18.15_

- [x] 44. Lajur `point_rules` untuk pemain
  - Migration tambah `track_players` string lalai `off`, `player_components` json, `player_inputs` json, `player_tiebreak` json
  - Cast ketiga-tiga json kepada array pada model `PointRule`
  - Kaedah `playerComponent(string $key)` dan `tracksPlayers()`
  - Lalai `off` untuk `kind` `race` dan `judged`
  - _Requirements: 18.3, 18.6, 18.7, 18.8, 18.28_

- [x] 45. Migration `tournament_match_players` dan modelnya
  - Digantung pada `tournament_match_entrant_id`, bukan pada match
  - `event_participant_id`, `took_part` boolean, `inputs` json, `points`, `component_points` json, `component_counts` json, `recorded_by`
  - Unique `(tournament_match_entrant_id, event_participant_id)`, index `(event_participant_id)`
  - Cascade delete daripada `tournament_match_entrants`
  - Ujian: buang undian membuang baris pemain sekali
  - _Requirements: 18.1, 18.11, 18.23_

- [x] 46. Seksyen Player Scoring dalam borang Point Rule
  - Pemilih `track_players`: Off · Optional · Required
  - Senarai `player_inputs` boleh tambah dan buang: kunci, label, jenis, wajib atau tidak
  - Senarai `player_components` guna lima jenis yang sama seperti komponen pasukan
  - Susunan `player_tiebreak` sebagai medan boleh susun, disahkan merujuk komponen pemain yang wujud
  - Seluruh seksyen tersembunyi bila `track_players` ialah `off`
  - _Requirements: 18.3, 18.6, 18.7, 18.8, 18.9_

- [x] 47. Kemas kini `PointRuleSeeder` untuk profil pemain PMPL
  - `kills` per_unit value 1, `knocks` per_unit value 0, `damage` per_unit value 0
  - `player_inputs`: kills wajib, knocks dan damage optional
  - `player_tiebreak` `["kills","damage","knocks"]`
  - `track_players` kekal `optional`, bukan `required`
  - Ujian unit: pemain 5 kill 3 knock 1420 damage memberi 5 mata dengan count kills 5 dan damage 1420
  - _Requirements: 18.6, 18.7, 18.8, 18.15_

- [x] 48. Baris pemain boleh buka-tutup dalam borang markah
  - Chevron per entrant, tertutup secara lalai, satu dibuka pada satu masa
  - Senaraikan `event_participants` berperanan `player` bagi pendaftaran entrant itu sahaja
  - Checkbox `took_part` dan satu medan per `player_inputs`
  - Papar jumlah setiap input pemain bersebelahan angka pasukan sebagai maklumat sahaja, tanpa amaran bila tidak sama
  - Butang `guna untuk Players` menaip kiraan kehadiran ke medan `players_present`, dan medan itu kekal boleh ditulis semula
  - Seluruh blok tidak dirender bila `track_players` ialah `off`
  - Boleh dikendalikan papan kekunci, setiap medan berlabel
  - _Requirements: 18.4, 18.10, 18.11, 18.12, 18.13, 18.14, 18.16_

- [x] 49. Simpan markah pemain dalam `MatchController`
  - Kira mata setiap pemain melalui `scorePlayer()`, jangan terima jumlah yang ditaip
  - Tolak bila pemain sudah direkod di bawah entrant lain dalam kejohanan sama, dan namakan entrant itu
  - Bila `track_players` ialah `required`, tolak penutupan match sampai setiap pemain yang menyertai ada angka
  - Tulis dalam transaksi yang sama seperti markah pasukan
  - Rakam `recorded_by`
  - Ujian: markah pasukan mesti tetap tersimpan walaupun setiap baris pemain dibiar kosong bila `optional`
  - _Requirements: 18.2, 18.5, 18.15, 18.19, 18.24_

- [x] 50. Migration `tournament_player_standings` dan `PlayerStandingsCalculator`
  - Dikira daripada baris sumber, tidak pernah ditambah
  - Per peringkat dan keseluruhan kejohanan, disusun ikut `player_tiebreak`
  - Tanda `entrant_is_disqualified`, kekalkan angka pemain
  - Ujian unit: tie-break kills kemudian damage; pemain daripada entrant DQ masih muncul bertanda
  - Ujian: `tournament_standings` pasukan tidak berubah satu baris pun selepas markah pemain disimpan — ini ujian yang mengunci pemisahan dua ledger
  - _Requirements: 18.1, 18.17, 18.18, 18.20_

- [x] 51. Tab Players pada skrin Standings
  - Tab muncul hanya bila `track_players` bukan `off`
  - Lajur dibina daripada `player_components`
  - Eksport CSV di bawah `tournaments.standings.export`
  - Keadaan kosong menyatakan markah pemain adalah optional dan di mana ia dihidupkan
  - _Requirements: 18.27_

- [x] 52. Migration `tournament_player_awards` dan penerbitan anugerah
  - Nama, IGN, nama entrant dan jumlah DISALIN masa terbit, bukan dirujuk
  - Boleh terbit dan tarik semula tanpa bergantung pada podium pasukan
  - Di bawah `tournaments.halloffame.publish`, dengan rakaman siapa dan bila
  - Bahagian `Player Awards` pada skrin Hall of Fame
  - _Requirements: 18.21, 18.22, 18.24_

- [x] 53. Leaderboard pemain pada halaman awam
  - Jadual kedua di bawah ranking pasukan pada `/events/{slug}/ranking`
  - Nama, IGN, nama pasukan dan angka markah sahaja
  - Tiada IC, alamat, telefon, emel atau tarikh lahir
  - Hormati tetapan `public_rankings_live` yang sama
  - Ujian: sahkan tiada medan peribadi keluar dalam HTML
  - _Requirements: 18.26_

- [x] 54. Audit dan pengesahan akhir Fasa 7
  - Log audit untuk masuk markah pemain, pembetulan, terbit dan tarik anugerah
  - Sahkan setiap skrin baharu memulangkan 200, termasuk bila `track_players` ialah `off`
  - Sahkan 48 ujian asal masih lulus
  - `npm run build`, kosongkan cache, buang semua data dan fail ujian
  - _Requirements: 18.25_

---

## Tambahan tidak dirancang, dijumpai semasa Fasa 7

- [x] 55. `Rescorer` — kira semula markah bila profil diedit
  - **Pepijat yang sudah ada sebelum Fasa 7.** `PointRuleController::update()` memberitahu operator "standings will be recalculated" dan meminta pengesahan, tetapi tidak pernah memanggil apa-apa pengiraan. Lebih dalam lagi: `StandingsCalculator` menjumlahkan `component_points` yang **sudah disimpan**, jadi walaupun ia dipanggil ia akan menjumlahkan nombor lama dan mendapat jawapan lama. Menukar nilai kill daripada 1 ke 2 tidak akan mengubah apa-apa.
  - Kelas baharu `app/Support/Tournament/Rescorer.php` memasukkan `inputs` mentah semula ke dalam engine sebelum kedudukan dibina. `inputs` ialah apa yang operator taip dan tidak pernah berubah; semua yang lain diterbitkan daripadanya. Itulah sebab keduanya disimpan dalam lajur berasingan.
  - Mengendalikan `race` melalui `scoreRace` kerana placement hanya bermakna berbanding seluruh heat
  - Melangkau fixture yang belum dimainkan, supaya markah kosong tidak menukar match `scheduled` menjadi `completed`
  - Kira semula kedua-dua ledger, masing-masing dengan senarai komponennya sendiri
  - Disahkan: nilai kill 1 ke 2 menukar 26 mata jadi 42 dan kembali 26 bila dipulihkan; nilai kill pemain 1 ke 3 menukar 5 jadi 15 tanpa menyentuh satu pun jumlah pasukan
  - _Requirements: 2.10, 8.1, 8.2, 18.1, 18.17_
