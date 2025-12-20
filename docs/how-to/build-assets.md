# Build frontend assets

Lo stack runtime non include Node.js.

Build con container temporaneo:

```bash
docker run --rm \
  -v "$(pwd)":/app \
  -w /app \
  node:22-alpine \
  sh -c "npm ci && npm run build"
```

Output: `public/build/`.
