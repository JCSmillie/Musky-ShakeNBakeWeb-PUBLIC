FROM php:8.2-apache-bookworm

ENV DEBIAN_FRONTEND=noninteractive
ENV TZ=America/New_York
ARG MOSBASIC_REPO="https://github.com/JCSmillie/MOSBasic.git"
ARG MOSBASIC_REF="main"
ENV MOSBASIC_HOME=/usr/local/SmillieWare/mosbasic

RUN apt-get update && apt-get install -y --no-install-recommends \
    bash \
    ca-certificates \
    curl \
    default-mysql-client \
    git \
    libcurl4-openssl-dev \
    libonig-dev \
    libsqlite3-dev \
    libzip-dev \
    procps \
    python3 \
    tzdata \
    unzip \
    zip \
  && docker-php-ext-install curl mbstring mysqli pdo_mysql pdo_sqlite \
  && a2enmod headers rewrite \
  && rm -rf /var/lib/apt/lists/*

WORKDIR /opt/musky
COPY . /opt/musky

RUN rm -rf "${MOSBASIC_HOME}" \
 && mkdir -p /usr/local/SmillieWare \
 && git clone --depth 1 --branch "${MOSBASIC_REF}" "${MOSBASIC_REPO}" "${MOSBASIC_HOME}" \
 && test -f "${MOSBASIC_HOME}/mosbasic" \
 && chmod +x "${MOSBASIC_HOME}/mosbasic" \
 && ln -sf "${MOSBASIC_HOME}/mosbasic" /usr/local/bin/mosbasic \
 && rm -rf "${MOSBASIC_HOME}/.git" \
 && mkdir -p /opt/musky/logs /var/lib/musky/Templates /var/lib/musky/MOSBasic-Drop /var/lib/musky/GoogleAuths /var/lib/musky/GoogleCreds \
 && chown -R www-data:www-data /opt/musky/logs /var/lib/musky \
 && cp Docker/apache-vhost.conf /etc/apache2/sites-available/000-default.conf \
 && chmod +x /opt/musky/Docker/entrypoint.sh

ENTRYPOINT ["/opt/musky/Docker/entrypoint.sh"]
CMD ["apache2-foreground"]
