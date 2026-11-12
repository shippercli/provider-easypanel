# syntax=docker/dockerfile:1
FROM python:3.12-slim
RUN pip install --no-cache-dir 'psycopg[binary]==3.2.9' 'pymongo==4.13.2' 'redis==6.2.0'
WORKDIR /app
COPY <<'PYTHON' /app/probe.py
import os
from http.server import BaseHTTPRequestHandler, HTTPServer

DATASTORE = '@DATASTORE@'


def query():
    host = os.environ['DB_HOST']
    username = os.environ['DB_USERNAME']
    password = os.environ['DB_PASSWORD']
    database = os.environ['DB_DATABASE']

    if DATASTORE == 'postgres':
        import psycopg

        with psycopg.connect(host=host, user=username, password=password,
                             dbname=database, connect_timeout=3) as connection:
            return connection.execute('SELECT 1').fetchone() == (1,)

    if DATASTORE == 'mongo':
        from pymongo import MongoClient

        with MongoClient(host, username=username, password=password,
                         authSource='admin', serverSelectionTimeoutMS=3000,
                         timeoutMS=3000) as connection:
            status = connection.admin.command('connectionStatus')
            if {'user': username, 'db': 'admin'} not in status['authInfo']['authenticatedUsers']:
                return False
            return connection[database].probe.find_one() is None

    if DATASTORE == 'redis':
        from redis import Redis

        with Redis(host=host, username=username, password=password, socket_connect_timeout=3,
                   socket_timeout=3) as connection:
            return connection.ping()

    return False


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        try:
            ready = query()
        except Exception:
            ready = False

        self.send_response(200 if ready else 503)
        self.end_headers()
        if ready:
            self.wfile.write(f'shippercli-{DATASTORE}-query-ok'.encode())


HTTPServer(('0.0.0.0', 80), Handler).serve_forever()
PYTHON
EXPOSE 80
CMD ["python", "/app/probe.py"]
