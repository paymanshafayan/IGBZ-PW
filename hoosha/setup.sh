#!/usr/bin/env bash
#
# آماده‌سازی محیط دسکتاپ هوشا از روی باینری‌های داخل `_bin/`.
#
# چرا این فایل وجود دارد: سندباکس توسعه به فایل‌های ریلیز گیت‌هاب دسترسی ندارد، پس
# باینری‌ها داخل مخزن‌اند و هر جلسه باید از روی آن‌ها محیط بازساخته شود — همان کاری که
# `_devenv/setup.sh` برای وردپرس می‌کند.
#
# اجرا:  bash hoosha/setup.sh
#
set -Eeuo pipefail

HOOSHA="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
REPO="$( cd "$HOOSHA/.." && pwd )"
BIN="$REPO/_bin"
WORK="$HOOSHA/.work"

blue()  { printf '\033[36m==>\033[0m %s\n' "$*"; }
ok()    { printf '  \033[32mok\033[0m %s\n' "$*"; }
warn()  { printf '  \033[33m—\033[0m  %s\n' "$*"; }
die()   { printf '\n\033[31mخطا:\033[0m %s\n' "$*" >&2; exit 1; }

mkdir -p "$WORK"

# --------------------------------------------------------------- وابستگی‌های npm

blue "وابستگی‌های npm"
if [ -d "$HOOSHA/node_modules/@modelcontextprotocol" ]; then
	ok "از قبل نصب است"
else
	( cd "$HOOSHA" && npm install --no-audit --no-fund >/dev/null 2>&1 ) \
		&& ok "نصب شد" \
		|| die "npm install شکست خورد. اینترنت رجیستری npm را بررسی کن."
fi

# ------------------------------------------------------- سرهم‌کردن فایل‌های تکه‌شده

blue "بررسی فایل‌های تکه‌شده در _bin/"
shopt -s nullglob
joined=0
for first in "$BIN"/*.part-aa; do
	base="${first%.part-aa}"
	name="$( basename "$base" )"
	if [ -f "$base" ]; then
		ok "$name از قبل سرهم شده"
		continue
	fi
	blue "سرهم‌کردن $name"
	cat "$base".part-* > "$base"
	ok "$name ساخته شد ($( du -h "$base" | cut -f1 ))"
	joined=$(( joined + 1 ))
done
[ "$joined" = 0 ] && warn "چیزی برای سرهم‌کردن نبود"

# ---------------------------------------------------------------- استخراج آرشیوها

# استخراج یک آرشیو (zip یا rar) در پوشهٔ مقصد.
extract() {
	local archive="$1" dest="$2"
	case "$archive" in
		*.zip)
			unzip -q -o "$archive" -d "$dest"
			;;
		*.rar)
			node "$HOOSHA/tools/unrar.mjs" "$archive" "$dest"
			;;
		*)
			die "فرمت ناشناخته: $archive"
			;;
	esac
}

found_shell=""

blue "پنجرهٔ دسکتاپ"

# --- Neutralinojs (سبک)
for archive in "$BIN"/neutralinojs-*.zip "$BIN"/neutralinojs-*.rar; do
	[ -f "$archive" ] || continue
	dest="$WORK/neutralino"
	if [ -d "$dest" ] && [ -n "$( ls -A "$dest" 2>/dev/null )" ]; then
		ok "Neutralinojs از قبل استخراج شده"
	else
		mkdir -p "$dest"
		extract "$archive" "$dest"
		ok "Neutralinojs استخراج شد ← $dest"
	fi
	found_shell="neutralino"
	break
done

# --- Electron (سنگین)
if [ -z "$found_shell" ]; then
	for archive in "$BIN"/electron-*-linux-x64.zip "$BIN"/electron-*-linux-x64.rar; do
		[ -f "$archive" ] || continue
		dest="$WORK/electron"
		if [ -x "$dest/electron" ]; then
			ok "Electron از قبل استخراج شده"
		else
			mkdir -p "$dest"
			extract "$archive" "$dest"
			chmod +x "$dest/electron" 2>/dev/null || true
			ok "Electron استخراج شد ← $dest"
		fi
		found_shell="electron"
		break
	done
fi

if [ -z "$found_shell" ]; then
	warn "هیچ پوستهٔ دسکتاپی پیدا نشد"
	printf '     یکی از این‌ها را در %s بگذار (راهنما: _bin/README.md):\n' "$BIN"
	printf '       • neutralinojs-v<نسخه>.zip                       (~۵MB، یک فایل)\n'
	printf '       • electron-v43.4.0-linux-x64.zip.part-aa/ab/ac   (تکه‌شده با split)\n'
fi

# ------------------------------------------------------------------------- Bun

blue "Bun (فقط برای فورک OpenCode)"
bun_found=""
for archive in "$BIN"/bun-linux-x64.zip "$BIN"/bun-linux-x64.rar; do
	[ -f "$archive" ] || continue
	dest="$WORK/bun"
	if [ -x "$dest/bun" ]; then
		ok "Bun از قبل استخراج شده"
	else
		mkdir -p "$dest"
		extract "$archive" "$dest"
		# زیپ رسمی bun یک پوشهٔ داخلی دارد؛ باینری را بالا می‌آوریم.
		if [ ! -x "$dest/bun" ]; then
			inner="$( find "$dest" -name bun -type f | head -1 )"
			[ -n "$inner" ] && mv "$inner" "$dest/bun"
		fi
		chmod +x "$dest/bun" 2>/dev/null || true
		ok "Bun استخراج شد ← $dest/bun"
	fi
	bun_found="1"
	break
done
[ -z "$bun_found" ] && warn "Bun نیست (اگر فورک OpenCode را انتخاب کردی لازم می‌شود)"

# ----------------------------------------------------------------------- خلاصه

printf '\n\033[32mآماده.\033[0m\n\n'
printf '  اجرا در مرورگر :  node %s/src/cli.js\n' "$HOOSHA"
if [ "$found_shell" = "electron" ]; then
	printf '  اجرا در پنجره  :  %s/electron %s/desktop/main.js\n' "$WORK/electron" "$HOOSHA"
elif [ "$found_shell" = "neutralino" ]; then
	printf '  اجرا در پنجره  :  (پیکربندی Neutralino پس از آپلود ساخته می‌شود)\n'
fi
printf '  تست‌ها         :  node %s/test/run.mjs\n\n' "$HOOSHA"
