FROM nextcloud:35.0.2-apache

# ffmpeg is optional for the app (it enables the "Server transcode" delivery mode).
# Keep it in the dev image so every delivery mode can be tested; set
# SCREENINGS_NO_FFMPEG=1 at build time to test a server without it.
ARG SCREENINGS_NO_FFMPEG=0

# Pick up PHP edits immediately instead of after opcache's 60 s revalidation.
RUN echo "opcache.revalidate_freq=0" > /usr/local/etc/php/conf.d/zz-screenings-dev.ini

RUN if [ "$SCREENINGS_NO_FFMPEG" != "1" ]; then \
      apt-get update && apt-get install -y --no-install-recommends ffmpeg && rm -rf /var/lib/apt/lists/*; \
    fi
