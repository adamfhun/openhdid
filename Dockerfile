# syntax=docker/dockerfile:1
#
# OpenHDID container image: nginx mainline (with headers-more) + PHP-FPM + the
# application. One image serves every role; the command picks it:
#   web (nginx + PHP-FPM), worker [queue], scheduler, migrate, artisan <args>.
#
# Every base image is pinned by digest and every download by SHA-256. The
# maintainer script (platform.sh) updates the values below together; nginx
# tarballs are checked against the nginx.org PGP signatures before pinning.

ARG PHP_IMAGE=php:8.5.10-fpm-alpine3.24@sha256:ce1dcc234879feab0f309100e55e89e7cf21b9085e76de2a03a8240cec02751e
ARG NODE_IMAGE=node:24.21.0-alpine3.24@sha256:ebfe2f90462722a7a4de65e91990e97fe0d401c70e0e762c5b53302f905ec1c1
ARG COMPOSER_IMAGE=composer:2.10.3@sha256:a5f59b9fd2faf31218632be4809dc6491761085e8064c31dc3b84378c48c248b
ARG ALPINE_IMAGE=alpine:3.24@sha256:294b683cb724975bec92580e1e685676bd4b50bda910ddb8c51d4cabeaec77e6
ARG NGINX_VERSION=1.31.6
ARG NGINX_SHA256=974ed5298a5e398e008704ed5db284e655fc270c596493dbccada452448fc9f1
ARG HEADERS_MORE_VERSION=0.40
ARG HEADERS_MORE_SHA256=c14cb5e6c998590c209efbf77bd7637ce2cab02332e4192756a8af5b26ba4284
ARG PHPREDIS_VERSION=6.3.0
ARG PHPREDIS_SHA256=0d5141f634bd1db6c1ddcda053d25ecf2c4fc1c395430d534fd3f8d51dd7f0b5

FROM ${COMPOSER_IMAGE} AS composer

# --- nginx from source: only the modules the site uses, headers-more built in ---
FROM ${ALPINE_IMAGE} AS nginx
ARG NGINX_VERSION
ARG NGINX_SHA256
ARG HEADERS_MORE_VERSION
ARG HEADERS_MORE_SHA256
SHELL ["/bin/ash", "-eo", "pipefail", "-c"]
WORKDIR /src
RUN set -eux; \
    apk add --no-cache build-base linux-headers openssl-dev pcre2-dev zlib-dev; \
    wget -q -O nginx.tar.gz "https://nginx.org/download/nginx-${NGINX_VERSION}.tar.gz"; \
    echo "${NGINX_SHA256}  nginx.tar.gz" | sha256sum -c -; \
    wget -q -O headers-more.tar.gz "https://github.com/openresty/headers-more-nginx-module/archive/refs/tags/v${HEADERS_MORE_VERSION}.tar.gz"; \
    echo "${HEADERS_MORE_SHA256}  headers-more.tar.gz" | sha256sum -c -; \
    tar -xzf nginx.tar.gz; \
    tar -xzf headers-more.tar.gz
WORKDIR /src/nginx-${NGINX_VERSION}
RUN set -eux; \
    ./configure \
        --prefix=/etc/nginx \
        --sbin-path=/usr/sbin/nginx \
        --conf-path=/etc/nginx/nginx.conf \
        --pid-path=/tmp/openhdid/nginx/nginx.pid \
        --lock-path=/tmp/openhdid/nginx/nginx.lock \
        --error-log-path=stderr \
        --http-log-path=/dev/stdout \
        --http-client-body-temp-path=/tmp/openhdid/nginx/body \
        --http-fastcgi-temp-path=/tmp/openhdid/nginx/fastcgi \
        --with-threads \
        --with-file-aio \
        --with-pcre-jit \
        --with-http_ssl_module \
        --with-http_v2_module \
        --with-http_realip_module \
        --with-http_gzip_static_module \
        --without-http-cache \
        --without-http_autoindex_module \
        --without-http_browser_module \
        --without-http_empty_gif_module \
        --without-http_grpc_module \
        --without-http_memcached_module \
        --without-http_mirror_module \
        --without-http_proxy_module \
        --without-http_scgi_module \
        --without-http_split_clients_module \
        --without-http_ssi_module \
        --without-http_userid_module \
        --without-http_uwsgi_module \
        --add-module="/src/headers-more-nginx-module-${HEADERS_MORE_VERSION}" \
        --with-cc-opt='-O2 -fstack-protector-strong -D_FORTIFY_SOURCE=2 -fPIE -Wformat -Werror=format-security' \
        --with-ld-opt='-Wl,-z,relro -Wl,-z,now -pie'; \
    make -j"$(nproc)"; \
    make install; \
    strip /usr/sbin/nginx; \
    /usr/sbin/nginx -V

