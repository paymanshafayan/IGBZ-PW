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

# اگر package.json عوض شده باشد (git pull با وابستگی تازه) خودمان npm ci می‌زنیم —
# نه خطی مثل "Cannot find package 'undici'" سرِ راه کاربر بیاید.
deps_current() {
	[ -d node_modules ] || return 1
	[ -f .deps-marker ] || return 1
	node -e "const fs=require('fs');const v=(JSON.parse(fs.readFileSync('package.json','utf8'))||{}).version||'';const m=fs.readFileSync('.deps-marker','utf8').trim();process.exit(v===m?0:1)" 2>/dev/null
}
if ! deps_current; then
	printf '  وابستگی‌ها تازه یا کهنه‌اند — یک بار npm ci اجرا می‌شود...\n'
	npm ci || exit 1
	node -e "const fs=require('fs');fs.writeFileSync('.deps-marker',((fs.readFileSync('package.json','utf8').match(/\"version\"\s*:\s*\"[^\"]+\"/))||[''])[0])"
fi

exec node src/cli.js "$@"
