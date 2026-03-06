"""Enable the pgcrypto PostgreSQL extension.

MikroTik device credentials are stored encrypted at rest using
pgp_sym_encrypt / pgp_sym_decrypt from pgcrypto.  This migration
must run before any model migration that relies on those functions.
"""

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
