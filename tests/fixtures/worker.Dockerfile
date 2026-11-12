FROM php:8.3-cli-alpine
WORKDIR /app
RUN printf '%s\n' 'ShipperCLI EasyPanel worker fixture web app' > index.html
RUN printf '%s\n' '<?php' 'if (array_slice($argv, 1) !== ["queue:work", "database", "--queue=default", "--sleep=30", "--tries=1", "--timeout=60"]) { exit(1); }' 'while (true) { echo "ShipperCLI EasyPanel worker command executed\n"; sleep(5); }' > artisan
EXPOSE 80
CMD ["php", "-S", "0.0.0.0:80"]
