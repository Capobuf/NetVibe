@extends('layouts.setup')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-900">Pre-Flight System Check</h2>
        <p class="text-gray-600 mt-2">Verifying your server meets all requirements...</p>
    </div>

    <!-- Checks List -->
    <div class="space-y-3">
        @foreach($checks as $key => $check)
        <div class="flex items-center justify-between p-4 rounded-lg border {{ $check['passed'] ? 'bg-green-50 border-green-200' : 'bg-red-50 border-red-200' }}">
            <div class="flex items-center space-x-3">
                @if($check['passed'])
                <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                @else
                <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
                @endif
                <div>
                    <p class="font-medium {{ $check['passed'] ? 'text-green-900' : 'text-red-900' }}">
                        {{ $check['label'] }}
                    </p>
                    <p class="text-sm {{ $check['passed'] ? 'text-green-700' : 'text-red-700' }}">
                        {{ $check['message'] }}
                    </p>
                </div>
            </div>
        </div>
        @endforeach
    </div>

    <!-- Next Button -->
    <div class="flex justify-end pt-4">
        @if($allPassed)
        <a href="{{ route('setup.migrate') }}" class="inline-flex items-center px-6 py-3 bg-teal-600 hover:bg-teal-700 text-white font-semibold rounded-lg shadow-md transition duration-150">
            Continue to Database Setup
            <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
            </svg>
        </a>
        @else
        <button disabled class="inline-flex items-center px-6 py-3 bg-gray-300 text-gray-500 font-semibold rounded-lg shadow-md cursor-not-allowed">
            Fix Errors to Continue
        </button>
        @endif
    </div>
</div>
@endsection
