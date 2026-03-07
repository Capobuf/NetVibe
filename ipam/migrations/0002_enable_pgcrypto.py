"""Enable the pgcrypto PostgreSQL extension."""

from django.db import migrations


class Migration(migrations.Migration):

    dependencies = [
        ('ipam', '0001_initial'),
    ]

    operations = [
        migrations.RunSQL(
            sql='CREATE EXTENSION IF NOT EXISTS pgcrypto;',
            reverse_sql='DROP EXTENSION IF EXISTS pgcrypto;',
        ),
    ]
