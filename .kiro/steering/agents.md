# Panduan Coding untuk AI Agent

## Prinsip Utama

### 1. Baca dan Faham Arahan Dengan Teliti
- Jika diberi 10 arahan, SENARAIKAN semua 10 arahan terlebih dahulu
- Buat SATU PERSATU mengikut urutan
- JANGAN skip atau buat sebahagian sahaja
- JANGAN buat kerja melebih-lebih yang tidak diminta

### 2. Fokus Pada Apa Yang Diminta Sahaja
- Jika diminta tukar background header, HANYA tukar background header
- JANGAN tukar font color, submenu, atau elemen lain yang tidak disebut
- JANGAN assume apa yang user mahu
- Tanya jika tidak pasti

### 3. Semak Semua Komponen Sebelum Coding

Sebelum buat sebarang perubahan, SEMAK dahulu:

#### Laravel Backend
- **Controllers** - Logic dan flow
- **Models** - Relationships dan attributes
- **Migrations** - Database structure
- **Form Requests** - Validation rules
- **Routes** - Route definitions dan naming

#### Frontend
- **Blade Templates** - View structure
- **Components** - Reusable components
- **JavaScript** - Client-side logic
- **CSS/Tailwind** - Styling

### 4. Testing Selepas Perubahan
- Clear cache Laravel selepas perubahan: `php artisan view:clear`
- Test functionality yang diubah
- Pastikan tidak break functionality lain

## Standard Font Sizes untuk Website Ini

Gunakan saiz font ini sebagai default/regular:

### Header & Navigation
- **Menu items**: `text-sm` (14px)
- **Logo height**: `h-8` (32px)

### Hero Section
- **Main heading**: `text-4xl md:text-5xl lg:text-6xl`
- **Description text**: `text-base md:text-lg`
- **Buttons**: Font size standard dalam button

### Content Sections
- **Section headings**: `text-3xl md:text-4xl`
- **Body text**: `text-base` (16px)
- **Small text**: `text-sm` (14px)
- **Extra small**: `text-xs` (12px)

### Top Header
- **Date/time dan contact**: `text-xs` (12px)
- **Icons**: `w-3 h-3`

## Checklist Sebelum Submit Code

- [ ] Baca semua arahan dengan teliti
- [ ] Senaraikan semua task jika lebih dari 1
- [ ] Buat HANYA apa yang diminta
- [ ] Semak impak pada komponen lain
- [ ] Clear cache Laravel
- [ ] Test functionality

## Contoh Salah vs Betul

### ❌ SALAH
**Arahan**: "Tukar background header bila scroll"
**Action**: Tukar background header, font color, submenu color, logo, dan semua elemen

### ✅ BETUL
**Arahan**: "Tukar background header bila scroll"
**Action**: Tukar HANYA background header, tidak sentuh elemen lain

---

## Nota Penting

1. **JANGAN buat assumption** - Jika tidak pasti, TANYA
2. **FOKUS pada arahan** - Buat apa yang diminta sahaja
3. **SEMAK sebelum code** - Pastikan faham struktur kod sedia ada
4. **TEST selepas code** - Pastikan tidak break functionality lain

Ingat: Lebih baik buat sikit tapi betul, daripada buat banyak tapi salah.
