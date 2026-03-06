"""Centralised pgcrypto helpers for reversible credential encryption.

All encrypt/decrypt SQL lives here so the rest of the codebase never
touches raw pgcrypto SQL directly.

Requirements:
  - PostgreSQL with the ``pgcrypto`` extension enabled
    (migration ``0002_enable_pgcrypto``).
  - The ``NETVIBE_PGCRYPTO_KEY`` environment variable must be set.
"""

import os

from django.core.exceptions import ImproperlyConfigured
from django.db import connection


def _get_encryption_key() -> str:
    """Return the pgcrypto symmetric key or fail loudly."""
    key = os.environ.get('NETVIBE_PGCRYPTO_KEY', '')
    if not key:
        raise ImproperlyConfigured(
            'NETVIBE_PGCRYPTO_KEY environment variable is required '
            'for credential encryption but is not set.'
        )
    return key


def encrypt_password(plaintext: str) -> bytes:
    """Encrypt *plaintext* with pgp_sym_encrypt and return raw bytes.

    The result is suitable for storage in a ``BinaryField``.
    """
    key = _get_encryption_key()
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT pgp_sym_encrypt(%s, %s)",
            [plaintext, key],
        )
        return cursor.fetchone()[0]


def decrypt_password(ciphertext) -> str:
    """Decrypt *ciphertext* (bytes or memoryview) and return the plaintext."""
    key = _get_encryption_key()
    # Django may hand us a memoryview from BinaryField; cast to bytes.
    if isinstance(ciphertext, memoryview):
        ciphertext = bytes(ciphertext)
    with connection.cursor() as cursor:
        cursor.execute(
            "SELECT pgp_sym_decrypt(%s, %s)",
            [ciphertext, key],
        )
        return cursor.fetchone()[0]
