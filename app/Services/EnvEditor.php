<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

class EnvEditor
{
    /**
     * Get the value of a key from the .env file.
     */
    public function getValue(string $key): ?string
    {
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            return null;
        }

        $content = File::get($envPath);
        $pattern = "/^{$key}=(.*)$/m";

        if (preg_match($pattern, $content, $matches)) {
            $value = trim($matches[1]);
            // Remove quotes if present
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $value = substr($value, 1, -1);
            }
            return $value;
        }

        return null;
    }

    /**
     * Set the value of a key in the .env file.
     * If the key exists, it updates it. If not, it appends it.
     */
    public function setKey(string $key, string $value): void
    {
        $envPath = base_path('.env');

        if (!File::exists($envPath)) {
            File::put($envPath, '');
        }

        $content = File::get($envPath);
        
        // Escape value if it contains spaces or special characters
        if (preg_match('/\s/', $value) || str_contains($value, '#')) {
            $value = '"' . str_replace('"', '\"', $value) . '"';
        }

        $pattern = "/^{$key}=.*/m";
        $replacement = "{$key}={$value}";

        if (preg_match($pattern, $content)) {
            $content = preg_replace($pattern, $replacement, $content);
        } else {
            $content .= PHP_EOL . $replacement . PHP_EOL;
        }

        File::put($envPath, $content);
    }

    /**
     * Set multiple keys at once.
     */
    public function setKeys(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->setKey($key, (string) $value);
        }
    }
}