# --- PHP with only the extensions the application needs ---
FROM ${PHP_IMAGE} AS php
ARG PHPREDIS_VERSION
ARG PHPREDIS_SHA256
SHELL ["/bin/ash", "-eo", "pipefail", "-c"]
# The run-time libraries of the new extensions are listed one per word on purpose.
# hadolint ignore=SC2086
RUN set -eux; \
    apk add --no-cache icu-data-full; \
    apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev libxml2-dev libzip-dev linux-headers; \
    wget -q -O /tmp/redis.tgz "https://pecl.php.net/get/redis-${PHPREDIS_VERSION}.tgz"; \
    echo "${PHPREDIS_SHA256}  /tmp/redis.tgz" | sha256sum -c -; \
    docker-php-source extract; \
    mkdir -p /usr/src/php/ext/redis; \
    tar -xzf /tmp/redis.tgz -C /usr/src/php/ext/redis --strip-components=1; \
    docker-php-ext-install -j"$(nproc)" bcmath intl pcntl pdo_mysql redis soap zip; \
    docker-php-source delete; \
    runDeps="$( \
        scanelf --needed --nobanner --format '%n#p' --recursive /usr/local/lib/php/extensions \
            | tr ',' '\n' | sort -u \
            | awk 'system("[ -e /usr/local/lib/" $1 " ]") == 0 { next } { print "so:" $1 }' \
    )"; \
    apk add --no-cache $runDeps; \
    apk del --no-network .build-deps; \
    rm -rf /tmp/*

# --- Application code and production dependencies ---
FROM php AS app
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --prefer-dist --no-scripts --no-autoloader
COPY artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY lang ./lang
COPY public ./public
COPY resources/views ./resources/views
COPY routes ./routes
# Build-time boot needs no database or cache; runtime caches are written at start.
ENV CACHE_STORE=array SESSION_DRIVER=array LOG_CHANNEL=stderr
RUN set -eux; \
    mkdir -p storage/app/public storage/app/private storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache; \
    composer dump-autoload --no-dev --classmap-authoritative --no-interaction; \
    php artisan filament:optimize --no-interaction; \
    rm -rf storage/framework/views/* bootstrap/cache/config.php bootstrap/cache/routes-*.php bootstrap/cache/events.php

# --- Front-end build (the Filament theme reads its sources from vendor/) ---
FROM ${NODE_IMAGE} AS assets
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci --ignore-scripts --no-audit --no-fund
COPY vite.config.js ./
COPY resources ./resources
COPY --from=app /app/vendor/filament ./vendor/filament
COPY --from=app /app/app/Filament ./app/Filament
RUN set -eux; \
    npm run build; \
    find public/build -type f \( -name '*.js' -o -name '*.css' -o -name '*.svg' -o -name '*.json' \) -exec gzip -9 -k -n {} +

# --- Runtime image ---
FROM php AS runtime
SHELL ["/bin/ash", "-eo", "pipefail", "-c"]
ARG OPENHDID_VERSION=0.0.0-dev
ARG OPENHDID_SOURCE_URL=https://github.com/adamfhun/openhdid
RUN set -eux; \
    apk add --no-cache pcre2 tini; \
    apk del --no-network curl openssl tar xz; \
    rm -f /usr/src/php.tar.xz /usr/src/php.tar.xz.asc; \
    rm -f /usr/local/bin/docker-php-* /usr/local/bin/pear /usr/local/bin/peardev /usr/local/bin/pecl \
          /usr/local/bin/phpize /usr/local/bin/php-config /usr/local/bin/phar /usr/local/bin/phar.phar; \
    rm -rf /usr/local/include /usr/local/php /usr/local/etc/php-fpm.d/* /usr/local/etc/php/php.ini-*; \
    find /usr/local/lib/php -mindepth 1 -maxdepth 1 ! -name extensions -exec rm -rf {} +; \
    find / -xdev -perm /6000 -type f -exec chmod a-s {} + ; \
    mkdir -p /etc/nginx /usr/local/share/openhdid /etc/openhdid/tls /etc/openhdid/ca
COPY --from=nginx /usr/sbin/nginx /usr/sbin/nginx
COPY --from=nginx /etc/nginx/mime.types /etc/nginx/fastcgi_params /etc/nginx/
COPY docker/php.ini /usr/local/etc/php/conf.d/zz-openhdid.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/openhdid.conf
COPY docker/runtime.php docker/error.html /usr/local/share/openhdid/
COPY --chmod=0755 docker/openhdid /usr/local/bin/openhdid
COPY --from=app /app /app
COPY --from=assets /app/public/build /app/public/build
COPY LICENSE /app/LICENSE
# The PHP snippet is single-quoted on purpose: $e is a PHP variable, not a shell one.
# hadolint ignore=SC2016
RUN set -eux; \
    find /app/public/css /app/public/js -type f \( -name '*.js' -o -name '*.css' \) -exec gzip -9 -k -n {} + 2>/dev/null || true; \
    ln -s ../storage/app/public /app/public/storage; \
    chown -R www-data:www-data /app/storage; \
    nginx -v; \
    php -r 'foreach (["intl", "redis", "pdo_mysql", "pcntl", "soap", "zip", "bcmath", "Zend OPcache"] as $e) { if (! extension_loaded($e)) { fwrite(STDERR, "missing PHP extension: $e\n"); exit(1); } }'

ENV HOME=/tmp \
    APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr \
    APP_CONFIG_CACHE=/tmp/openhdid/cache/config.php \
    APP_ROUTES_CACHE=/tmp/openhdid/cache/routes.php \
    APP_EVENTS_CACHE=/tmp/openhdid/cache/events.php \
    VIEW_COMPILED_PATH=/tmp/openhdid/views \
    PHP_INI_SCAN_DIR=/usr/local/etc/php/conf.d:/tmp/openhdid/php \
    OPENHDID_FPM_PM=dynamic \
    OPENHDID_FPM_MAX_CHILDREN=8 \
    OPENHDID_FPM_START_SERVERS=2 \
    OPENHDID_FPM_MIN_SPARE_SERVERS=2 \
    OPENHDID_FPM_MAX_SPARE_SERVERS=4 \
    HDID_VERSION=${OPENHDID_VERSION} \
    HDID_SOURCE_URL=${OPENHDID_SOURCE_URL}/tree/v${OPENHDID_VERSION}

LABEL org.opencontainers.image.title="OpenHDID" \
      org.opencontainers.image.description="Caller identification for customer service desks: staff panel, client portal and call-center API." \
      org.opencontainers.image.version="${OPENHDID_VERSION}" \
      org.opencontainers.image.source="${OPENHDID_SOURCE_URL}" \
      org.opencontainers.image.url="${OPENHDID_SOURCE_URL}" \
      org.opencontainers.image.documentation="${OPENHDID_SOURCE_URL}/tree/v${OPENHDID_VERSION}/docs" \
      org.opencontainers.image.licenses="AGPL-3.0-or-later"

WORKDIR /app
USER 82:82
EXPOSE 8080 8443
HEALTHCHECK --interval=30s --timeout=5s --start-period=90s --retries=3 CMD ["/usr/local/bin/openhdid", "healthcheck"]
ENTRYPOINT ["/sbin/tini", "--", "/usr/local/bin/openhdid"]
CMD ["web"]
