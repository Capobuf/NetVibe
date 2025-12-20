<div class="space-y-4">
    <dl class="grid grid-cols-2 gap-4">
        <div>
            <dt class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Level</dt>
            <dd class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium
                    @if($record->level === 'INFO') bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300
                    @elseif($record->level === 'WARNING') bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300
                    @elseif($record->level === 'DEBUG') bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200
                    @else bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300
                    @endif">
                    {{ $record->level }}
                </span>
            </dd>
        </div>
        <div>
            <dt class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Time</dt>
            <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100">{{ $record->created_at->format('Y-m-d H:i:s') }}</dd>
        </div>
    </dl>

    <div>
        <dt class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Message</dt>
        <dd class="mt-1 text-sm text-gray-900 dark:text-gray-100 break-words">{{ $record->message }}</dd>
    </div>

    @if($record->context)
    <div>
        <dt class="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide">Context</dt>
        <dd class="mt-1">
            <pre class="p-3 bg-gray-50 dark:bg-gray-800 rounded-md text-xs overflow-x-auto">{{ json_encode($record->context, JSON_PRETTY_PRINT) }}</pre>
        </dd>
    </div>
    @endif
</div>
