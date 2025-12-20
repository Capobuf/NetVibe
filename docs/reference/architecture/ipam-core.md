Core IPAM logic

Validazione e conversioni
- CIDR validator normalizza e suggerisce rete corretta (app/Rules/ValidCidr.php).
- IpConversionService: string⇄decimal, parse/toCidr, host count, membership, next-free IP; usato da Subnet e IpAddress mutator/accessor.
- Prefill IP libero sfrutta range decimali e ignora soft-delete.

Discovery attiva (ICMP/fping)
- `ScanSubnetJob` rispetta `discover_subnet`, usa FpingWrapper (`fping -a -g`, exit 3/4 fatal) e aggiorna IpAddress state=1 + last_seen; aggiorna `last_discovery`.
- Scheduler: console registra solo prune log; scansioni subnet scatenate via UI/automation.

Discovery ingest da agent (passivo)
- Endpoint `/api/v1/discovery/results` → job ProcessDiscoveryIngestJob → IngestScanResultsAction.
- Scope: subnets discover=true per agent/customer. MAC mismatch → DiscoveryEvent PENDING (MAC_MISMATCH). Orphan IP: tenta auto-import in subnet discover abilitata con lock; altrimenti DiscoveryEvent ORPHAN_IP. Creazioni approvate settano state=2, type Host.

Deep ingest (ARP/DHCP da Mikrotik job)
- Risultati agent (discovery_fast/deep) passano da ProcessAgentJobResultAction → IngestMikrotikArpAction.
- ARP/DHCP: normalizza MAC, mappa a subnet per customer, aggiorna/crea IP con tipo/stato/porta; crea subnet /24 da PVID+VLAN se manca; altrimenti DiscoveryEvent con conflict.
- ARP/DHCP: normalizza MAC, mappa a subnet per customer, aggiorna/crea IP con tipo/stato/porta.
	- Auto‑creazione subnet `/24` (Fase 1):
		- Quando durante l’ingest (ARP/DHCP) compare un IP `A.B.C.X` che non ricade in alcuna subnet esistente del tenant, il sistema crea automaticamente `A.B.C.0/24` con le seguenti proprietà:
			- `customer_id` = lo stesso del device / `scan_agent` che ha eseguito la discovery
			- `discover_subnet = true`
			- `scan_agent_id` = agent che ha eseguito la discovery
			- `created_by_discovery = true` (campo booleano)
		- Se la subnet `A.B.C.0/24` è presente in `SubnetBlacklist` per quel `customer_id`:
			- non viene creata automaticamente
			- non viene popolata dalla discovery (niente nuovi IP o aggiornamenti)
			- non genera IP o aggiornamenti

Parametri di discovery
- Spatie DiscoverySettings: scan interval default, polling, ICMP timeout/retry, log retention; gestibili da Filament ManageDiscoverySettings.
