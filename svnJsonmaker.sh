#!/usr/bin/env bash
set -euo pipefail

PLUGIN_SLUG="fishdan-jsonmaker"
PLUGIN_SRC="/var/www/wpdev/wp-content/plugins/${PLUGIN_SLUG}"
SVN_URL="https://plugins.svn.wordpress.org/${PLUGIN_SLUG}"
SVN_WORKING_COPY="${HOME}/plugsub/${PLUGIN_SLUG}-svn"
CREDENTIAL_FILE="${HOME}/plugsub/svn.keys"

if [[ ! -d "${SVN_WORKING_COPY}" ]]; then
	echo "SVN working copy not found at ${SVN_WORKING_COPY}" >&2
	echo "Run: svn checkout ${SVN_URL} ${SVN_WORKING_COPY}" >&2
	exit 1
fi

if [[ ! -f "${PLUGIN_SRC}/readme.txt" ]]; then
	echo "Could not locate readme.txt at ${PLUGIN_SRC}/readme.txt" >&2
	exit 1
fi

VERSION=$(grep -E '^Stable tag:' "${PLUGIN_SRC}/readme.txt" | awk '{print $3}')
if [[ -z "${VERSION}" ]]; then
	echo "Unable to extract version from readme.txt" >&2
	exit 1
fi

echo "Using version ${VERSION}"

if [[ -f "${CREDENTIAL_FILE}" ]]; then
	while IFS=':= ' read -r key value _; do
		[[ -z "${key}" || "${key}" == \#* ]] && continue
		value=${value//$'\r'/}
		case "${key}" in
			SVN_USERNAME | username)
				SVN_USERNAME="${value}"
				;;
			SVN_PASSWORD | password)
				SVN_PASSWORD="${value}"
				;;
		esac
	done < "${CREDENTIAL_FILE}"

	if [[ -z "${SVN_USERNAME:-}" || -z "${SVN_PASSWORD:-}" ]]; then
		echo "Credential file ${CREDENTIAL_FILE} is missing SVN_USERNAME/password entries; continuing without explicit credentials."
	fi
else
	echo "Credential file ${CREDENTIAL_FILE} not found; continuing without explicit credentials."
fi

svn_cmd() {
	if [[ -n "${SVN_USERNAME:-}" && -n "${SVN_PASSWORD:-}" ]]; then
		command svn --non-interactive --username "${SVN_USERNAME}" --password "${SVN_PASSWORD}" "$@"
	else
		command svn "$@"
	fi
}

run() {
	local description=$1
	shift
	echo "▶ ${description}"
	"$@"
	echo "✔ ${description}"
}

cd "${SVN_WORKING_COPY}"

run "svn cleanup" svn_cmd cleanup
run "svn up" svn_cmd up

run "sync trunk with plugin source" rsync -av --delete \
	--exclude='.git' \
	--exclude='.github' \
	--exclude='.gitignore' \
	--exclude='*.notes' \
	"${PLUGIN_SRC}/" trunk/

run "svn status (pre-add)" svn_cmd status
run "svn add trunk (force)" svn_cmd add --force trunk

MISSING_PATHS=$(svn_cmd status | awk '/^!/ {print $2}')
if [[ -n "${MISSING_PATHS}" ]]; then
	while IFS= read -r path; do
		if [[ -n "${path}" ]]; then
			run "svn rm ${path}" svn_cmd rm "${path}"
		fi
	done <<< "${MISSING_PATHS}"
fi

run "svn status (pre-commit)" svn_cmd status
run "svn commit trunk for ${VERSION}" svn_cmd commit trunk -m "Release ${VERSION}"

TAG_PATH="tags/${VERSION}"
run "svn copy trunk to ${TAG_PATH}" svn_cmd copy trunk "${TAG_PATH}"
run "svn status (tag added)" svn_cmd status
run "svn commit tag ${VERSION}" svn_cmd commit "${TAG_PATH}" -m "Tag ${VERSION}"
run "svn status (final)" svn_cmd status
