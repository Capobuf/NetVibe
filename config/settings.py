import os
import sys
from pathlib import Path

from django.urls import reverse_lazy
from django.utils.translation import gettext_lazy as _

# Build paths inside the project like this: BASE_DIR / 'subdir'.
BASE_DIR = Path(__file__).resolve().parent.parent


# Quick-start development settings - unsuitable for production
# See https://docs.djangoproject.com/en/6.0/howto/deployment/checklist/

# SECURITY WARNING: keep the secret key used in production secret!
SECRET_KEY = os.environ.get('DJANGO_SECRET_KEY', 'change-me')

# SECURITY WARNING: don't run with debug turned on in production!
DEBUG = os.environ.get('DJANGO_DEBUG', '0') == '1'
RUNNING_TESTS = 'test' in sys.argv

ALLOWED_HOSTS = [
    host.strip()
    for host in os.environ.get('DJANGO_ALLOWED_HOSTS', '').split(',')
    if host.strip()
]


# Application definition

INSTALLED_APPS = [
    # Unfold must precede django.contrib.admin to override admin templates
    'unfold',
    'django.contrib.admin',
    'django.contrib.auth',
    'django.contrib.contenttypes',
    'django.contrib.sessions',
    'django.contrib.messages',
    'django.contrib.staticfiles',
    'django_tasks_db',
    'ipam.apps.IpamConfig',
]

if DEBUG and not RUNNING_TESTS:
    INSTALLED_APPS.append('debug_toolbar')

MIDDLEWARE = [
    'django.middleware.security.SecurityMiddleware',
    'debug_toolbar.middleware.DebugToolbarMiddleware'
    if DEBUG and not RUNNING_TESTS
    else None,
    'django.contrib.sessions.middleware.SessionMiddleware',
    'django.middleware.locale.LocaleMiddleware',
    'django.middleware.common.CommonMiddleware',
    'django.middleware.csrf.CsrfViewMiddleware',
    'django.contrib.auth.middleware.AuthenticationMiddleware',
    'django.contrib.messages.middleware.MessageMiddleware',
    'django.middleware.clickjacking.XFrameOptionsMiddleware',
]

MIDDLEWARE = [middleware for middleware in MIDDLEWARE if middleware]

ROOT_URLCONF = 'config.urls'

TEMPLATES = [
    {
        'BACKEND': 'django.template.backends.django.DjangoTemplates',
        'DIRS': [BASE_DIR / 'templates'],
        'APP_DIRS': True,
        'OPTIONS': {
            'context_processors': [
                'django.template.context_processors.request',
                'django.contrib.auth.context_processors.auth',
                'django.contrib.messages.context_processors.messages',
            ],
        },
    },
]

WSGI_APPLICATION = 'config.wsgi.application'


# Database
# https://docs.djangoproject.com/en/6.0/ref/settings/#databases

DATABASES = {
    'default': {
        'ENGINE': 'django.db.backends.postgresql',
        'NAME': os.environ.get('POSTGRES_DB', ''),
        'USER': os.environ.get('POSTGRES_USER', ''),
        'PASSWORD': os.environ.get('POSTGRES_PASSWORD', ''),
        'HOST': os.environ.get('DATABASE_HOST', 'db'),
        'PORT': os.environ.get('DATABASE_PORT', '5432'),
    }
}

if DEBUG and not RUNNING_TESTS:
    DEBUG_TOOLBAR_CONFIG = {
        'SHOW_TOOLBAR_CALLBACK': lambda request: True,
    }


# Password validation
# https://docs.djangoproject.com/en/6.0/ref/settings/#auth-password-validators

AUTH_PASSWORD_VALIDATORS = [
    {
        'NAME': 'django.contrib.auth.password_validation.UserAttributeSimilarityValidator',
    },
    {
        'NAME': 'django.contrib.auth.password_validation.MinimumLengthValidator',
    },
    {
        'NAME': 'django.contrib.auth.password_validation.CommonPasswordValidator',
    },
    {
        'NAME': 'django.contrib.auth.password_validation.NumericPasswordValidator',
    },
]


# Internationalization
# https://docs.djangoproject.com/en/6.0/topics/i18n/

LANGUAGE_CODE = 'en'

LANGUAGES = [
    ('en', _('English')),
    ('it', _('Italian')),
]

LOCALE_PATHS = [BASE_DIR / 'locale']

TIME_ZONE = 'UTC'

USE_I18N = True

USE_TZ = True

TASKS = {
    'default': {
        'BACKEND': 'django_tasks_db.DatabaseBackend',
    }
}


# Static files (CSS, JavaScript, Images)
# https://docs.djangoproject.com/en/6.0/howto/static-files/

STATIC_URL = 'static/'

UNFOLD = {
    'SIDEBAR': {
        'show_all_applications': True,
        'navigation': [],
    },
    'TABS': [
        {
            'models': ['ipam.subnet', 'ipam.vlan'],
            'items': [
                {
                    'title': _('VLANs'),
                    'link': reverse_lazy('admin:ipam_vlan_changelist'),
                },
                {
                    'title': _('Subnets'),
                    'link': reverse_lazy('admin:ipam_subnet_changelist'),
                },
            ],
        },
        {
            'models': [{'name': 'ipam.subnet', 'detail': True}],
            'items': [
                {
                    'title': _('VLANs'),
                    'link': reverse_lazy('admin:ipam_vlan_changelist'),
                },
                {
                    'title': _('Subnets'),
                    'link': reverse_lazy('admin:ipam_subnet_changelist'),
                },
                {
                    'title': _('Details'),
                    'link': '',
                    'inline': 'general',
                },
                {
                    'title': _('Child Subnets'),
                    'link': '',
                    'inline': 'children',
                },
                {
                    'title': _('Subnet IPs'),
                    'link': '',
                    'inline': 'ipaddress_set',
                },
            ],
        },
        {
            'models': [
                {'name': 'ipam.vlan', 'detail': True},
            ],
            'items': [
                {
                    'title': _('VLANs'),
                    'link': reverse_lazy('admin:ipam_vlan_changelist'),
                },
                {
                    'title': _('Subnets'),
                    'link': reverse_lazy('admin:ipam_subnet_changelist'),
                },
                {
                    'title': _('Details'),
                    'link': '',
                    'inline': 'general',
                },
            ],
        },
    ],
}
