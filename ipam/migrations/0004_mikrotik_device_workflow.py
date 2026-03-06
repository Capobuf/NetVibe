import django.db.models.deletion
from django.db import migrations, models


class Migration(migrations.Migration):

    dependencies = [
        ('ipam', '0003_mikrotik_integration'),
    ]

    operations = [
        migrations.AlterField(
            model_name='mikrotikdevice',
            name='scheme',
            field=models.CharField(
                choices=[('https', 'HTTPS'), ('http', 'HTTP')],
                default='https',
                help_text='Choose the protocol used by the RouterOS REST API.',
                max_length=8,
                verbose_name='Scheme',
            ),
        ),
        migrations.AlterField(
            model_name='mikrotiksyncrun',
            name='device',
            field=models.ForeignKey(
                blank=True,
                null=True,
                on_delete=django.db.models.deletion.SET_NULL,
                related_name='sync_runs',
                to='ipam.mikrotikdevice',
                verbose_name='Device',
            ),
        ),
    ]
