# Implementation status (PDL ↔ code)

Questa tabella fotografa gli scostamenti tra PDL e implementazione attuale.

| Area | PDL | Code attuale | Stato |
|------|-----|-------------|------|
| Compose service names | `netvibe-web`, `netvibe-worker`, ... | Allineato in `compose.yml` | ✅ |
| Profiles obbligatori | Sì | Tutti i servizi sono sotto `profiles` | ✅ |
| Test DB dedicato | `netvibe_test` | `netvibe-test-db` + bootstrap `tests/Pest.php` | ✅ |
| Split worker (user vs low) | suggerito | 1 solo `queue:work` (discovery,default,low) | 🟡 |
| Webhook notifications | previsti | UI/settings presenti, invio non implementato | 🟡 |
| Activity log | previsto | package presente, uso non evidente | 🟡 |
| Debug endpoints | da proteggere | verificare `routes/web.php` | 🟡 |
