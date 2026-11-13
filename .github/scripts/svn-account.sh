#!/usr/bin/env bash
# Picks, and checks, the WordPress.org SVN account a release goes out with.
#
# Each contributor may keep their own SVN credentials as the secrets
# SVN_<LOGIN>_USERNAME and SVN_<LOGIN>_PASSWORD in the wordpress-org
# environment, where <LOGIN> is their GitHub login in capitals with every
# character other than a letter or digit turned into an underscore:
# paulacato becomes SVN_PAULACATO_USERNAME, some-one becomes SVN_SOME_ONE_….
# Whoever has none set releases with the company account, SVN_USERNAME and
# SVN_PASSWORD.
#
#   svn-account.sh key <login>   writes key=<LOGIN> to the step outputs
#   svn-account.sh choose        writes variant=personal|company, and
#                                company=set|unset, from PERSONAL_USERNAME,
#                                PERSONAL_PASSWORD, COMPANY_USERNAME,
#                                COMPANY_PASSWORD and KEY
#   svn-account.sh probe <slug>  checks SVN_USERNAME and SVN_PASSWORD may
#                                commit to the plugin <slug>
set -euo pipefail

output() {
	echo "$1=$2" >> "${GITHUB_OUTPUT:-/dev/stdout}"
}

case "${1:-}" in
	key)
		login=${2:?Give the GitHub login.}
		output key "$(printf '%s' "$login" | tr '[:lower:]' '[:upper:]' | tr -c 'A-Z0-9' '_')"
		;;

	choose)
		key=${KEY:?Set KEY.}
		if [ -n "${PERSONAL_USERNAME:-}" ] && [ -n "${PERSONAL_PASSWORD:-}" ]; then
			variant=personal
		elif [ -n "${PERSONAL_USERNAME:-}${PERSONAL_PASSWORD:-}" ]; then
			# Half a personal account means one was meant, so falling back to the
			# company account would hide the mistake.
			echo "::error::Only one of SVN_${key}_USERNAME and SVN_${key}_PASSWORD is set. Set both, or neither to use the company account."
			exit 1
		elif [ -n "${COMPANY_USERNAME:-}" ] && [ -n "${COMPANY_PASSWORD:-}" ]; then
			variant=company
		else
			echo "::error::There are no SVN_${key}_USERNAME and SVN_${key}_PASSWORD, and no company SVN_USERNAME and SVN_PASSWORD to fall back to."
			exit 1
		fi
		echo "::notice::SVN_${key}_*: the $variant SVN credentials are used."
		output variant "$variant"
		if [ -n "${COMPANY_USERNAME:-}" ] && [ -n "${COMPANY_PASSWORD:-}" ]; then
			output company set
		else
			output company unset
		fi
		;;

	probe)
		# Opens a commit transaction, adds a folder to it and throws it away: the
		# same requests svn makes when it commits, short of the last one, so
		# nothing reaches the repository. WordPress.org asks for the login on the
		# first request and checks commit access on the second.
		slug=${2:?Give the plugin slug.}
		: "${SVN_USERNAME:?Set SVN_USERNAME.}" "${SVN_PASSWORD:?Set SVN_PASSWORD.}"
		base=https://plugins.svn.wordpress.org

		# The credentials go to curl on stdin, not on its command line.
		escape() {
			local value=${1//\\/\\\\}
			printf '%s' "${value//\"/\\\"}"
		}
		config=$(printf 'user = "%s:%s"\n' "$(escape "$SVN_USERNAME")" "$(escape "$SVN_PASSWORD")")
		request() {
			curl --silent --show-error --output /dev/null --write-out '%{http_code}' --config - "$@" <<< "$config"
		}

		headers=$(mktemp)
		status=$(request --dump-header "$headers" --request POST \
			--header 'Content-Type: application/vnd.svn-skel' \
			--data-binary '( create-txn )' "$base/!svn/me")

		case "$status" in
			201) ;;
			401)
				echo "::error::WordPress.org does not accept this username and password. Use the SVN password from the account's profile, not the login password."
				exit 1
				;;
			*)
				echo "::error::WordPress.org answered $status when asked to open a transaction, so the credentials could not be checked."
				exit 1
				;;
		esac

		txn=$(grep -i '^SVN-Txn-Name:' "$headers" | cut -d' ' -f2 | tr -d '\r\n')
		if [ -z "$txn" ]; then
			echo "::error::WordPress.org accepted the login but returned no transaction, so commit access could not be checked."
			exit 1
		fi
		trap 'request --request DELETE "$base/!svn/txn/$txn" > /dev/null || true' EXIT

		status=$(request --request MKCOL "$base/!svn/txr/$txn/$slug/svn-credentials-check")
		case "$status" in
			201)
				echo "The login is valid and may commit to $slug."
				;;
			403)
				echo "::error::The login is valid, but the account may not commit to $slug. Add it as a committer on the plugin's Advanced view."
				exit 1
				;;
			404|409)
				echo "::error::The login is valid, but there is no plugin $slug in SVN."
				exit 1
				;;
			*)
				echo "::error::The login is valid, but WordPress.org answered $status when checking commit access to $slug."
				exit 1
				;;
		esac
		;;

	*)
		echo "Usage: $0 key <login> | choose | probe <slug>" >&2
		exit 2
		;;
esac
