ARG PHP_IMAGE
FROM ${PHP_IMAGE}
RUN apt-get update \
    && apt-get install -y --no-install-recommends ffmpeg \
    && rm -rf /var/lib/apt/lists/*
USER 33:33
ENTRYPOINT ["php", "/var/www/html/_h5ai/private/php/storyboard-worker.php", "--watch"]
