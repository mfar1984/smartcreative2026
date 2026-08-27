@extends('layouts.admin')

@section('title', 'Point Rules')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Tournament</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $intro['label'] }}</span>
@endsection

@section('content')
    @php
        use App\Models\PointRule;

        $head = 'px-5 py-3 text-xs font-bold uppercase tracking-wide text-gray-500';
    @endphp

    <x-admin.settings-shell
        title="Point Rules"
        description="Scoring you expect to reuse. A tournament picks one rather than carrying its own numbers."
        :tabs="$tabs"
        :active-tab="$activeTab"
        :route="$route">

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="flex flex-wrap items-start justify-between gap-4 mb-5">
            <x-admin.section-intro
                :title="$intro['title']"
                :description="$intro['description']"
                :icon="$intro['icon']"
                :accent="$intro['accent']"
                class="mb-0" />

            @if ($canCreate)
                <a href="{{ route('admin.tournaments.rules.create', ['kind' => $activeTab]) }}"
                   class="inline-flex items-center gap-2 rounded-lg border border-blue-600 bg-blue-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-blue-700 transition shadow-sm shrink-0">
                    <x-admin.icon name="plus" class="w-4 h-4" />
                    New Point Rule
                </a>
            @endif
        </div>

        <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
            <x-admin.filter-bar
                :action="route('admin.tournaments.rules')"
                :reset="$isFiltered ? route('admin.tournaments.rules', ['tab' => $activeTab]) : null">

                <input type="hidden" name="tab" value="{{ $activeTab }}">

                <div class="relative flex-1 min-w-56">
                    <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                        <x-admin.icon name="search" class="w-4 h-4" />
                    </span>
                    <label for="q" class="sr-only">Search point rules</label>
                    <input type="search" id="q" name="q" value="{{ $search }}"
                           placeholder="Search by name..."
                           class="w-full rounded-lg border border-gray-300 pl-9 pr-3 py-2 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition">
                </div>
            </x-admin.filter-bar>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-50 text-left">
                        <tr>
                            <th scope="col" class="{{ $head }}">Name</th>
                            <th scope="col" class="{{ $head }}">Scoring</th>
                            <th scope="col" class="{{ $head }}">Tie-break</th>
                            <th scope="col" class="{{ $head }} text-right">In Use</th>
                            @if ($canUpdate || $canDelete)
                                <th scope="col" class="{{ $head }} text-center">Actions</th>
                            @endif
                        </tr>
                    </thead>

                    <tbody class="divide-y divide-gray-100">
                        @forelse ($rules as $rule)
                            @php
                                $placement = $rule->component('placement')['values'] ?? [];
                                $kills = $rule->component('kills')['value'] ?? null;
                                $penalty = $rule->component('squad_penalty')['values'] ?? [];
                                $judges = $rule->component('judges');
                            @endphp

                            <tr class="hover:bg-blue-50/40 align-top">
                                <td class="px-5 py-3">
                                    @if ($canUpdate)
                                        <a href="{{ route('admin.tournaments.rules.edit', $rule) }}"
                                           class="font-semibold text-blue-600 hover:underline">{{ $rule->name }}</a>
                                    @else
                                        <span class="font-semibold text-gray-900">{{ $rule->name }}</span>
                                    @endif

                                    @unless ($rule->is_active)
                                        <span class="ml-1 rounded bg-gray-100 px-1.5 py-0.5 text-xs font-semibold text-gray-600">Off</span>
                                    @endunless

                                    @if ($rule->squad_size)
                                        <span class="block text-xs text-gray-400 mt-0.5">Full squad {{ $rule->squad_size }}</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-xs text-gray-600">
                                    @if (filled($placement))
                                        <span class="block">1st place {{ $placement['1'] ?? 0 }} pts</span>
                                    @endif
                                    @if ($kills !== null)
                                        <span class="block">Each kill {{ $kills }} pt</span>
                                    @endif
                                    @if (filled($penalty))
                                        <span class="block">Short squad penalty set</span>
                                    @endif
                                    @if ($judges)
                                        <span class="block">{{ $rule->input('judges')['count'] ?? '?' }} judges,
                                            {{ \App\Models\PointRule::AGGREGATE_METHODS[$judges['method']] ?? $judges['method'] }}</span>
                                    @endif
                                </td>

                                <td class="px-5 py-3 text-xs text-gray-600">
                                    @forelse ($rule->tiebreak ?? [] as $index => $key)
                                        <span class="block">{{ $index + 1 }}. {{ Str::headline($key) }}</span>
                                    @empty
                                        <span class="text-gray-300">&mdash;</span>
                                    @endforelse
                                </td>

                                <td class="px-5 py-3 text-right tabular-nums text-gray-700">
                                    {{ $rule->tournaments_count }}
                                </td>

                                @if ($canUpdate || $canDelete)
                                    {{-- Icon buttons, matching the Roles and Users tables. The name is
                                         carried in title and aria-label rather than on screen, so a
                                         screen reader and a hover both say which rule is meant. --}}
                                    <td class="px-5 py-3 whitespace-nowrap">
                                        <div class="flex items-center justify-center gap-1">
                                            @if ($canUpdate)
                                                <a href="{{ route('admin.tournaments.rules.edit', $rule) }}"
                                                   class="p-1.5 rounded-lg text-amber-600 hover:bg-amber-50 transition"
                                                   title="Edit {{ $rule->name }}" aria-label="Edit {{ $rule->name }}">
                                                    <x-admin.icon name="pencil" class="w-4 h-4" />
                                                </a>
                                            @endif

                                            @if ($canDelete)
                                                @if ($rule->tournaments_count > 0)
                                                    {{-- A rule in use cannot be deleted, and the controller
                                                         refuses it. Saying so here is kinder than a button
                                                         that fails when pressed. --}}
                                                    <span class="p-1.5 rounded-lg text-gray-300 cursor-not-allowed"
                                                          title="{{ $rule->name }} scores {{ $rule->tournaments_count }} {{ Str::plural('tournament', $rule->tournaments_count) }}, so it cannot be deleted"
                                                          aria-label="{{ $rule->name }} cannot be deleted because it is in use">
                                                        <x-admin.icon name="trash" class="w-4 h-4" />
                                                    </span>
                                                @else
                                                    <form action="{{ route('admin.tournaments.rules.destroy', $rule) }}" method="POST"
                                                          onsubmit="return confirm('Delete {{ addslashes($rule->name) }}? This cannot be undone.');">
                                                        @csrf
                                                        @method('DELETE')
                                                        <button type="submit"
                                                                class="p-1.5 rounded-lg text-red-600 hover:bg-red-50 transition"
                                                                title="Delete {{ $rule->name }}" aria-label="Delete {{ $rule->name }}">
                                                            <x-admin.icon name="trash" class="w-4 h-4" />
                                                        </button>
                                                    </form>
                                                @endif
                                            @endif
                                        </div>
                                    </td>
                                @endif
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-5 py-12 text-center">
                                    <x-admin.icon name="sliders" class="w-10 h-10 mx-auto text-gray-300" />
                                    <p class="text-sm font-semibold text-gray-700 mt-3">
                                        {{ $isFiltered ? 'Nothing matches that search' : 'No point rules here yet' }}
                                    </p>
                                    <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                                        This is where the placement table, the value of a kill and the
                                        tie-break order are set, so they are never buried in code.
                                    </p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-5 py-3.5 border-t border-gray-200">
                @if ($rules->hasPages())
                    {{ $rules->links() }}
                @else
                    <p class="text-xs text-gray-500">
                        {{ $rules->total() }} {{ Str::plural('point rule', $rules->total()) }}
                    </p>
                @endif
            </div>
        </div>
    </x-admin.settings-shell>
@endsection
