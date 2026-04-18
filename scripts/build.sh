#!/bin/bash
# Build script for Restic Backup Unraid plugin
# Usage: ./scripts/build.sh [version]
# Example: ./scripts/build.sh 2026.04.12.01
#
# After running:
#   1. Update &version; and &md5; in src/restic-backup.plg
#   2. git add archive/ src/ && git commit -m "..." && git push

set -e

PLUGIN="restic-backup"
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"

# Auto-increment: find highest existing .NN for today and add 1
_today=$(date '+%Y.%m.%d')
if [ -z "$1" ]; then
    _max=0
    for _f in "${ROOT_DIR}"/archive/${PLUGIN}-${_today}.*.txz; do
        [ -f "$_f" ] || continue
        _n=$(basename "$_f" | sed -E "s/${PLUGIN}-${_today}\\.([0-9]+)-.*/\1/")
        _n=$((10#$_n))
        [ "$_n" -gt "$_max" ] && _max=$_n
    done
    VERSION="${_today}.$(printf '%02d' $((_max + 1)))"
else
    VERSION="$1"
fi
STAGE="/tmp/${PLUGIN}_build/staging"
ARCHIVE_DIR="${ROOT_DIR}/archive"

echo "Building ${PLUGIN}-${VERSION}-x86_64-1.txz ..."

# Clean staging
rm -rf "/tmp/${PLUGIN}_build"
mkdir -p "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/scripts"
mkdir -p "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/include"
mkdir -p "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/assets"
mkdir -p "${STAGE}/install"

# Copy plugin files
cp "${ROOT_DIR}/src/restic-backup.page" \
   "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/"
cp "${ROOT_DIR}/src/ResticBackup.php" \
   "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/"
cp "${ROOT_DIR}/src/ResticBackupAPI.php" \
   "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/"
cp "${ROOT_DIR}/src/include/helpers.php" \
   "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/include/"
cp "${ROOT_DIR}/src/assets/script.js" \
   "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/assets/"
cp "${ROOT_DIR}/src/scripts/restic-backup.py" \
   "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/scripts/"
cp "${ROOT_DIR}/src/scripts/setup_cron.sh" \
   "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/scripts/"
chmod 755 "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/scripts/"*.py
chmod 755 "${STAGE}/usr/local/emhttp/plugins/${PLUGIN}/scripts/"*.sh

# Slack description
cat > "${STAGE}/install/slack-desc" << EOF
${PLUGIN}: Restic Backup (MajorPain007)
${PLUGIN}:
${PLUGIN}: Restic-based backup with GUI configuration for Unraid.
${PLUGIN}: Multiple jobs, ZFS snapshots, scheduled backups, live logs.
${PLUGIN}:
EOF

# Build archive (no Apple metadata)
mkdir -p "${ARCHIVE_DIR}"
PKG="${PLUGIN}-${VERSION}-x86_64-1.txz"
( cd "${STAGE}" && COPYFILE_DISABLE=1 tar --no-xattrs -cJf "${ARCHIVE_DIR}/${PKG}" install/ usr/ )

if command -v md5 >/dev/null 2>&1; then
    MD5=$(md5 -q "${ARCHIVE_DIR}/${PKG}")
else
    MD5=$(md5sum "${ARCHIVE_DIR}/${PKG}" | awk '{print $1}')
fi

echo ""
echo "Done!"
echo "  File : archive/${PKG}"
echo "  MD5  : ${MD5}"
echo "  Size : $(du -sh "${ARCHIVE_DIR}/${PKG}" | cut -f1)"
echo ""
echo "Next steps:"
echo "  1. Update src/restic-backup.plg:"
echo "     <!ENTITY version   \"${VERSION}\">"
echo "     <!ENTITY md5       \"${MD5}\">"
echo "  2. git add archive/ src/ && git commit && git push"
