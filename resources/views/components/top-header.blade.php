<div id="top-header">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row justify-between items-center py-1 text-xs">
            <!-- Date and Time Section -->
            <div class="datetime-display text-white">
                {{-- The chosen format descriptor rides on the element itself so the clock
                     has a single source of truth and so it is visible in the markup. The
                     JS reads these rather than hardcoding a 24h/12h shape. --}}
                <span id="current-datetime"
                      data-timezone="{{ \App\Support\LocalTime::zone() }}"
                      data-date-format="{{ \App\Support\LocalTime::dateFormat() }}"
                      data-time-format="{{ \App\Support\LocalTime::timeFormat() }}"></span>
            </div>
            
            <!-- Contact Information Section -->
            {{-- Email and telephone come from Settings > General Config > Contact
                 Details. GeneralSettings reads that group once per request, so the
                 footer below asking for the same values costs nothing extra. --}}
            <div class="contact-info flex items-center gap-4 mt-1 md:mt-0">
                <a href="mailto:{{ App\Support\GeneralSettings::contactEmail() }}" class="flex items-center gap-2 text-white hover:text-blue-300 transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <span>{{ App\Support\GeneralSettings::contactEmail() }}</span>
                </a>
                <span class="text-blue-400">•</span>
                <span class="flex items-center gap-2 text-white">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    <span>{{ App\Support\GeneralSettings::contactPhone() }}</span>
                </span>
            </div>
        </div>
    </div>
</div>

@push('scripts')
{{-- The clock ticks in the browser, so it cannot call LocalTime per frame. Instead
     PHP (which can read GeneralSettings) hands the JS the configured timezone and the
     chosen date_format / time_format as PHP date() token strings, and the formatter
     below renders those tokens itself. This keeps the public clock on the same
     timezone and the same chosen format as every server-rendered date. The weekday
     prefix is kept as it was. Only the tokens the General Config options can produce
     are supported (j d F M m Y y for dates; H h g i s A a for times). --}}
<script>
(function () {
    const clockEl = document.getElementById('current-datetime');
    const CLOCK_TZ = clockEl?.dataset.timezone || 'UTC';
    const CLOCK_DATE_FORMAT = clockEl?.dataset.dateFormat || 'd M Y';
    const CLOCK_TIME_FORMAT = clockEl?.dataset.timeFormat || 'g:i a';

    const MONTHS_FULL = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    const MONTHS_SHORT = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    const DAYS_SHORT = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    // Read "now" as wall-clock parts in the configured timezone, so the clock
    // matches the server's display zone rather than the viewer's own.
    function partsInZone(date) {
        const fmt = new Intl.DateTimeFormat('en-GB', {
            timeZone: CLOCK_TZ,
            year: 'numeric', month: 'numeric', day: 'numeric',
            hour: 'numeric', minute: 'numeric', second: 'numeric',
            weekday: 'short', hour12: false,
        });
        const parts = {};
        for (const p of fmt.formatToParts(date)) {
            parts[p.type] = p.value;
        }
        let hour = parseInt(parts.hour, 10);
        if (hour === 24) { hour = 0; } // some engines report 24 for midnight
        return {
            weekday: parts.weekday,
            day: parseInt(parts.day, 10),
            month: parseInt(parts.month, 10), // 1-12
            year: parseInt(parts.year, 10),
            hour: hour,
            minute: parseInt(parts.minute, 10),
            second: parseInt(parts.second, 10),
        };
    }

    const pad = (n) => String(n).padStart(2, '0');

    // Render a PHP date() format string using only the tokens the settings offer.
    function renderPhpFormat(format, t) {
        let out = '';
        for (let i = 0; i < format.length; i++) {
            const c = format[i];
            switch (c) {
                case 'j': out += String(t.day); break;
                case 'd': out += pad(t.day); break;
                case 'F': out += MONTHS_FULL[t.month - 1]; break;
                case 'M': out += MONTHS_SHORT[t.month - 1]; break;
                case 'm': out += pad(t.month); break;
                case 'Y': out += String(t.year); break;
                case 'y': out += pad(t.year % 100); break;
                case 'H': out += pad(t.hour); break;
                case 'h': out += pad(((t.hour % 12) || 12)); break;
                case 'g': out += String((t.hour % 12) || 12); break;
                case 'i': out += pad(t.minute); break;
                case 's': out += pad(t.second); break;
                case 'A': out += (t.hour >= 12 ? 'PM' : 'AM'); break;
                case 'a': out += (t.hour >= 12 ? 'pm' : 'am'); break;
                case '\\': i++; out += format[i] ?? ''; break;
                default: out += c;
            }
        }
        return out;
    }

    function updateDateTime() {
        if (! clockEl) { return; }
        const t = partsInZone(new Date());
        const datePart = renderPhpFormat(CLOCK_DATE_FORMAT, t);
        const timePart = renderPhpFormat(CLOCK_TIME_FORMAT, t);
        clockEl.textContent = `${t.weekday}, ${datePart} • ${timePart}`;
    }

    updateDateTime();
    setInterval(updateDateTime, 1000);
})();

// Show/hide top header on scroll
(function() {
    const topHeader = document.getElementById('top-header');
    const heroSection = document.querySelector('.hero-section');

    function syncTopHeader() {
        const scrollTop = window.pageYOffset || document.documentElement.scrollTop;
        const heroHeight = heroSection ? heroSection.offsetHeight : 300;

        // Show top header when scrolled past hero section
        topHeader.classList.toggle('is-visible', scrollTop > heroHeight - 100);
    }

    window.addEventListener('scroll', syncTopHeader, { passive: true });
    window.addEventListener('resize', syncTopHeader);
    syncTopHeader();
})();
</script>
@endpush
