import django.core.validators
import django.db.models.deletion
from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ('ipam', '0002_enable_pgcrypto'),
    ]

    operations = [
        # --- MikroTikDevice ---
        migrations.CreateModel(
            name='MikroTikDevice',
            fields=[
                ('id', models.BigAutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('name', models.CharField(blank=True, help_text='Display name. Auto-populated from the device identity if left blank.', max_length=128, verbose_name='Name')),
                ('host', models.CharField(help_text='Hostname or IP address of the RouterOS device.', max_length=255, verbose_name='Host / IP')),
                ('port', models.PositiveIntegerField(default=443, verbose_name='Port')),
                ('scheme', models.CharField(default='https', help_text='http or https.', max_length=8, verbose_name='Scheme')),
                ('username', models.CharField(max_length=128, verbose_name='Username')),
                ('password_encrypted', models.BinaryField(editable=False, verbose_name='Password (encrypted)')),
                ('verify_tls', models.BooleanField(default=False, help_text='Enable strict TLS certificate verification.', verbose_name='Verify TLS')),
                ('enabled', models.BooleanField(default=True, verbose_name='Enabled')),
                ('sync_enabled', models.BooleanField(default=True, help_text='Enable automatic periodic synchronization.', verbose_name='Periodic sync')),
                ('sync_interval_minutes', models.PositiveIntegerField(default=15, validators=[django.core.validators.MinValueValidator(1)], verbose_name='Sync interval (minutes)')),
                ('import_vlans', models.BooleanField(default=True, verbose_name='Import VLANs')),
                ('import_subnets', models.BooleanField(default=True, verbose_name='Import subnets')),
                ('import_dhcp_leases', models.BooleanField(default=True, verbose_name='Import DHCP leases')),
                ('import_arp', models.BooleanField(default=True, verbose_name='Import ARP')),
                ('import_neighbors', models.BooleanField(default=True, verbose_name='Import neighbors')),
                ('ignore_dynamic_bridge_vlans', models.BooleanField(default=True, help_text='Skip bridge VLAN rows where dynamic=true.', verbose_name='Ignore dynamic bridge VLANs')),
                ('device_role', models.CharField(choices=[('router', 'Router'), ('switch', 'Switch'), ('unknown', 'Unknown')], default='unknown', max_length=16, verbose_name='Device role')),
                ('is_authoritative_for_l3', models.BooleanField(default=True, help_text='If true, sync will create/update subnets from this device.', verbose_name='Authoritative for L3')),
                ('is_authoritative_for_dhcp', models.BooleanField(default=True, help_text='If true, sync will import DHCP lease data from this device.', verbose_name='Authoritative for DHCP')),
                ('last_successful_sync_at', models.DateTimeField(blank=True, null=True, verbose_name='Last successful sync')),
                ('last_error', models.TextField(blank=True, verbose_name='Last error')),
                ('last_seen_identity', models.CharField(blank=True, max_length=128, verbose_name='Identity')),
                ('last_seen_version', models.CharField(blank=True, max_length=64, verbose_name='RouterOS version')),
                ('last_seen_board', models.CharField(blank=True, max_length=128, verbose_name='Board')),
                ('last_seen_platform', models.CharField(blank=True, max_length=128, verbose_name='Platform')),
            ],
            options={
                'verbose_name': 'MikroTik device',
                'verbose_name_plural': 'MikroTik devices',
                'ordering': ('name', 'host'),
            },
        ),
        # --- MikroTikSyncRun ---
        migrations.CreateModel(
            name='MikroTikSyncRun',
            fields=[
                ('id', models.BigAutoField(auto_created=True, primary_key=True, serialize=False, verbose_name='ID')),
                ('mode', models.CharField(choices=[('test', 'Test'), ('sync', 'Sync')], max_length=8, verbose_name='Mode')),
                ('status', models.CharField(choices=[('scheduled', 'Scheduled'), ('running', 'Running'), ('success', 'Success'), ('failed', 'Failed')], default='scheduled', max_length=16, verbose_name='Status')),
                ('scheduled_for', models.DateTimeField(verbose_name='Scheduled for')),
                ('started_at', models.DateTimeField(blank=True, null=True, verbose_name='Started at')),
                ('finished_at', models.DateTimeField(blank=True, null=True, verbose_name='Finished at')),
                ('trace_id', models.CharField(blank=True, db_index=True, max_length=64, verbose_name='Trace ID')),
                ('job_id', models.CharField(blank=True, max_length=64, verbose_name='Job ID')),
                ('vlans_created', models.PositiveIntegerField(default=0, verbose_name='VLANs created')),
                ('vlans_updated', models.PositiveIntegerField(default=0, verbose_name='VLANs updated')),
                ('subnets_created', models.PositiveIntegerField(default=0, verbose_name='Subnets created')),
                ('subnets_updated', models.PositiveIntegerField(default=0, verbose_name='Subnets updated')),
                ('ips_created', models.PositiveIntegerField(default=0, verbose_name='IPs created')),
                ('ips_updated', models.PositiveIntegerField(default=0, verbose_name='IPs updated')),
                ('warning_summary', models.JSONField(blank=True, default=list, verbose_name='Warnings')),
                ('observed_summary', models.JSONField(blank=True, default=dict, help_text='Normalized neighbor/ARP summary for diagnostics.', verbose_name='Observed summary')),
                ('error_message', models.TextField(blank=True, verbose_name='Error message')),
                ('task_ref', models.CharField(blank=True, db_index=True, max_length=64, verbose_name='Task reference')),
                ('device', models.ForeignKey(on_delete=django.db.models.deletion.CASCADE, related_name='sync_runs', to='ipam.mikrotikdevice', verbose_name='Device')),
            ],
            options={
                'verbose_name': 'MikroTik sync run',
                'verbose_name_plural': 'MikroTik sync runs',
                'ordering': ('-scheduled_for', '-pk'),
            },
        ),
        # --- VLAN provenance fields ---
        migrations.AddField(model_name='vlan', name='source_kind', field=models.CharField(choices=[('manual', 'Manual'), ('mikrotik', 'MikroTik')], default='manual', max_length=16, verbose_name='Source')),
        migrations.AddField(model_name='vlan', name='source_device', field=models.ForeignKey(blank=True, null=True, on_delete=django.db.models.deletion.SET_NULL, related_name='imported_vlans', to='ipam.mikrotikdevice', verbose_name='Source device')),
        migrations.AddField(model_name='vlan', name='source_remote_id', field=models.CharField(blank=True, default='', max_length=255, verbose_name='Source remote ID')),
        migrations.AddField(model_name='vlan', name='source_job_id', field=models.CharField(blank=True, default='', max_length=64, verbose_name='Source job ID')),
        migrations.AddField(model_name='vlan', name='source_trace_id', field=models.CharField(blank=True, default='', max_length=64, verbose_name='Source trace ID')),
        migrations.AddField(model_name='vlan', name='last_synced_at', field=models.DateTimeField(blank=True, null=True, verbose_name='Last synced at')),
        migrations.AddField(model_name='vlan', name='sync_state', field=models.CharField(blank=True, choices=[('live', 'Live'), ('orphaned', 'Orphaned'), ('conflict', 'Conflict')], default='live', max_length=16, verbose_name='Sync state')),
        migrations.AddField(model_name='vlan', name='integration_sync_enabled', field=models.BooleanField(default=True, help_text='Disable to stop updating this VLAN from the upstream device.', verbose_name='Integration sync enabled')),
        migrations.AddField(model_name='vlan', name='source_upstream_disabled', field=models.BooleanField(default=False, help_text='True when the upstream device reports this VLAN as disabled.', verbose_name='Upstream disabled')),
        migrations.AddField(model_name='vlan', name='source_bridge_name', field=models.CharField(blank=True, default='', help_text='Bridge name from the upstream device, if applicable.', max_length=128, verbose_name='Source bridge')),
        # --- Subnet provenance fields ---
        migrations.AddField(model_name='subnet', name='source_kind', field=models.CharField(choices=[('manual', 'Manual'), ('mikrotik', 'MikroTik')], default='manual', max_length=16, verbose_name='Source')),
        migrations.AddField(model_name='subnet', name='source_device', field=models.ForeignKey(blank=True, null=True, on_delete=django.db.models.deletion.SET_NULL, related_name='imported_subnets', to='ipam.mikrotikdevice', verbose_name='Source device')),
        migrations.AddField(model_name='subnet', name='source_remote_id', field=models.CharField(blank=True, default='', max_length=255, verbose_name='Source remote ID')),
        migrations.AddField(model_name='subnet', name='source_job_id', field=models.CharField(blank=True, default='', max_length=64, verbose_name='Source job ID')),
        migrations.AddField(model_name='subnet', name='source_trace_id', field=models.CharField(blank=True, default='', max_length=64, verbose_name='Source trace ID')),
        migrations.AddField(model_name='subnet', name='last_synced_at', field=models.DateTimeField(blank=True, null=True, verbose_name='Last synced at')),
        migrations.AddField(model_name='subnet', name='sync_state', field=models.CharField(blank=True, choices=[('live', 'Live'), ('orphaned', 'Orphaned'), ('conflict', 'Conflict')], default='live', max_length=16, verbose_name='Sync state')),
        migrations.AddField(model_name='subnet', name='integration_sync_enabled', field=models.BooleanField(default=True, help_text='Disable to stop updating this subnet from the upstream device.', verbose_name='Integration sync enabled')),
        migrations.AddField(model_name='subnet', name='source_upstream_disabled', field=models.BooleanField(default=False, help_text='True when the upstream device reports this subnet as disabled.', verbose_name='Upstream disabled')),
        migrations.AddField(model_name='subnet', name='source_upstream_invalid', field=models.BooleanField(default=False, help_text='True when the upstream device reports this subnet as invalid.', verbose_name='Upstream invalid')),
        migrations.AddField(model_name='subnet', name='source_interface_name', field=models.CharField(blank=True, default='', help_text='Interface name from the upstream device.', max_length=128, verbose_name='Source interface')),
        # --- IPAddress provenance + lease fields ---
        migrations.AddField(model_name='ipaddress', name='source_kind', field=models.CharField(choices=[('manual', 'Manual'), ('mikrotik', 'MikroTik')], default='manual', max_length=16, verbose_name='Source')),
        migrations.AddField(model_name='ipaddress', name='source_device', field=models.ForeignKey(blank=True, null=True, on_delete=django.db.models.deletion.SET_NULL, related_name='imported_ips', to='ipam.mikrotikdevice', verbose_name='Source device')),
        migrations.AddField(model_name='ipaddress', name='source_remote_id', field=models.CharField(blank=True, default='', max_length=255, verbose_name='Source remote ID')),
        migrations.AddField(model_name='ipaddress', name='source_job_id', field=models.CharField(blank=True, default='', max_length=64, verbose_name='Source job ID')),
        migrations.AddField(model_name='ipaddress', name='source_trace_id', field=models.CharField(blank=True, default='', max_length=64, verbose_name='Source trace ID')),
        migrations.AddField(model_name='ipaddress', name='source_observed_at', field=models.DateTimeField(blank=True, null=True, verbose_name='Source observed at')),
        migrations.AddField(model_name='ipaddress', name='last_synced_at', field=models.DateTimeField(blank=True, null=True, verbose_name='Last synced at')),
        migrations.AddField(model_name='ipaddress', name='sync_state', field=models.CharField(blank=True, choices=[('live', 'Live'), ('orphaned', 'Orphaned'), ('conflict', 'Conflict')], default='live', max_length=16, verbose_name='Sync state')),
        migrations.AddField(model_name='ipaddress', name='lease_kind', field=models.CharField(choices=[('none', 'None'), ('dhcp_dynamic', 'DHCP dynamic'), ('dhcp_static', 'DHCP static')], default='none', max_length=16, verbose_name='Lease kind')),
        migrations.AddField(model_name='ipaddress', name='lease_status', field=models.CharField(blank=True, default='', help_text='Raw lease status from the upstream DHCP server.', max_length=32, verbose_name='Lease status')),
        migrations.AddField(model_name='ipaddress', name='lease_server_name', field=models.CharField(blank=True, default='', max_length=128, verbose_name='DHCP server')),
    ]
