#!/bin/bash

# Configurazione
OUTPUT="codebase_dump.txt"
PROJECT_DIR="/root/NetVibe"

# Pulisce il file precedente se esiste
if [ -f "$OUTPUT" ]; then
    rm "$OUTPUT"
fi

# Header del file di dump
echo "=== CODEBASE DUMP ===" >> "$OUTPUT"
echo "Generated on: $(date)" >> "$OUTPUT"
echo "Stack: Laravel 12 / Filament 4 / Docker" >> "$OUTPUT"
echo "Root: $PROJECT_DIR" >> "$OUTPUT"
echo "=====================" >> "$OUTPUT"
echo "" >> "$OUTPUT"

# Esecuzione della ricerca
# Escludiamo vendor, node_modules, .git, storage (che contiene log e cache) e public (asset compilati)
# Includiamo solo estensioni rilevanti: php, json, yaml, yml, md

echo "Inizio mappatura..."

find "$PROJECT_DIR" -type f \
    -not -path "*/vendor/*" \
    -not -path "*/node_modules/*" \
    -not -path "*/.git/*" \
    -not -path "*/storage/*" \
    -not -path "*/public/*" \
    -not -name "composer.lock" \
    -not -name "package-lock.json" \
    \( -name "*.php" -o -name "*.json" -o -name "*.yaml" -o -name "*.yml" -o -name "*.md" \) \
| sort | while read -r file; do
    
    # Calcola il percorso relativo per pulizia
    REL_PATH=${file#$PROJECT_DIR/}
    
    echo "Processing: $REL_PATH"
    
    echo "START_FILE: $REL_PATH" >> "$OUTPUT"
    echo "----------------------------------------" >> "$OUTPUT"
    cat "$file" >> "$OUTPUT"
    echo "" >> "$OUTPUT"
    echo "END_FILE: $REL_PATH" >> "$OUTPUT"
    echo "========================================" >> "$OUTPUT"
    echo "" >> "$OUTPUT"
done

echo "Mappatura completata! File salvato in: $OUTPUT"