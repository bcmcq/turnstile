FROM dunglas/frankenphp:1-php8.5-alpine
RUN install-php-extensions redis
WORKDIR /app
COPY apps/mocks/ ./
ENV SERVER_NAME=:8090
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8090"]
