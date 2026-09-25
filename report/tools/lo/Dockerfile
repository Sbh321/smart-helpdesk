# LibreOffice for laying out the report (tools/paginate.sh): page count, and page numbers for the contents and lists.
# Liberation Serif has the same metrics as Times New Roman, so pages break as they do with Times New Roman.
FROM debian:trixie-slim
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends \
      libreoffice-writer-nogui python3-uno fonts-liberation2 fonts-dejavu-core poppler-utils \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /work
