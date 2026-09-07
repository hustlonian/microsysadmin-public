#!/usr/bin/env bash
set -euo pipefail

app_root="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
output_root="$app_root/dist"
channel=""
released_at=""
package_url=""
signing_key_file=""
unsigned_development=0
capture_locked_vendor=0
source_epoch="${SOURCE_DATE_EPOCH:-0}"

usage() {
    cat <<'USAGE'
Usage: ./build-release.sh [options]

Options:
  --output DIR                 Output directory (default: ./dist)
  --channel CHANNEL            stable, beta, or development
  --released-at ISO8601        Immutable release time
  --package-url URL            Published package URL
  --signing-key-file FILE      PEM RSA or base64 Ed25519 private key outside this tree
  --unsigned-development       Explicitly permit an unsigned development build
  --capture-locked-vendor      Capture the clean staged Composer vendor baseline and exit

Set SOURCE_DATE_EPOCH to reproduce archive metadata exactly.
USAGE
}

while [[ $# -gt 0 ]]; do
    case "$1" in
        --output) output_root="$2"; shift 2 ;;
        --channel) channel="$2"; shift 2 ;;
        --released-at) released_at="$2"; shift 2 ;;
        --package-url) package_url="$2"; shift 2 ;;
        --signing-key-file) signing_key_file="$2"; shift 2 ;;
        --unsigned-development) unsigned_development=1; shift ;;
        --capture-locked-vendor) capture_locked_vendor=1; shift ;;
        -h|--help) usage; exit 0 ;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 64 ;;
    esac
done

version="$(tr -d '[:space:]' < "$app_root/VERSION")"
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$ ]]; then
    echo "VERSION is not valid Semantic Versioning: $version" >&2
    exit 1
fi
if [[ -z "$channel" ]]; then
    if [[ "$version" == *-dev* ]]; then channel="development"
    elif [[ "$version" == *-* ]]; then channel="beta"
    else channel="stable"
    fi
fi
case "$channel" in stable|beta|development) ;; *) echo "Invalid release channel." >&2; exit 64 ;; esac
if [[ "$channel" == "stable" && "$version" == *-* ]]; then
    echo "Stable channel releases cannot use a Semantic Version prerelease suffix: $version" >&2
    exit 1
fi
if [[ "$channel" == "beta" && "$version" != *-* ]]; then
    echo "Beta channel releases require a Semantic Version prerelease suffix." >&2
    exit 1
fi

if [[ -z "$released_at" ]]; then
    released_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
fi

php_binary="${MICROSYSADMIN_PHP_BINARY:-}"
if [[ -z "$php_binary" ]]; then
    for candidate in /opt/lampp/bin/php /usr/bin/php /usr/local/bin/php; do
        if [[ -x "$candidate" ]]; then php_binary="$candidate"; break; fi
    done
fi
[[ -n "$php_binary" ]] || { echo "PHP 8.2+ was not found." >&2; exit 1; }

staging_parent="$(mktemp -d "${TMPDIR:-/tmp}/microsysadmin-release.XXXXXXXX")"
staging_root="$staging_parent/microsysadmin"
cleanup() {
    [[ -n "${staging_parent:-}" && "$staging_parent" == "${TMPDIR:-/tmp}"/microsysadmin-release.* ]] && rm -rf -- "$staging_parent"
}
trap cleanup EXIT
mkdir -p "$staging_root"

