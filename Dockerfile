FROM python:3.12-slim

WORKDIR /app

# libpq-dev and gcc are required to build and link psycopg against PostgreSQL client libraries.
RUN apt-get update \
    && apt-get install --no-install-recommends -y gcc libpq-dev \
    && rm -rf /var/lib/apt/lists/*

COPY requirements.txt requirements-dev.txt ./

RUN pip install --no-cache-dir -r requirements.txt -r requirements-dev.txt

COPY . /app

