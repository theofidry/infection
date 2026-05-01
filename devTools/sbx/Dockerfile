FROM docker/sandbox-templates:codex-docker

ARG PHP_VERSION=8.4

# The base image runs as the non-root `agent` user by default.
# Switch to root for all system-level package installations.
USER root

# PHP
RUN --mount=type=cache,target=/var/cache/apt,sharing=locked \
    set -o errexit -o nounset -o pipefail \
    && apt-get update \
    && apt-get install -y --no-install-recommends \
        # CLI SAPI
        php${PHP_VERSION}-cli \
        # Extensions used by infection to not have to rely on polyfills
        php${PHP_VERSION}-mbstring \
        php${PHP_VERSION}-xml \
        php${PHP_VERSION}-zip \
        # Coverage driver for PHPUnit and Infection e2e coverage runs
        php${PHP_VERSION}-xdebug \
        # Provides phpize/php-config so PIE can target this PHP installation
        php${PHP_VERSION}-dev \
        # Parser generator required when PIE must build extensions from source
        bison \
        # Compiler toolchain required when PIE must build extensions from source
        build-essential \
        # CA bundle used by curl/PIE for HTTPS downloads
        ca-certificates \
        # Downloads the PIE PHAR during image build
        curl \
        # Network connectivity probe used for collector/debug checks
        netcat-openbsd \
        # Native dependency discovery used by extension builds
        pkg-config \
        # Lexer generator required when PIE must build extensions from source
        re2c \
    && rm -rf /tmp/*

COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer

# PIE + telemetry/coverage extensions
RUN set -o errexit -o nounset -o pipefail \
    && curl -fL --output /tmp/pie.phar https://github.com/php/pie/releases/latest/download/pie.phar \
    && mv /tmp/pie.phar /usr/local/bin/pie \
    && chmod +x /usr/local/bin/pie \
    && pie install -n \
        --with-php-config="/usr/bin/php-config${PHP_VERSION}" \
        --with-phpize-path="/usr/bin/phpize${PHP_VERSION}" \
        pie-extensions/grpc \
    && phpenmod -v "${PHP_VERSION}" xdebug \
    && printf '%s\n' 'xdebug.mode=coverage' >> "/etc/php/${PHP_VERSION}/mods-available/xdebug.ini" \
    && php -m | grep -Fx grpc \
    && php -m | grep -Fx xdebug \
    && php -r 'exit(ini_get("xdebug.mode") === "coverage" ? 0 : 1);'

ENV NO_PROXY="host.docker.internal,169.254.1.1,localhost,127.0.0.1,::1,gateway.docker.internal"
ENV no_proxy="$NO_PROXY"

# Docker Sandbox injects proxy variables at runtime and may replace NO_PROXY
# from the image. /etc/sandbox-persistent.sh is sourced by interactive sessions
# and agents started with `sbx run`, so restore host collector endpoints there.
RUN set -o errexit -o nounset -o pipefail; \
    { \
        printf '%s\n' ': "${NO_PROXY:=${no_proxy:-localhost,127.0.0.1,::1,gateway.docker.internal}}"'; \
        printf '%s\n' 'for no_proxy_host in host.docker.internal 169.254.1.1; do'; \
        printf '%s\n' '    case ",${NO_PROXY}," in'; \
        printf '%s\n' '        *",${no_proxy_host},"*) ;;'; \
        printf '%s\n' '        *) NO_PROXY="${no_proxy_host},${NO_PROXY}" ;;'; \
        printf '%s\n' '    esac'; \
        printf '%s\n' 'done'; \
        printf '%s\n' 'export NO_PROXY'; \
        printf '%s\n' 'export no_proxy="${NO_PROXY}"'; \
    } >> /etc/sandbox-persistent.sh

# Restore the default non-root user that the sandbox agent runs as.
USER agent
