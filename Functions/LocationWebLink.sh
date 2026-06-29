#!/bin/zsh
#
# Return location link per Mosyle IF unit is in lost mode.
DeviceSerialNumber="$1"
SCRIPT_DIR="$(cd -- "$(dirname -- "$0")" && pwd)"
MUSKY_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
MUSKY_CONFIG="$MUSKY_ROOT/musky_config.json"
PHP_BIN="${PHP_BIN:-$(command -v php 2>/dev/null)}"
MOSYLE_API_BASE_URL="https://managerapi.mosyle.com/v2"
MOSBASIC_BINARY="/usr/local/bin/mosbasic"
MOSYLE_CREDENTIAL_FILE=""

musky_config_string() {
    local path="$1"
    local default_value="${2:-}"

    if [[ -z "$PHP_BIN" ]] || [[ ! -r "$MUSKY_CONFIG" ]]; then
        printf '%s' "$default_value"
        return 0
    fi

    "$PHP_BIN" -r '
        $cfgPath = $argv[1];
        $path = explode(".", $argv[2]);
        $default = $argv[3] ?? "";
        $cfg = json_decode((string)@file_get_contents($cfgPath), true);
        if (!is_array($cfg)) {
            echo $default;
            exit(0);
        }
        $cursor = $cfg;
        foreach ($path as $segment) {
            $segment = trim((string)$segment);
            if ($segment === "" || !is_array($cursor) || !array_key_exists($segment, $cursor)) {
                echo $default;
                exit(0);
            }
            $cursor = $cursor[$segment];
        }
        if (is_string($cursor) || is_numeric($cursor)) {
            echo (string)$cursor;
            exit(0);
        }
        echo $default;
    ' "$MUSKY_CONFIG" "$path" "$default_value"
}

json_find_first_scalar() {
    local key="$1"
    "$PHP_BIN" -r '
        $target = $argv[1];
        $data = json_decode(stream_get_contents(STDIN), true);
        if (!is_array($data)) {
            exit(1);
        }
        $stack = [$data];
        while ($stack) {
            $node = array_pop($stack);
            if (!is_array($node)) {
                continue;
            }
            foreach ($node as $k => $v) {
                if ((string)$k === $target && (is_string($v) || is_numeric($v))) {
                    echo (string)$v;
                    exit(0);
                }
                if (is_array($v)) {
                    $stack[] = $v;
                }
            }
        }
        exit(1);
    ' "$key"
}

Generate_JSON_Login() {
cat <<EOF
{"accessToken":"$MOSYLE_API_key","email":"$MOSYLE_API_Username","password":"$MOSYLE_API_Password"}
EOF
}

log_line() {
    printf '%s\n' "$*" >&2
}

GetBearerToken() {
    local grab_token
    grab_token=$(curl -sS --include --location "$MOSYLE_API_BASE_URL/login" \
        --header 'Content-Type: application/json' \
        --data "$(Generate_JSON_Login)" 2>/dev/null)

    AuthToken=$(printf '%s\n' "$grab_token" | awk 'BEGIN{IGNORECASE=1} /^Authorization:/ {print $3; exit}')
    AuthToken="${AuthToken//[$'\r\n\t ']/}"

    if [[ -z "$AuthToken" ]]; then
        log_line "No token given by Mosyle. FAIL."
        exit 1
    fi
}

MOSBASIC_BINARY="$(musky_config_string 'paths.mosbasic_binary' "$MOSBASIC_BINARY")"
MOSYLE_API_BASE_URL="$(musky_config_string 'mosyle.api_base_url' "$MOSYLE_API_BASE_URL")"
MOSYLE_CREDENTIAL_FILE="$(musky_config_string 'mosyle.credentials_file' '')"

if [[ -z "$MOSYLE_CREDENTIAL_FILE" ]]; then
    MOSYLE_CREDENTIAL_FILE="$(dirname -- "$MOSBASIC_BINARY")/.MosyleAPI"
fi

if [[ ! -r "$MOSYLE_CREDENTIAL_FILE" ]]; then
    echo "Mosyle credential file not readable. FAIL."
    exit 1
fi

source "$MOSYLE_CREDENTIAL_FILE"
APIKey="$MOSYLE_API_key"

Generate_JSON_LostmodeCheck() {
cat <<EOF
{"accessToken":"$MOSYLE_API_key","options":{"os":"ios","serial_numbers":"$DeviceSerialNumber","specific_columns":"deviceudid,date_last_beat,tags,lostmode_status,longitude,latitude,altitude"}}
EOF
}


CheckLostMode() {
	APIOUTPUT=$(curl -sS --location "$MOSYLE_API_BASE_URL/listdevices" \
		--header 'content-type: application/json' \
		--header "Authorization: Bearer $AuthToken" \
		--data "$(Generate_JSON_LostmodeCheck)")

	if printf '%s' "$APIOUTPUT" | grep -q "DEVICES_NOTFOUND"; then
		log_line "Mosyle doesn't know $DeviceSerialNumber. Epic fail."
		UDID="NOTFOUND"
		return
	fi

	unset UDID
	UDID=$(printf '%s' "$APIOUTPUT" | json_find_first_scalar 'deviceudid')
	LASTBEAT=$(printf '%s' "$APIOUTPUT" | json_find_first_scalar 'date_last_beat')
	TAGS=$(printf '%s' "$APIOUTPUT" | json_find_first_scalar 'tags')
	LOSTMODE=$(printf '%s' "$APIOUTPUT" | json_find_first_scalar 'lostmode_status')
	LONGITUDE=$(printf '%s' "$APIOUTPUT" | json_find_first_scalar 'longitude')
	LATITUDE=$(printf '%s' "$APIOUTPUT" | json_find_first_scalar 'latitude')
	ALTITUDE=$(printf '%s' "$APIOUTPUT" | json_find_first_scalar 'altitude')

	if [[ -n "$LASTBEAT" && -n "$PHP_BIN" ]]; then
		LASTBEATDATE=$("$PHP_BIN" -r '
			$ts = (int)($argv[1] ?? 0);
			if ($ts > 0) {
				echo date("Y-m-d h:i:s A", $ts);
			}
		' "$LASTBEAT")

		current_time=$(date +%s)
		current_time=$(expr "$current_time" / 3600)
		before_time=$(expr "$LASTBEAT" / 3600)
		hoursago=$(expr "$current_time" - "$before_time")
	fi
}


if [ -z "$DeviceSerialNumber" ]; then
	echo "No serial number provided.  FAIL."
else
	GetBearerToken
	CheckLostMode
	
	if [ "$LOSTMODE" = "ENABLED" ]; then
		#FYI info about Apple Maps, web, and variables
		# https://developer.apple.com/library/archive/featuredarticles/iPhoneURLScheme_Reference/MapLinks/MapLinks.html

		if [[ -n "$LATITUDE" ]] && [[ -n "$LONGITUDE" ]]; then
			echo "https://beta.maps.apple.com/?ll=$LATITUDE%2C$LONGITUDE&q=iPadLocation"
			WHEREISiPad="https://beta.maps.apple.com/?ll=$LATITUDE%2C$LONGITUDE&q=iPadLocation"
	
		else
			echo "Incomplete data.  Could not show location"
		fi
		
	else
		echo "iPad is not in Lost Mode.  Nothing to show."
	fi
fi
