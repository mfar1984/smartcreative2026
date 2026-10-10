{{--
    The Dashboard crumb, drawn only for somebody who may open the dashboard.

    The same call the sponsorship area already makes, and for the same reason: a
    monitoring account does not hold dashboard.view, so a Dashboard link on its
    screens is a link straight to a 403 — and the owner was explicit that a view-only
    observer should not be shown controls that cannot work.

    One partial rather than the condition repeated on each screen, so a screen added
    to a scoped role's navigation later inherits the right answer instead of the old
    one. Everybody who holds the permission sees exactly the crumb they saw before.
--}}
@if (auth()->user()?->hasPermission('dashboard.view'))
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
@endif
