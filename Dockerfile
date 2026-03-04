FROM python:3.12-slim

WORKDIR /app

# gettext provides makemessages/compilemessages, iputils-ping and iproute2 are
# required by the subnet scan worker, and libpq-dev/gcc build psycopg.
RUN apt-get update \
    && apt-get install --no-install-recommends -y gcc gettext iproute2 iputils-ping libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt requirements-dev.txt ./

RUN pip install --no-cache-dir -r requirements.txt -r requirements-dev.txt

COPY . /app
