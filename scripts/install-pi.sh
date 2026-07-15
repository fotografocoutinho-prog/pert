#!/usr/bin/env bash
# Provision a Raspberry Pi (Pi 3/4/5, Raspberry Pi OS) as a kiosk player.
# Installs Node + the Electron player and registers a systemd service that
# launches the kiosk on boot and restarts it on failure (watchdog).
#
# Usage:
#   sudo API_URL=http://server:4000 MONITOR_ID=<uuid> TOKEN=<jwt> ./scripts/install-pi.sh
set -euo pipefail

: "${API_URL:?Set API_URL (e.g. http://192.168.1.10:4000)}"
: "${MONITOR_ID:?Set MONITOR_ID (the monitor UUID from the backoffice)}"
: "${TOKEN:?Set TOKEN (a device access token)}"

APP_USER="${SUDO_USER:-pi}"
APP_DIR="/opt/signage-player"

echo "==> Installing system dependencies"
apt-get update
apt-get install -y nodejs npm git

echo "==> Copying player into ${APP_DIR}"
mkdir -p "${APP_DIR}"
cp -r "$(dirname "$0")/../player/." "${APP_DIR}/"
cp -r "$(dirname "$0")/../shared" "${APP_DIR}/shared"

echo "==> Installing player dependencies"
cd "${APP_DIR}"
npm install --omit=dev
npm run build

echo "==> Writing player configuration"
CONFIG_DIR="/home/${APP_USER}/.config/@signage/player"
mkdir -p "${CONFIG_DIR}"
cat > "${CONFIG_DIR}/config.json" <<EOF
{ "apiUrl": "${API_URL}", "monitorId": "${MONITOR_ID}", "token": "${TOKEN}" }
EOF
chown -R "${APP_USER}:${APP_USER}" "/home/${APP_USER}/.config"

echo "==> Registering systemd service"
cat > /etc/systemd/system/signage-player.service <<EOF
[Unit]
Description=Digital Signage Player (kiosk)
After=graphical.target network-online.target

[Service]
User=${APP_USER}
Environment=DISPLAY=:0
Environment=SIGNAGE_API_URL=${API_URL}
Environment=SIGNAGE_MONITOR_ID=${MONITOR_ID}
Environment=SIGNAGE_TOKEN=${TOKEN}
WorkingDirectory=${APP_DIR}
ExecStart=/usr/bin/npm start
Restart=always
RestartSec=3

[Install]
WantedBy=graphical.target
EOF

systemctl daemon-reload
systemctl enable signage-player.service
systemctl restart signage-player.service

echo "Done. The kiosk will start on boot and restart automatically on failure."
