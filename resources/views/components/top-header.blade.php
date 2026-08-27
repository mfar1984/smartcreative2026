<div id="top-header">
    <div class="container mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex flex-col md:flex-row justify-between items-center py-1 text-xs">
            <!-- Date and Time Section -->
            <div class="datetime-display text-white">
                <span id="current-datetime"></span>
            </div>
            
            <!-- Contact Information Section -->
            <div class="contact-info flex items-center gap-4 mt-1 md:mt-0">
                <a href="mailto:event@smartcreative.my" class="flex items-center gap-2 text-white hover:text-blue-300 transition">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <span>event@smartcreative.my</span>
                </a>
                <span class="text-blue-400">•</span>
                <span class="flex items-center gap-2 text-white">
                    <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"/>
                    </svg>
                    <span>019-866 6898</span>
                </span>
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
function updateDateTime() {
    const now = new Date();
    const days = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    const months = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    
    const dayName = days[now.getDay()];
    const day = String(now.getDate()).padStart(2, '0');
    const month = months[now.getMonth()];
    const year = now.getFullYear();
    
    let hours = now.getHours();
    const minutes = String(now.getMinutes()).padStart(2, '0');
    const seconds = String(now.getSeconds()).padStart(2, '0');
    const ampm = hours >= 12 ? 'pm' : 'am';
    
    hours = hours % 12;
    hours = hours ? hours : 12;
    hours = String(hours).padStart(2, '0');
    
    const formatted = `${dayName}, ${day} ${month} ${year} • ${hours}:${minutes}:${seconds} ${ampm}`;
    document.getElementById('current-datetime').textContent = formatted;
}

updateDateTime();
setInterval(updateDateTime, 1000);

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
