#!/bin/zsh

source /Users/jsmillie/.MosyleAPI

DEBUG="Y"

#This function and the next were added to support Bearer tokens
#in Mosyle.
Generate_JSON_PostData() {
cat <<EOF
	{"accessToken": "$MOSYLE_API_key",
	"email": "$MOSYLE_API_Username",
	"password": "$MOSYLE_API_Password" }
EOF
}

GetBearerToken() {
	#Using JSON Post data from above try to get BearerToken
	GrabToken=$(curl --include --location 'https://managerapi.mosyle.com/v2/login' \
	--header 'Content-Type: application/json' \
	--data "$(Generate_JSON_PostData)" 2>/dev/null)
	
	AuthToken=$(echo "$GrabToken" | grep Authorization | cut -d ' ' -f 3 )

	#Make sure we got data back and if so store it.
	if [ -z "$AuthToken" ]; then
		echo "No token given by Mostle.  FAIL."
		exit 1
	
	else
		echo "Token Given.  Storing.."
		#Strip any Spaces in the Token
		AuthToken="${AuthToken//[[:space:]]/}"
		#Drop the token to file.  Its good for 24hrs
		#so maybe down the road we can reuse it.
		#echo "$AuthToken" > ../../../ConfigFiles/.MosyleAPI_BearToken
	fi
}


#Format for an iPad Data Dump of JSON
Generate_JSON_IOSDUMPPostData() {
cat <<EOF
	{"accessToken": "$MOSYLE_API_key",
	"options": {
		"os": "ios",
		"serial_numbers": "$DeviceSerialNumber",
		"page": "$THEPAGE",
		"specific_columns": "date_last_beat,lostmode_status,last_ip_beat,last_lan_ip,userid"
	}
}
EOF
}

GetCurrentInfo-ios(){
	GetBearerToken
	
	#This is a new CURL call with JSON data - JCS 11/8/23
	output=$(curl --location 'https://managerapi.mosyle.com/v2/listdevices' \
		--header 'content-type: application/json' \
		--header "Authorization: Bearer $AuthToken" \
		--data "$(Generate_JSON_IOSDUMPPostData)") 
	
	if echo "$output" | grep "DEVICES_NOTFOUND"; then
		log_line "No updated info available for $DeviceSerialNumber"
		
	else
		# if [ "$MB_DEBUG" = "Y" ]; then
			echo "$output" > "/tmp/$DeviceSerialNumber.GetCurrentInfo-ios.txt"
		# fi
		
		#What parse are we using
		if echo "$output" | grep -q "userid"; then
			#iPad is assigned
			MicroParse=$(echo "$output"| awk 'BEGIN{FS=",";RS="},{"}{print $0}' | perl -pe 's/.*"date_last_beat":"?(.*?)"?,"lostmode_status":"(.*?)","last_ip_beat":"?(.*?)"?,"last_lan_ip":?(.*?),"userid":"?(.*?)",*.*/\1\t\2\t\3\t\4\t\5\t\6/')			
		else
			#iPad not assigned.  Use this one.
			MicroParse=$(echo "$output"| awk 'BEGIN{FS=",";RS="},{"}{print $0}' | perl -pe 's/.*"date_last_beat":"?(.*?)"?,"lostmode_status":"(.*?)","last_ip_beat":"?(.*?)"?,"last_lan_ip":"?(.*?)",*.*/\1\t\2\t\3\t\4\t\5/')
		fi

		LASTCHECKIN=$(echo "$MicroParse" |  cut -f 1 -d$'\t' )
		LOSTMODESTATUS=$(echo "$MicroParse" |  cut -f 2 -d$'\t' )
		LAST_IP_BEAT=$(echo "$MicroParse" |  cut -f 3 -d$'\t' )
		LAST_LAN_IP=$(echo "$MicroParse" |  cut -f 4 -d$'\t' )
		USERID=$(echo "$MicroParse" |  cut -f 5 -d$'\t' )
		
		if [ "$DEBUG" = "Y" ]; then
			echo "Returned data from query-> $output"
			echo " ----------- "
			echo "Microparse-> $MicroParse"
			echo " ----------- "
			echo "--LASTCHECKIN--> $LASTCHECKIN"
			echo "--LOSTMODESTATUS--> $LOSTMODESTATUS"
			echo "--LAST_IP_BEAT--> $LAST_IP_BEAT"
			echo "--LAST_LAN_IP---> $LAST_LAN_IP"
			echo "--USERID----> $USERID"
		fi
		
		
		#If device is unassigned then set the USERID to be UNASSIGNED
		if [ ! -n "$USERID" ]; then
			USERID="UNASSIGNED"
		fi
		
		if [ "$LASTCHECKIN" = "null" ]; then
			LASTCHECKIN="NO-DATA"
		else
			#Take Epoch time and convert to hours
			LASTCHECKIN=$(/usr/local/Smillieware/Frameworks/Python.framework/Versions/Current/bin/python3 -c "import datetime; print(datetime.datetime.fromtimestamp(int("$LASTCHECKIN")).strftime('%Y-%m-%d %I:%M:%S %p'))")
		fi
	fi

}

