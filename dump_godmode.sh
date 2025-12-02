#!/bin/bash
OUTPUT="FULL_PROJECT_CONTEXT.txt"
ROOT_DIR="/root/NetVibe"

# Pulisce output precedente
echo "--- BEGIN PROJECT DUMP ---" > "$OUTPUT"
echo "Generated: $(date)" >> "$OUTPUT"

# Trova tutti i file rilevanti escludendo spazzatura
# Includiamo: PHP, Blade, JS (custom), JSON (config), YAML, MD
find "$ROOT_DIR" -type f \
    -not -path "*/vendor/*" \
    -not -path "*/node_modules/*" \
    -not -path "*/.git/*" \
    -not -path "*/storage/*" \
    -not -path "*/public/*" \
    -not -name "*.log" \
    -not -name "composer.lock" \
    -not -name "package-lock.json" \
    \( -name "*.php" -o -name "*.blade.php" -o -name "*.js" -o -name "*.json" -o -name "*.yaml" -o -name "*.yml" -o -name "*.md" \) \
| sort | while read -r file; do
    
    REL_PATH=${file#$ROOT_DIR/}
    
    echo "FILE_START: $REL_PATH" >> "$OUTPUT"
    cat "$file" >> "$OUTPUT"
    echo -e "\nFILE_END: $REL_PATH" >> "$OUTPUT"
    echo "----------------------------------------" >> "$OUTPUT"
    
done

echo "--- END PROJECT DUMP ---" >> "$OUTPUT"
echo "Dump completato: $OUTPUT"