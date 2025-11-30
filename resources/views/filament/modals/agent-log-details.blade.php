<div class="space-y-4">
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Level</h3>
        <p class="mt-1">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                @if($record->level === 'INFO') bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300
                @elseif($record->level === 'WARNING') bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300
                @else bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300
                @endif">
                {{ $record->level }}
            </span>
        </p>
    </div>

    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Message</h3>
        <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->message }}</p>
    </div>

    @if($record->context)
    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Context</h3>
        <pre class="mt-1 p-3 bg-gray-50 dark:bg-gray-800 rounded-md text-xs overflow-x-auto">{{ json_encode($record->context, JSON_PRETTY_PRINT) }}</pre>
    </div>
    @endif

    <div>
        <h3 class="text-sm font-medium text-gray-700 dark:text-gray-300">Timestamp</h3>
        <p class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->created_at->format('Y-m-d H:i:s') }}</p>
    </div>
</div>