#Format for an iPad Data Dump of JSON
Generate_JSON_AssignDevice() {
cat <<EOF
	{"accessToken": "$MOSYLE_API_key",
	"elements": [ {
        "operation": "assign_device",
    	"id": "$USERIDperIIQ",
        "serial_number": "$DeviceSerialNumber"
		}
		]
}
EOF
}

AssigniPad() {
	#Before starting to grab data lets grab the Bearer Token
	GetBearerToken
		

	echo "-----------------$USERIDperIIQ"
	echo "-----------------$DeviceSerialNumber"	
		
	#This is a new CURL call with JSON data - JCS 11/8/23
	APIOUTPUT=$(curl --location 'https://managerapi.mosyle.com/v2/users' \
		--header 'content-type: application/json' \
		--header "Authorization: Bearer $AuthToken" \
		--data "$(Generate_JSON_AssignDevice)")
	
	CMDStatus=$(echo "$APIOUTPUT" | cut -d ":" -f 4 | cut -d "," -f 1 | tr -d '"' | tr -d '}]})')

	#DEBUGGING
	if [ $"DEBUG" = Y ]; then
		echo "CMD Status--> $CMDStatus"
		echo "APIOUTPUT---> $APIOUTPUT"
		echo "$(Generate_JSON_AssignDevice)"
	        log_line "CMD Status--> $CMDStatus"
                log_line "APIOUTPUT---> $APIOUTPUT"
                log_line "$(Generate_JSON_AssignDevice)"



	fi

	
	if [ "$CMDStatus" = "DEVICES_NOTFOUND" ]; then
		log_line "Device not found in Mosyle.  Can't Assign!"

	elif echo "$APIOUTPUT" | grep -q "UNKNOWN_USER" ; then
			log_line "User not found in Mosyle.  Can't Assign!"	
	
	elif echo "$APIOUTPUT" | grep -q "INVALID_DATA" ; then
			log_line "Bad Data given to API.  Didn't work!"
			echo "$APIOUTPUT >> $LOG"		
				

	elif [ "$CMDStatus" = "COMMAND_SENT" ]; then
		log_line "Command was Successful!"
		
	elif echo "$APIOUTPUT" | grep -q "OK" ; then
		log_line "Command was Successful!"

	else
		MAXASSIGNMENTS=$(echo "$APIOUTPUT" | grep "MAX_ASSIGNMENTS" )

		if [ ! -z "$MAXASSIGNMENTS" ]; then
			log_line "Device is assigned to someone else already.  ($AssignedUName)"

		else

			log_line "Command yeilded Unknown Status ($APIOUTPUT)"
			log_line "$CMDStatus"
		fi
	fi
}

DeviceSerialNumber="NNVX2MM41V"
GetCurrentInfo-ios


