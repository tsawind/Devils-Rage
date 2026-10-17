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

# Batch 1 plugins (pinned) + our own plugin (fit library import):
#   seat-fitting (fits, doctrines, "who can fly it"), prices-core + Fuzzwork prices,
#   seat-inventory (doctrine stock), seat-srp, seat-discord-pings.
COPY --chown=seat:seat plugin devilsrage-plugin
RUN composer config repositories.devilsrage \
        '{"type": "path", "url": "devilsrage-plugin", "options": {"symlink": false}}' \
 && composer require \
        cryptatech/seat-fitting:5.0.11 \
        recursivetree/seat-prices-core:1.0.1 \
        cryptatech/seat-prices-fuzzwork:1.0.1 \
        recursivetree/seat-inventory:2.1.7 \
        cryptatech/seat-srp:5.0.7 \
        mattfalahe/seat-discord-pings:2.0.1 \
        devilsrage/seat-devilsrage:1.7.0 \
        --no-install --no-ansi \
 && composer update \
        cryptatech/seat-fitting recursivetree/seat-prices-core cryptatech/seat-prices-fuzzwork \
        recursivetree/seat-inventory cryptatech/seat-srp mattfalahe/seat-discord-pings \
        devilsrage/seat-devilsrage \
        --no-scripts --no-dev --no-ansi --no-progress

# Batch 2 plugins, only when DR_BATCH2=1 in ~/seat-docker/.env (watch memory first):
#   seat-calendar (ops calendar, Discord events), structure-manager (fuel, structure
#   alerts), seat-package-notifications (tells you when plugins are outdated),
#   manager-core (~2 min structure attack alerts and fuel prices for structure-manager).
ARG DR_BATCH2=0
RUN if [ "$DR_BATCH2" = "1" ]; then \
        composer require \
            hermesdj/seat-calendar:1.1.8 \
            mattfalahe/structure-manager:2.0.4 \
            recursivetree/seat-package-notifications:1.0.5 \
            mattfalahe/manager-core:1.0.3 \
            --no-install --no-ansi \
     && composer update \
            hermesdj/seat-calendar mattfalahe/structure-manager recursivetree/seat-package-notifications mattfalahe/manager-core \
            --no-scripts --no-dev --no-ansi --no-progress; \
    fi

# structure-manager (batch 2): "@everyone" / "@here" in its role boxes really ping.
COPY --chown=seat:seat patch-structure-manager-everyone.php /tmp/patch-structure-manager-everyone.php
RUN F=vendor/mattfalahe/structure-manager/src/Services/WebhookDispatcher.php \
 && if [ -f "$F" ]; then php /tmp/patch-structure-manager-everyone.php "$F" && php -l "$F"; fi \
 && rm /tmp/patch-structure-manager-everyone.php

# Replace one file, but only if the original is exactly the reviewed 3.1.2 version.
COPY --chown=seat:seat ConnectorPolicyManagement.php /tmp/ConnectorPolicyManagement.php
RUN F=vendor/warlof/seat-connector/src/Traits/ConnectorPolicyManagement.php \
 && echo "e6705f8154cf23c00b58851437fcf92c3441d09fc66e2a1fae7db8baea5947e4  $F" | sha256sum -c - \
 && cp /tmp/ConnectorPolicyManagement.php "$F" \
 && rm /tmp/ConnectorPolicyManagement.php \
 && php -l "$F"

# "All characters" / "Only this character" buttons above the character picker on every
# character page that has one (Assets, Fittings, Wallet, Mail, Contracts, Industry, ...).
# Fails the build if no such page is found or a page doesn't look as expected.
COPY --chown=seat:seat patch-character-picker.php /tmp/patch-character-picker.php
RUN FILES=$(grep -rl 'id="dt-character-selector"' vendor/eveseat/web/src/resources/views/character) \
 && test -n "$FILES" \
 && php /tmp/patch-character-picker.php $FILES \
 && rm /tmp/patch-character-picker.php

# "My characters only" toggle for directors / CEOs / admins (top bar, next to search).
# Narrows lists and the top search to your own characters; never grants access.
COPY --chown=seat:seat patch-own-only-mode.php /tmp/patch-own-only-mode.php
RUN php /tmp/patch-own-only-mode.php \
        vendor/eveseat/web/src/Http/DataTables/Scopes/CharacterScope.php \
        vendor/eveseat/web/src/resources/views/includes/header.blade.php \
 && php -l vendor/eveseat/web/src/Http/DataTables/Scopes/CharacterScope.php \
 && rm /tmp/patch-own-only-mode.php
