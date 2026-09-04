#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this script with sudo."
    exit 1
fi

app_dir=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
php_bin=$(command -v php)

if [[ -z ${php_bin} ]]; then
    echo "PHP CLI was not found. Install PHP before configuring background jobs."
    exit 1
fi

systemctl enable --now cron

cat > /etc/cron.d/wbs-background-jobs <<EOF
* * * * * root ${php_bin} ${app_dir}/api/cron/process_sms_queue.php >> /var/log/wbs-sms-queue.log 2>&1
* * * * * root ${php_bin} ${app_dir}/api/cron/process_email_queue.php >> /var/log/wbs-email-queue.log 2>&1
0 8 * * * root ${php_bin} ${app_dir}/api/cron/send_payment_reminders.php >> /var/log/wbs-payment-reminders.log 2>&1
EOF

chmod 644 /etc/cron.d/wbs-background-jobs
systemctl restart cron

echo "Background jobs installed successfully."