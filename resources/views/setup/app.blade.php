@extends('layouts.setup')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-900">Application Configuration</h2>
        <p class="text-gray-600 mt-2">
            Configure the public URL and locale for this NetVibe instance. Queue driver and worker/scheduler must be configured on the host.
        </p>
    </div>

    @if(session('success'))
        <div class="bg-green-50 border border-green-200 rounded-lg p-4">
            <div class="flex">
                <svg class="w-6 h-6 text-green-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <p class="text-green-700">{{ session('success') }}</p>
            </div>
        </div>
    @endif

    @if($errors->any())
        <div class="bg-red-50 border border-red-200 rounded-lg p-4">
            <div class="flex">
                <svg class="w-6 h-6 text-red-600 mr-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div>
                    <h3 class="font-semibold text-red-900">Validation Errors</h3>
                    @foreach($errors->all() as $error)
                        <p class="text-sm text-red-700 mt-1">{{ $error }}</p>
                    @endforeach
                </div>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('setup.app.store') }}" class="space-y-6">
        @csrf

        <div>
            <label for="app_url" class="block text-sm font-medium text-gray-700 mb-2">
                Application URL
            </label>
            <input
                type="text"
                id="app_url"
                name="app_url"
                value="{{ old('app_url', $appUrl ?? '') }}"
                required
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent @error('app_url') border-red-500 @enderror"
            >
            @error('app_url')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-sm text-gray-500">
                This URL is used for links in notifications and agent callbacks.
            </p>
        </div>

        <div>
            <label for="locale" class="block text-sm font-medium text-gray-700 mb-2">
                Locale
            </label>
            <select
                id="locale"
                name="locale"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent @error('locale') border-red-500 @enderror"
            >
                <option value="en" @selected(old('locale', $locale ?? 'en') === 'en')>English</option>
                <option value="it" @selected(old('locale', $locale ?? 'en') === 'it')>Italiano</option>
            </select>
            @error('locale')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-sm text-gray-500">
                Controls the default UI language. You can change it later from the Settings panel.
            </p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
                Queue driver (read-only)
            </label>
            <div class="flex items-center justify-between px-4 py-2 border border-gray-200 rounded-lg bg-gray-50">
                <span class="font-mono text-sm text-gray-800">{{ $queueDriver }}</span>
                <span class="text-xs text-gray-500">
                    Configure via <code class="bg-gray-100 px-1 rounded">QUEUE_CONNECTION</code> in <code class="bg-gray-100 px-1 rounded">.env</code>.
                </span>
            </div>
            <p class="mt-1 text-sm text-gray-500">
                In production, <span class="font-mono">redis</span> is strongly recommended.
                With <span class="font-mono">sync</span>, discovery, export and backup will be disabled.
            </p>
        </div>

        <div class="flex justify-between pt-4">
            <a href="{{ route('setup.customer') }}" class="inline-flex items-center px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold rounded-lg transition duration-150">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path>
                </svg>
                Back
            </a>
            <button type="submit" class="inline-flex items-center px-6 py-3 bg-teal-600 hover:bg-teal-700 text-white font-semibold rounded-lg shadow-md transition duration-150">
                Finish Setup
                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
            </button>
        </div>
    </form>
</div>
@endsection

