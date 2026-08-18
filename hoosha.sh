#!/usr/bin/env bash
# ---------------------------------------------------------------------------
#  اجرای هوشا از هر جایی داخل مخزن — همتای لینوکسی/مکِ hoosha.cmd.
#
#  دلیلش همان است: «node src/cli.js» از ریشهٔ مخزن، خطای MODULE_NOT_FOUND می‌دهد
#  که هیچ اشاره‌ای به پوشه ندارد. این اسکریپت خودش جای درست می‌رود.
#
#  کاربرد:  ./hoosha.sh [گزینه‌ها]
# ---------------------------------------------------------------------------
set -euo pipefail

here="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
app="$here/hoosha"

if ! command -v node >/dev/null 2>&1; then
	printf '\n  Node.js پیدا نشد. نسخهٔ ۲۰ یا بالاتر لازم است.\n\n' >&2
	exit 1
fi

if [ ! -f "$app/src/cli.js" ]; then
	printf '\n  پوشهٔ hoosha کنار این فایل نیست: %s\n  این اسکریپت باید در ریشهٔ مخزن بماند.\n\n' "$app" >&2
	exit 1
fi

cd "$app"

if [ ! -d node_modules ]; then
	printf '  وابستگی‌ها نصب نیستند. یک بار npm ci اجرا می‌شود...\n'
	npm ci
fi

exec node src/cli.js "$@"
