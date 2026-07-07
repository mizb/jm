FROM php:8.3-cli-alpine

WORKDIR /app
ENV JM_API_VERSION=2026.07.07.3

RUN apk add --no-cache \
        curl-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        libwebp-dev \
        oniguruma-dev \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" curl gd mbstring \
    && pecl install apcu \
    && docker-php-ext-enable apcu \
    && apk del .build-deps \
    && chown -R www-data:www-data /app

COPY --chown=www-data:www-data index.php probe.php README.md LICENSE ./
COPY --chown=www-data:www-data docker-entrypoint.sh ./
RUN chmod +x /app/docker-entrypoint.sh

USER www-data

EXPOSE 8088

HEALTHCHECK --interval=30s --timeout=10s --start-period=15s --retries=3 \
    CMD php -r "exit(@file_get_contents('http://127.0.0.1:8088/?health=1') === false ? 1 : 0);"

CMD ["/app/docker-entrypoint.sh"]
