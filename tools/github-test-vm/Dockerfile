FROM ubuntu:24.04

ARG DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        bash \
        ca-certificates \
        composer \
        curl \
        git \
        git-lfs \
        jq \
        less \
        mysql-client \
        openssh-client \
        php-cli \
        php-curl \
        php-mbstring \
        php-mysql \
        php-xml \
        php-zip \
        rsync \
        shellcheck \
        sudo \
        unzip \
        zip \
    && rm -rf /var/lib/apt/lists/*

RUN useradd --create-home --shell /bin/bash tester \
    && printf '%s\n' 'tester ALL=(ALL) NOPASSWD:ALL' > /etc/sudoers.d/tester \
    && chmod 0440 /etc/sudoers.d/tester

COPY entrypoint.sh seed-workspace.sh run-microsysadmin-ci.sh /usr/local/bin/

RUN chmod 0755 \
    /usr/local/bin/entrypoint.sh \
    /usr/local/bin/seed-workspace.sh \
    /usr/local/bin/run-microsysadmin-ci.sh

WORKDIR /workspace/microsysadmin

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["sleep", "infinity"]
