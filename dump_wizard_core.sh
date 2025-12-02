#!/bin/bash
OUTPUT="wizard_core_dump.txt"
ROOT_DIR="/root/NetVibe"

echo "=== WIZARD & CORE LOGIC DUMP ===" > "$OUTPUT"
echo "Generated on: $(date)" >> "$OUTPUT"

# Funzione helper per stampare i file
dump_file() {
    if [ -f "$1" ]; then
        echo "START_FILE: $1" >> "$OUTPUT"
        echo "----------------------------------------" >> "$OUTPUT"
        cat "$1" >> "$OUTPUT"
        echo "" >> "$OUTPUT"
        echo "END_FILE: $1" >> "$OUTPUT"
        echo "========================================" >> "$OUTPUT"
    else
        echo "WARNING: File $1 not found"
    fi
}

echo "Dumping Critical Files..."

# 1. IL WIZARD E I SUOI MIDDLEWARE
dump_file "app/Livewire/SetupPage.php"
dump_file "app/Http/Middleware/EnsureAppIsInstalled.php"
dump_file "app/Services/EnvEditor.php"

# 2. CONFIGURAZIONE FILAMENT (Per vedere come registra i cluster)
dump_file "app/Providers/Filament/AdminPanelProvider.php"
dump_file "app/Providers/AppServiceProvider.php"

# 3. LE MIGRAZIONI CONFLITTUALI (Settings)
dump_file "database/migrations/2024_01_01_000001_create_settings_table.php"
dump_file "database/migrations/2025_11_29_133422_create_settings_table.php"

# 4. MODELLO USER (Per l'Admin creation)
dump_file "app/Models/User.php"

# 5. ROUTES
dump_file "routes/web.php"

echo "Dump completato in $OUTPUT"