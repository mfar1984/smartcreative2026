{{-- Shared status and error banners for every admin screen. --}}

@if (session('status'))
    <div role="alert" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
        <svg class="w-5 h-5 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
        </svg>
        <p class="text-sm text-green-800">{{ session('status') }}</p>
    </div>
@endif

@if (session('warning'))
    <div role="alert" class="flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-lg p-4 mb-6">
        <svg class="w-5 h-5 shrink-0 text-amber-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
        <p class="text-sm text-amber-800">{{ session('warning') }}</p>
    </div>
@endif

@if ($errors->any())
    <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
        <div class="flex items-start gap-3">
            <svg class="w-5 h-5 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
            <div>
                <p class="text-sm font-semibold text-red-800 mb-1">Please correct the following:</p>
                <ul class="list-disc list-inside space-y-0.5">
                    @foreach ($errors->all() as $error)
                        <li class="text-sm text-red-700">{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif
