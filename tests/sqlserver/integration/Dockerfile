# PHP with pdo_sqlsrv, for tests/Integration/SqlServerDriverTest.php only.
# PHP 8.4: the locked dev dependencies need >= 8.4.1; pdo_sqlsrv 5.13.x supports 8.4 on Debian 11-13.
FROM php:8.4-cli

RUN apt-get update \
    && apt-get install -y --no-install-recommends curl unixodbc-dev libgssapi-krb5-2 \
    && . /etc/os-release \
    && curl -fsSLO "https://packages.microsoft.com/config/debian/${VERSION_ID%%.*}/packages-microsoft-prod.deb" \
    && dpkg -i packages-microsoft-prod.deb \
    && rm packages-microsoft-prod.deb \
    && apt-get update \
    && ACCEPT_EULA=Y apt-get install -y --no-install-recommends msodbcsql18 \
    && pecl install sqlsrv-5.13.3 pdo_sqlsrv-5.13.3 \
    && docker-php-ext-enable sqlsrv pdo_sqlsrv \
    && rm -rf /var/lib/apt/lists/* /tmp/pear
