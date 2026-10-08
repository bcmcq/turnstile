FROM dunglas/frankenphp:1-php8.5-alpine
RUN apk add --no-cache docker-cli
WORKDIR /app
COPY apps/scaler/ ./
CMD ["frankenphp", "php-server", "--root", "/app/public", "--listen", ":8070"]
