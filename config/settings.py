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
    'unfold.contrib.forms',
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

TIME_ZONE = os.environ.get(
    'DJANGO_TIME_ZONE',
    os.environ.get('TZ', 'Europe/Rome'),
)

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
    'SITE_TITLE': _('NetVibe Admin'),
    'SITE_HEADER': 'Netvibe',
    'SITE_SUBHEADER': 'Network operations',
    'SITE_SYMBOL': 'NV',
    'COMMAND': {
        'search_models': True,
        'show_history': True,
    },
    'SIDEBAR': {
        'show_search': True,
        'command_search': True,
        'show_all_applications': True,
        'navigation': [
            {
                'title': _('Navigation'),
                'separator': True,
                'items': [
                    {
                        'title': _('Dashboard'),
                        'icon': 'dashboard',
                        'link': reverse_lazy('admin:index'),
                    },
                ],
            },
            {
                'title': _('Networks'),
                'collapsible': True,
                'items': [
                    {
                        'icon': 'tag',
                        'title': _('VLANs'),
                        'link': reverse_lazy('admin:ipam_vlan_changelist'),
                    },
                    {
                        'icon': 'lan',
                        'title': _('Subnets'),
                        'link': reverse_lazy('admin:ipam_subnet_changelist'),
                    },
                    {
                        'icon': 'dns',
                        'title': _('IP addresses'),
                        'link': reverse_lazy('admin:ipam_ipaddress_changelist'),
                    },
                    {
                        'icon': 'sell',
                        'title': _('Tags'),
                        'link': reverse_lazy('admin:ipam_tag_changelist'),
                    },
                    {
                        'icon': 'tune',
                        'title': _('IPAM settings'),
                        'link': reverse_lazy('admin:ipam_ipamsettings_changelist'),
                    },
                ],
            },
            {
                'title': _('Discovery'),
                'collapsible': True,
                'items': [
                    {
                        'icon': 'explore',
                        'title': _('Discovery overview'),
                        'link': reverse_lazy('admin:ipam_discovery_overview'),
                    },
                    {
                        'icon': 'my_location',
                        'title': _('MikroTik targets'),
                        'link': reverse_lazy('admin:ipam_mikrotiktarget_changelist'),
                    },
                    {
                        'icon': 'router',
                        'title': _('MikroTik devices'),
                        'link': reverse_lazy('admin:ipam_mikrotikdevice_changelist'),
                    },
                    {
                        'icon': 'travel_explore',
                        'title': _('Discovery runs'),
                        'link': reverse_lazy('admin:ipam_mikrotikdiscoveryrun_changelist'),
                    },
                    {
                        'icon': 'inventory_2',
                        'title': _('Review queue'),
                        'link': reverse_lazy('admin:ipam_reviewitem_changelist'),
                    },
                ],
            },
            {
                'title': _('System'),
                'collapsible': True,
                'items': [
                    {
                        'icon': 'schedule',
                        'title': _('Job runs'),
                        'link': reverse_lazy('admin:ipam_jobrun_changelist'),
                    },
                    {
                        'icon': 'task_alt',
                        'title': _('Task results'),
                        'link': reverse_lazy(
                            'admin:django_tasks_database_dbtaskresult_changelist'
                        ),
                    },
                    {
                        'icon': 'tune',
                        'title': _('MikroTik settings'),
                        'link': reverse_lazy(
                            'admin:ipam_mikrotikintegrationsettings_changelist'
                        ),
                    },
                ],
            },
        ],
    },
    'TABS': [],
}
