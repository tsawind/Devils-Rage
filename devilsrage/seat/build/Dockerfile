# SeAT with the Discord connector baked in, plus The Devil's Rage patch:
# SeAT only adds/removes Discord roles that have an access rule in SeAT;
# every other role (manual or self-assigned) is left alone.
#
# Built by docker compose from ~/seat-docker (see docker-compose.devilsrage.yml).
# Update SeAT later with:  docker compose build --pull && docker compose up -d
FROM ghcr.io/eveseat/seat:5

ENV COMPOSER_MEMORY_LIMIT=-1

# Same steps SeAT's own start script uses for SEAT_PLUGINS, with versions pinned
# so the patched file below always matches the code it replaces.
RUN composer require warlof/seat-connector:3.1.2 warlof/seat-discord-connector:6.0.2 \
        --no-install --no-ansi \
 && composer update warlof/seat-connector warlof/seat-discord-connector \
        --no-scripts --no-dev --no-ansi --no-progress

# Replace one file, but only if the original is exactly the reviewed 3.1.2 version.
COPY --chown=seat:seat ConnectorPolicyManagement.php /tmp/ConnectorPolicyManagement.php
RUN F=vendor/warlof/seat-connector/src/Traits/ConnectorPolicyManagement.php \
 && echo "e6705f8154cf23c00b58851437fcf92c3441d09fc66e2a1fae7db8baea5947e4  $F" | sha256sum -c - \
 && cp /tmp/ConnectorPolicyManagement.php "$F" \
 && rm /tmp/ConnectorPolicyManagement.php \
 && php -l "$F"

# "All characters" / "Only this character" buttons on the character Assets page.
# The script refuses (and fails the build) if SeAT changed that page.
COPY --chown=seat:seat patch-assets-view.php /tmp/patch-assets-view.php
RUN php /tmp/patch-assets-view.php vendor/eveseat/web/src/resources/views/character/assets/assets.blade.php \
 && rm /tmp/patch-assets-view.php
