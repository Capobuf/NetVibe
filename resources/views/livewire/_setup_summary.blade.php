<div class="space-y-6">
    <div class="rounded-lg border border-gray-200 bg-white dark:border-white/10 dark:bg-gray-900">
        <dl class="divide-y divide-gray-200 dark:divide-white/10">
            <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Database Host</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">{{ $data['db_host'] }}</dd>
            </div>
            <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Database Name</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">{{ $data['db_database'] }}</dd>
            </div>
            <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Admin User</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">{{ $data['user_name'] }} ({{ $data['user_email'] }})</dd>
            </div>
            <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Site Name</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">{{ $data['site_name'] }}</dd>
            </div>
            <div class="px-4 py-3 sm:grid sm:grid-cols-3 sm:gap-4 sm:px-6">
                <dt class="text-sm font-medium text-gray-500 dark:text-gray-400">Demo Data</dt>
                <dd class="mt-1 text-sm text-gray-900 dark:text-white sm:col-span-2 sm:mt-0">
                    {{ $data['install_demo_data'] ? 'Yes' : 'No' }}
                </dd>
            </div>
        </dl>
    </div>

    <div class="rounded-md bg-primary-50 p-4 dark:bg-primary-500/10">
        <div class="flex">
            <div class="flex-shrink-0">
                <x-filament::icon icon="heroicon-m-information-circle" class="h-5 w-5 text-primary-400" />
            </div>
            <div class="ml-3">
                <h3 class="text-sm font-medium text-primary-800 dark:text-primary-200">Ready to Install</h3>
                <div class="mt-2 text-sm text-primary-700 dark:text-primary-300">
                    <p>Click "Finish & Install" below to complete the setup process. This will create your admin account and finalize the configuration.</p>
                </div>
            </div>
        </div>
    </div>
</div>
