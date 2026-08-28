@extends('layouts.admin')

@php
    $input = 'w-full rounded-lg border border-gray-300 px-3.5 py-2.5 text-sm text-gray-900 focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
@endphp

@section('title', 'Shop Settings')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Shop</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">Settings</span>
@endsection

@section('content')
    <x-admin.settings-shell
        title="Shop Settings"
        description="How the storefront behaves. Prices, stock and product copy live on each product."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.shop.settings">

        <form action="{{ route('admin.shop.settings.update', ['tab' => $activeTab]) }}" method="POST">
            @csrf
            @method('PUT')

            <x-admin.section-intro
                :title="$definition['intro']['title']"
                :description="$definition['intro']['description']"
                :icon="$definition['icon']" />

            {{-- Opening a shop with nothing in it puts an empty page on the live site,
                 so the count is stated next to the switch that does it rather than
                 discovered afterwards. --}}
            @if ($activeTab === 'storefront' && $activeProducts === 0)
                <div class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3.5 mb-5">
                    <x-admin.icon name="warning" class="w-5 h-5 shrink-0 mt-0.5 text-amber-600" />
                    <div class="text-sm text-amber-900">
                        <p class="font-semibold">No products are Active</p>
                        <p class="text-amber-800 mt-0.5">
                            Opening the shop now would show visitors an empty page. Set at least one
                            product to Active first.
                        </p>
                        <a href="{{ route('admin.shop.products') }}" class="inline-block font-semibold underline mt-1">
                            Go to Products
                        </a>
                    </div>
                </div>
            @endif

            <x-admin.panel :title="$definition['label']" :icon="$definition['icon']">
                @foreach ($definition['fields'] as $key => $field)
                    <x-admin.field-row
                        :label="$field['label']"
                        :help="$field['help'] ?? null"
                        :for="$field['type'] === 'toggle' ? null : $key"
                        :required="in_array('required', $field['rules'] ?? [], true)"
                        :error="$key">

                        @switch($field['type'])
                            @case('toggle')
                                <x-admin.toggle
                                    :name="$key"
                                    :id="$key"
                                    :checked="old($key, $values[$key] === '1')"
                                    :label="$field['label']"
                                    :disabled="! $canUpdate" />
                                @break

                            @case('textarea')
                                <textarea id="{{ $key }}" name="{{ $key }}" rows="3"
                                          @disabled(! $canUpdate)
                                          class="{{ $input }} resize-y">{{ old($key, $values[$key]) }}</textarea>
                                @break

                            @case('number')
                                <input type="number" id="{{ $key }}" name="{{ $key }}" step="1"
                                       value="{{ old($key, $values[$key]) }}"
                                       @disabled(! $canUpdate)
                                       class="{{ $input }} max-w-32 text-right tabular-nums">
                                @break

                            @default
                                <input type="text" id="{{ $key }}" name="{{ $key }}" maxlength="255"
                                       value="{{ old($key, $values[$key]) }}"
                                       @disabled(! $canUpdate)
                                       class="{{ $input }}">
                        @endswitch
                    </x-admin.field-row>
                @endforeach
            </x-admin.panel>

            {{-- Checkout is not built, so the shop cannot take a payment yet. Saying so
                 here keeps the screen honest instead of leaving somebody to discover
                 there is no Add to Cart button. --}}
            @if ($activeTab === 'storefront')
                <div class="flex items-start gap-3 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3.5 mt-5">
                    <x-admin.icon name="warning" class="w-5 h-5 shrink-0 mt-0.5 text-blue-600" />
                    <div class="text-sm text-blue-900">
                        <p class="font-semibold">There is no online checkout yet</p>
                        <p class="text-blue-800 mt-0.5">
                            The shop lists products and takes enquiries. It cannot take a payment,
                            so there are no shipping or tax settings here yet. The note above is
                            what tells a buyer how to order in the meantime.
                        </p>
                    </div>
                </div>
            @endif

            @if ($canUpdate)
                <div class="flex justify-end pt-5">
                    <button type="submit"
                            class="inline-flex items-center gap-2 bg-blue-600 text-white px-6 py-2.5 rounded-lg text-sm font-semibold hover:bg-blue-700 transition shadow-sm">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1-4l-4 4m0 0L8 3m4 4V3"/>
                        </svg>
                        Save {{ $definition['label'] }} Settings
                    </button>
                </div>
            @else
                <p class="text-sm text-gray-500 pt-5">
                    You can read these settings but not change them.
                </p>
            @endif
        </form>

    </x-admin.settings-shell>
@endsection
