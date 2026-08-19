#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "${script_dir}/.." && pwd)"
plugin_slug="btusa-contact-acquisition"
plugin_file="${repo_root}/${plugin_slug}.php"
output_dir="${1:-${repo_root}/dist}"

for command_name in php zip unzip; do
	if ! command -v "${command_name}" >/dev/null 2>&1; then
		echo "Required command is unavailable: ${command_name}" >&2
		exit 1
	fi
done

version="$(sed -nE 's/^ \* Version:[[:space:]]*([0-9]+\.[0-9]+\.[0-9]+).*$/\1/p' "${plugin_file}" | head -n 1)"

if [[ -z "${version}" ]]; then
	echo "Plugin header must contain a semantic Version such as 1.0.0." >&2
	exit 1
fi

echo "Checking PHP syntax for ${plugin_slug} ${version}..."
php -l "${plugin_file}"

mkdir -p "${output_dir}"
archive_path="${output_dir}/${plugin_slug}-${version}.zip"
temporary_dir="$(mktemp -d)"
staging_dir="${temporary_dir}/${plugin_slug}"

cleanup() {
	rm -rf "${temporary_dir}"
}
trap cleanup EXIT

mkdir -p "${staging_dir}"
cp "${plugin_file}" "${repo_root}/README.md" "${repo_root}/readme.txt" "${repo_root}/LICENSE" "${staging_dir}/"

(
	cd "${temporary_dir}"
	zip -q -r "${archive_path}" "${plugin_slug}"
)

unzip -tq "${archive_path}"

for required_path in \
	"${plugin_slug}/${plugin_slug}.php" \
	"${plugin_slug}/readme.txt"; do
	if ! unzip -Z1 "${archive_path}" | grep -Fx "${required_path}" >/dev/null; then
		echo "Archive is missing required file: ${required_path}" >&2
		exit 1
	fi
done

echo "Created ${archive_path}"
