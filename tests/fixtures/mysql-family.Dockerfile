# syntax=docker/dockerfile:1
FROM php:8.3-cli
RUN docker-php-ext-install pdo_mysql
WORKDIR /app
COPY <<'PHP' /app/index.php
<?php
try {
    $database = new PDO(
        'mysql:host='.getenv('DB_HOST').';dbname='.getenv('DB_DATABASE'),
        getenv('DB_USERNAME'),
        getenv('DB_PASSWORD'),
        [PDO::ATTR_TIMEOUT => 3],
    );
    $version = $database->query('SELECT VERSION()')->fetchColumn();
    if (is_string($version) && $version !== '') {
        $engine = stripos($version, 'mariadb') === false ? 'mysql' : 'mariadb';
        if ($engine === '@DATASTORE@') {
            echo 'shippercli-'.$engine.'-query-ok';
            return;
        }
    }
} catch (Throwable) {
    // Keep credentials and connection diagnostics out of the public probe response.
}
http_response_code(503);
PHP
EXPOSE 80
CMD ["php", "-d", "display_errors=0", "-d", "log_errors=0", "-S", "0.0.0.0:80", "-t", "/app"]
