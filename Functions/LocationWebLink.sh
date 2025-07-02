#!/bin/zsh
#
# Return location link per Mosyle IF unit is in lost mode.
DeviceSerialNumber="$1"	
#DeviceSerialNumber="F9FG4EXKQ1GC"
#MOSBasic scripts are used here and relied on
BAGCLI_WORKDIR=$(readlink /usr/local/bin/mosbasic)
#Remove our command name from the ou	 above
BAGCLI_WORKDIR=${BAGCLI_WORKDIR/mosbasic/}
export BAGCLI_WORKDIR
 
source "$BAGCLI_WORKDIR/config"

 #shellcheck source=common
source "$BAGCLI_WORKDIR/common"

source $LOCALCONF/.MosyleAPI
APIKey="$MOSYLE_API_key"

Generate_JSON_LostmodeCheck() {
cat <<EOF
	{"accessToken": "$MOSYLE_API_key",
	"options": {
		"os": "ios",
		"serial_numbers": "$DeviceSerialNumber",
		"specific_columns": "deviceudid,date_last_beat,tags,lostmode_status,longitude,latitude,altitude"
	}
}
EOF
}


CheckLostMode() {
	# #Build Query.  Just asking for current data on last beat, lostmode status, and location data if we can get it.
	APIOUTPUT=$(curl --location 'https://managerapi.mosyle.com/v2/listdevices' \
		--header 'content-type: application/json' \
		--header "Authorization: Bearer $AuthToken" \
		--data "$(Generate_JSON_LostmodeCheck)")

	if echo "$APIOUTPUT" | grep "DEVICES_NOTFOUND"; then
		log_line "Mosyle doesn't know $DeviceSerialNumber.  Epic Fail."
		UDID="NOTFOUND"

	#If device is ENABLED	
	elif echo "$APIOUTPUT" | grep "ENABLED"; then 
		#echo "Lost Mode is enabled."
		#Parse what was returned.
		JSON=$(echo "$APIOUTPUT" | $PYTHON2USE -m json.tool)		
		
		unset UDID
		
	else
		#Only enabled state gives us more than we need.  All other states we can go with bare minimum
		JSON=$(echo "$APIOUTPUT" | $PYTHON2USE -m json.tool)
		
		unset UDID

	fi

	if [ ! "$UDID" = "NOTFOUND" ]; then
		#Cut that up to variables.

		UDID=$(echo "$JSON" |  grep deviceudid | tail -1 | cut -d ':' -f 2 | cut -d '"' -f 2 )
		LASTBEAT=$(echo "$JSON" |  grep date_last_beat | tail -1 | cut -d ':' -f 2 | cut -d '"' -f 2)
		TAGS=$(echo "$JSON" | grep tags | tail -1 | cut -d ':' -f 2 | cut -d '"' -f 2)
		LOSTMODE=$(echo "$JSON" | grep lostmode_status | tail -1 | cut -d ':' -f 2 | cut -d '"' -f 2)
		LONGITUDE=$(echo "$JSON" | grep longitude | tail -1 | cut -d ':' -f 2 | cut -d '"' -f 2)
		LATITUDE=$(echo "$JSON" | grep latitude | tail -1 | cut -d ':' -f 2 | cut -d '"' -f 2)
		ALTITUDE=$(echo "$JSON" | grep altitude | tail -1 | cut -d ':' -f 2 | cut -d '"' -f 2)
				
		LASTBEATDATE=$($PYTHON2USE -c "import datetime; print(datetime.datetime.fromtimestamp(int("$LASTBEAT")).strftime('%Y-%m-%d %I:%M:%S %p'))")
		
		#Figure out how many hours ago last beat was
		current_time=$(date +%s)
		current_time=$(expr "$current_time" / 3600 )
		before_time=$(expr "$LASTBEAT" / 3600 )
		hoursago=$(expr "$current_time" - "$before_time" )
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

		if [[ ! -z "$LATITUDE" ]] && [[ ! -z "$LATITUDE" ]]; then
			echo "https://beta.maps.apple.com/?ll=$LATITUDE%2C$LONGITUDE&q=iPadLocation"
			WHEREISiPad="https://beta.maps.apple.com/?ll=$LATITUDE%2C$LONGITUDE&q=iPadLocation"
	
		else
			echo "Incomplete data.  Could not show location"
		fi
		
	else
		echo "iPad is not in Lost Mode.  Nothing to show."
	fi
fi

