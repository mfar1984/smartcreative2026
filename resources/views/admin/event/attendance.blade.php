@extends('layouts.admin')

@section('title', 'Attendance')

@section('breadcrumb')
    <a href="{{ route('admin.dashboard') }}" class="hover:text-gray-700 transition">Dashboard</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span>Event</span>
    <span class="mx-1.5 text-gray-300">/</span>
    <a href="{{ route('admin.event.attendance') }}" class="hover:text-gray-700 transition">Attendance</a>
    <span class="mx-1.5 text-gray-300">/</span>
    <span class="font-semibold text-gray-700">{{ $definition['label'] }}</span>
@endsection

@section('content')
    @php
        $accents = ['attendance' => 'blue', 'player-change' => 'purple', 'present' => 'green', 'absent' => 'amber'];
        $filterInput = 'rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-900 bg-white focus:outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-500/40 transition';
    @endphp

    <x-admin.settings-shell
        title="Attendance"
        description="Check people in at the door, swap a player when the squad changes, and see who is still missing."
        :tabs="$tabs"
        :active-tab="$activeTab"
        route="admin.event.attendance">

        <x-admin.section-intro
            :title="$definition['label']"
            :description="$definition['description']"
            :icon="$definition['icon']"
            :accent="$accents[$activeTab] ?? 'blue'" />

        {{-- Result of the last counter action, and anything that went wrong. --}}
        @if (session('status'))
            <div role="status" class="flex items-start gap-3 bg-green-50 border border-green-200 rounded-lg p-4 mb-5">
                <svg class="w-5 h-5 shrink-0 text-green-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm font-semibold text-green-800">{{ session('status') }}</p>
            </div>
        @endif

        @if ($errors->any())
            <div role="alert" class="bg-red-50 border border-red-200 rounded-lg p-4 mb-5">
                <p class="text-sm font-bold text-red-900 mb-1">That could not be done</p>
                <ul class="text-sm text-red-800 space-y-0.5">
                    @foreach ($errors->all() as $message)
                        <li>{{ $message }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        @include('admin.event.partials.attendance-' . $activeTab)
    </x-admin.settings-shell>
@endsection
