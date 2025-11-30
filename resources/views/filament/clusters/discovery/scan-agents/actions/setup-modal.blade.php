<div x-data="{
    apiKey: @entangle('tempNewKey'),
    apiUrl: '{{ config('app.url') }}/api',
    generating: false,
    copyToClipboard(text) {
        window.navigator.clipboard.writeText(text);
        $wire.call('notify', 'Copied to clipboard!');
    }
}">
    <div class="space-y-4">
        <!-- API URL Display -->
        <div>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">API URL</label>
            <div class="mt-1 flex rounded-md shadow-sm">
                <input 
                    type="text" 
                    readonly 
                    :value="apiUrl"
                    class="flex-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500 sm:text-sm"
                />
                <button 
                    type="button"
                    @click="copyToClipboard(apiUrl)"
                    class="ml-2 inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
                >
                    <x-filament::icon icon="heroicon-o-clipboard" class="h-4 w-4" />
                </button>
            </div>
        </div>

        <!-- Generate Key Button -->
        <div x-show="!apiKey">
            <button 
                type="button"
                wire:click="generateAgentKey"
                wire:loading.attr="disabled"
                class="w-full inline-flex justify-center items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-warning-600 hover:bg-warning-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-warning-500"
            >
                <x-filament::icon icon="heroicon-o-key" class="h-5 w-5 mr-2" />
                <span wire:loading.remove>Generate New API Key</span>
                <span wire:loading>Generating...</span>
            </button>
            <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                ⚠️ This will revoke any existing keys for this agent.
            </p>
        </div>

        <!-- API Key Display (After Generation) -->
        <div x-show="apiKey" x-cloak>
            <label class="text-sm font-medium text-gray-700 dark:text-gray-300">API Key (Copy Now!)</label>
            <div class="mt-1 flex rounded-md shadow-sm">
                <input 
                    type="text" 
                    readonly 
                    :value="apiKey"
                    class="flex-1 block w-full rounded-md border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-200 focus:border-primary-500 focus:ring-primary-500 sm:text-sm font-mono"
                />
                <button 
                    type="button"
                    @click="copyToClipboard(apiKey)"
                    class="ml-2 inline-flex items-center px-3 py-2 border border-gray-300 dark:border-gray-600 shadow-sm text-sm font-medium rounded-md text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-800 hover:bg-gray-50 dark:hover:bg-gray-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary-500"
                >
                    <x-filament::icon icon="heroicon-o-clipboard" class="h-4 w-4" />
                </button>
            </div>
            <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">
                ⚠️ Save this key now! It will not be shown again.
            </p>
        </div>

        <!-- Docker Compose Configuration (After Generation) -->
        <div x-show="apiKey" x-cloak>
            <div class="flex items-center justify-between mb-2">
                <label class="text-sm font-medium text-gray-700 dark:text-gray-300">Docker Compose Configuration</label>
                <button 
                    type="button"
                    @click="copyToClipboard(`IPAM_AGENT_API_KEY=${apiKey}\nIPAM_AGENT_API_URL=${apiUrl}`)"
                    class="inline-flex items-center px-2 py-1 text-xs font-medium rounded-md text-gray-700 dark:text-gray-300 bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600"
                >
                    <x-filament::icon icon="heroicon-o-clipboard" class="h-3 w-3 mr-1" />
                    Copy ENV
                </button>
            </div>
            <pre class="bg-gray-900 text-gray-100 p-4 rounded-md overflow-x-auto text-xs"><code>cd docker/remote-agent

# Create .env file with your credentials
cat > .env << EOF
IPAM_AGENT_API_KEY=<span x-text="apiKey"></span>
IPAM_AGENT_API_URL=<span x-text="apiUrl"></span>
EOF

# Start the agent
docker-compose up -d

# View logs
docker-compose logs -f</code></pre>
        </div>
    </div>
</div>
