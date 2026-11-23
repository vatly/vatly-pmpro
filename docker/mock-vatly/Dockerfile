FROM php:8.2-cli-alpine

WORKDIR /srv
COPY server.php /srv/server.php
COPY router.php /srv/router.php

EXPOSE 8080

# Serve requests from a small pool of workers. The hosted "Pay" flow fires the
# order.paid webhook synchronously, and the plugin's reaction calls back into
# this mock (GetOrder) while that outbound request is still open. A single
# built-in-server process would deadlock on that nested call; the worker pool
# lets the callback be served concurrently.
ENV PHP_CLI_SERVER_WORKERS=4

CMD ["php", "-S", "0.0.0.0:8080", "router.php"]
