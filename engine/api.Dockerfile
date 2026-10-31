FROM php:8.3-cli AS base
RUN apt-get update && apt-get install -y --no-install-recommends \
    libssl-dev libonig-dev libxml2-dev unzip git \
    && docker-php-ext-install mbstring dom \
    && pecl install mongodb-2.5.5 redis-6.2.0 \
    && docker-php-ext-enable mongodb redis \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY apps/api/composer.json apps/api/composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress
COPY apps/api/ ./
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && composer dump-autoload --optimize --no-dev --no-interaction
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

# Explicit local target includes offline reconstruction tools.
FROM base AS local

# Default target excludes local-only commands and import implementation.
FROM base AS production
RUN rm -f app/Console/Commands/ReplayEstimatedHistory.php \
    app/Console/Commands/RebuildRawEvidence.php \
    app/Infrastructure/Provider/RawEvidenceRebuilder.php \
    app/Infrastructure/Provider/RawRebuildIndex.php \
    app/Infrastructure/Provider/ArchivedFeed.php \
    app/Console/Commands/RecoverRawOdds.php \
    app/Infrastructure/Provider/RawOddsRecovery.php \
    app/Console/Commands/AuditLegacyHistory.php \
    app/Console/Commands/ImportLegacyHistory.php \
    app/Console/Commands/VerifyLegacyHistory.php \
    app/Infrastructure/Provider/LegacyHistoryVerification.php \
    app/Infrastructure/Provider/LegacyHistoryWriter.php \
    && composer dump-autoload --optimize --no-dev --no-interaction
