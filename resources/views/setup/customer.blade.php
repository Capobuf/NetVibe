@extends('layouts.setup')

@section('content')
<div class="space-y-6">
    <div>
        <h2 class="text-2xl font-bold text-gray-900">Create First Customer & Location</h2>
        <p class="text-gray-600 mt-2">
            Define your first customer and a default location. You can add more customers and locations later from the Organization cluster.
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

    <form method="POST" action="{{ route('setup.customer.store') }}" class="space-y-6">
        @csrf

        <div>
            <label for="customer_name" class="block text-sm font-medium text-gray-700 mb-2">
                Customer name
            </label>
            <input
                type="text"
                id="customer_name"
                name="customer_name"
                value="{{ old('customer_name') }}"
                required
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent @error('customer_name') border-red-500 @enderror"
            >
            @error('customer_name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-sm text-gray-500">
                This will be the primary tenant for this NetVibe instance.
            </p>
        </div>

        <div>
            <label for="location_name" class="block text-sm font-medium text-gray-700 mb-2">
                Location name (optional)
            </label>
            <input
                type="text"
                id="location_name"
                name="location_name"
                value="{{ old('location_name') }}"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent @error('location_name') border-red-500 @enderror"
            >
            @error('location_name')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <p class="mt-1 text-sm text-gray-500">
                If left empty, a default location named “Default” will be created.
            </p>
        </div>

        <div>
            <label for="location_address" class="block text-sm font-medium text-gray-700 mb-2">
                Location address (optional)
            </label>
            <input
                type="text"
                id="location_address"
                name="location_address"
                value="{{ old('location_address') }}"
                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-teal-500 focus:border-transparent @error('location_address') border-red-500 @enderror"
            >
            @error('location_address')
                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
            @enderror
        </div>

        <div class="flex justify-between pt-4">
            <a href="{{ route('setup.user') }}" class="inline-flex items-center px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-700 font-semibold rounded-lg transition duration-150">
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 17l-5-5m0 0l5-5m-5 5h12"></path>
                </svg>
                Back
            </a>
            <button type="submit" class="inline-flex items-center px-6 py-3 bg-teal-600 hover:bg-teal-700 text-white font-semibold rounded-lg shadow-md transition duration-150">
                Save & Continue
                <svg class="w-5 h-5 ml-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
                </svg>
            </button>
        </div>
    </form>
</div>
@endsection

