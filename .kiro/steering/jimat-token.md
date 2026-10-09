# Jimat Token — Wajib Ikut

Pemilik projek tak suka masa dan token dibazirkan. Peraturan ini mengatasi kebiasaan lain.

## Bercakap
- Jawapan pendek. Terus kepada apa yang dibuat dan apa yang perlu pemilik buat.
- Tiada penerangan panjang, tiada ulang semula apa yang dah dikatakan, tiada senarai pilihan yang tak diminta.
- Jangan tanya soalan yang jawapannya boleh didapati dengan membaca kod, borang atau database. Semak dulu, jangan tanya.
- Keputusan teknikal kecil: buat sendiri dan sebut dalam satu ayat. Tanya hanya untuk perkara yang memadam data, mengubah duit, atau boleh mengunci orang keluar dari login.

## Bekerja
- Terus tulis kod. Jangan buat fasa design, plan atau review berulang kali.
- Satu coder untuk satu kerja. Review berasingan hanya untuk perubahan duit, bayaran atau login.
- Jangan tulis fail .md (plan, design, report, verification) melainkan pemilik minta.
- Bina hanya apa yang diminta. Tiada fallback, tetapan tambahan atau pengesahan tambahan untuk perkara yang dah disahkan berfungsi di server.
- Brief kepada sub-agent ringkas dan tepat. Jangan suruh sub-agent menyiasat semula fakta yang dah diketahui.
- Jangan semak status workflow berulang kali. Tunggu notifikasi siap.

## Sebelum Commit
- Sync ke smartcreative-production ikut SEMUA folder (app, resources, database, routes, tests, config, bootstrap, public/build) dan fail root yang berubah, kemudian pastikan tiada fail yang masih berbeza. Jangan guna senarai folder yang ditaip sendiri.
- Push ke kedua-dua branch: main dan feature/grouping-registration-radio.