while IFS= read -r entry; do
    [[ -z "$entry" || "$entry" == \#* ]] && continue
    # Production dependencies are materialized from locked blobs below.
    [[ "$entry" == "vendor" ]] && continue
    source_path="$app_root/$entry"
    [[ -e "$source_path" ]] || { echo "Allow-listed release path is missing: $entry" >&2; exit 1; }
    cp -a --no-preserve=ownership -- "$source_path" "$staging_root/"
done < "$app_root/packaging/release-allowlist.txt"

while IFS= read -r entry; do
    [[ -z "$entry" || "$entry" == \#* ]] && continue
    target="$staging_root/$entry"
    if [[ -e "$target" || -L "$target" ]]; then
        rm -rf -- "$target"
    fi
done < "$app_root/packaging/release-excludes.txt"

if [[ "$capture_locked_vendor" -eq 1 ]]; then
    composer_binary="${MICROSYSADMIN_COMPOSER_BINARY:-}"
    if [[ -z "$composer_binary" ]]; then
        for candidate in /usr/bin/composer /usr/local/bin/composer; do
            if [[ -x "$candidate" ]]; then composer_binary="$candidate"; break; fi
        done
    fi
    [[ -n "$composer_binary" ]] || {
        echo "Composer is required only when capturing a new locked vendor baseline." >&2
        exit 1
    }
    if [[ -d "$staging_root/vendor" && ! -L "$staging_root/vendor" ]]; then
        rm -rf -- "$staging_root/vendor"
    fi
    COMPOSER_ALLOW_SUPERUSER=1 "$composer_binary" install \
        --working-dir="$staging_root" \
        --no-dev --prefer-dist --no-interaction --no-progress --no-scripts \
        --classmap-authoritative
    "$php_binary" "$app_root/doctor/tools/capture-locked-composer-vendor.php" \
        "--release-root=$staging_root"
    echo "Clean locked Composer vendor baseline captured; no release archive was created."
    exit 0
fi

"$php_binary" "$staging_root/scripts/materialize-locked-composer-vendor.php" \
    "--root=$staging_root"
"$php_binary" "$staging_root/scripts/verify-locked-composer-vendor.php" \
    "--root=$staging_root"

mkdir -p \
    "$staging_root/storage/logs" \
    "$staging_root/storage/backups" \
    "$staging_root/storage/cache" \
    "$staging_root/storage/scripts" \
    "$staging_root/storage/workspace" \
    "$staging_root/storage/demo"
cat > "$staging_root/storage/.htaccess" <<'HTACCESS'
Require all denied
HTACCESS
touch "$staging_root/storage/logs/.gitkeep" \
      "$staging_root/storage/backups/.gitkeep" \
      "$staging_root/storage/cache/.gitkeep" \
      "$staging_root/storage/scripts/.gitkeep" \
      "$staging_root/storage/workspace/.gitkeep" \
      "$staging_root/storage/demo/.gitkeep"

for forbidden in \
    "config/config.php" ".env" ".git" ".agents" \
    "storage/logs/app.log" "storage/backups/*.zip" \
    "storage/workspace/*" "machine-identity.json"; do
    if compgen -G "$staging_root/$forbidden" >/dev/null; then
        echo "Forbidden customer or machine state entered the release: $forbidden" >&2
        exit 1
    fi
done
if rg -l --hidden --glob '!vendor/**' -- \
    '-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----' "$staging_root" >/dev/null; then
    echo "A private key marker was found in the release staging tree." >&2
    exit 1
fi
"$staging_root/scripts/check-forbidden-files.sh" --root "$staging_root" --release
"$staging_root/scripts/check-secrets.sh" --root "$staging_root"

mkdir -p "$output_root"
output_root="$(cd "$output_root" && pwd)"
archive="$output_root/microsysadmin-$version.tar.gz"
checksum_file="$archive.sha256"
manifest="$output_root/microsysadmin-$version.manifest.json"
detached_signature="$output_root/microsysadmin-$version.manifest.sig"
public_key_output="$output_root/microsysadmin-$version.release-public-key.pem"

tar --sort=name \
    --mtime="@$source_epoch" \
    --owner=0 --group=0 --numeric-owner \
    --pax-option=delete=atime,delete=ctime \
    -cf - -C "$staging_parent" microsysadmin | gzip -n > "$archive"

checksum="$(sha256sum "$archive" | awk '{print $1}')"
printf '%s  %s\n' "$checksum" "$(basename "$archive")" > "$checksum_file"

manifest_args=(
    "$app_root/scripts/release-manifest.php"
    "--version=$version"
    "--channel=$channel"
    "--released-at=$released_at"
    "--sha256=$checksum"
    "--package-name=$(basename "$archive")"
    "--package-url=$package_url"
    "--output=$manifest"
)
if [[ -n "$signing_key_file" ]]; then
    manifest_args+=(
        "--signing-key-file=$signing_key_file"
        "--signature-output=$detached_signature"
    )
elif [[ "$unsigned_development" -eq 1 ]]; then
    manifest_args+=("--unsigned-development")
fi
"$php_binary" "${manifest_args[@]}"
if [[ -n "$signing_key_file" ]]; then
    cp "$app_root/config/release-public-key.pub" "$public_key_output"
fi

tar -tzf "$archive" >/dev/null
(
    cd "$output_root"
    sha256sum -c "$(basename "$checksum_file")" >/dev/null
)

echo "MicroSysAdmin release built:"
echo "  Archive:  $archive"
echo "  Checksum: $checksum_file"
echo "  Manifest: $manifest"
if [[ -n "$signing_key_file" ]]; then
    echo "  Signature: $detached_signature"
    echo "  Public key: $public_key_output"
fi
echo "  Version:  $version"
echo "  Channel:  $channel"
