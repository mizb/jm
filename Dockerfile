FROM php:8.3-cli-alpine

WORKDIR /app

RUN apk add --no-cache \
        curl-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j"$(nproc)" curl gd mbstring \
    && mkdir -p /app/cache \
    && chown -R www-data:www-data /app

COPY --chown=www-data:www-data index.php probe.php README.md LICENSE ./

USER www-data

EXPOSE 8080

HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8080/?health=1') === false ? 1 : 0);"

CMD ["php", "-S", "0.0.0.0:8080", "index.php"]
