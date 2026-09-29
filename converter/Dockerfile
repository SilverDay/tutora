# Tutora slide conversion worker (spec: Slide Conversion Pipeline).
# Runs one job per container, started by the conversion daemon with the sandbox profile
# from docs/IMPLEMENTATION_PLAN.md (network none, non-root, cap_drop ALL,
# no-new-privileges, read-only root, job-specific mounts, pids/memory/CPU limits,
# external wall-clock timeout, default seccomp profile).
FROM ubuntu:24.04

RUN apt-get update \
 && apt-get install -y --no-install-recommends \
      libreoffice-impress libreoffice-core poppler-utils \
      fonts-dejavu-core fonts-liberation2 fonts-crosextra-carlito fonts-crosextra-caladea \
 && apt-get purge -y --auto-remove \
 && rm -rf /var/lib/apt/lists/* /usr/share/doc /usr/share/man

# Hardened LibreOffice profile template (macros off, no Java, no update checks)
COPY registrymodifications.xcu /opt/tutora/lo-profile/user/registrymodifications.xcu
COPY convert.sh /opt/tutora/convert.sh
RUN chmod 0555 /opt/tutora/convert.sh && chmod -R a+rX /opt/tutora

# fixed unprivileged uid/gid (no login shell, no home)
USER 65532:65532
ENTRYPOINT ["/opt/tutora/convert.sh"]
