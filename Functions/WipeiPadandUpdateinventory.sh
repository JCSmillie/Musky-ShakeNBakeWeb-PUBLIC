#!/bin/bash
#
# WipeiPadandUpdateinventory.sh
#
#
#
# This script was orginally wrote to be run at the command line and then prompted
# for asset tags to be scanned in.  Script then would tell IIQ to unassign, make note of
# the unassign, and then also tell Mosyle to wipe the device.
#
# JCS - I know I wrote it, but no idea when.
# JCS - 04/03/2025
BAGCLI_WORKDIR=$(readlink /usr/local/bin/mosbasic)
#Remove our command name from the  above
BAGCLI_WORKDIR=${BAGCLI_WORKDIR//mosbasic/}

export BAGCLI_WORKDIR

source "$BAGCLI_WORKDIR/config"

 #shellcheck source=common
. "$BAGCLI_WORKDIR/common"


#source Helper File locations
LOCATION_SCRIPT_PATH=$(grep "^\$LOCATION_SCRIPT_PATH" ../web/config.php | sed -E "s/^\$LOCATION_SCRIPT_PATH\s*=\s*'([^']+)'.*/\1/")
source "$LOCATION_SCRIPT_PATH/IIQ_Support_Functions.sh"
#source "/AddonStorage/webcontent/MuskyFunctions/IIQ_Support_Functions.sh"
#source "/Users/jsmillie/GitHub/Musky-ShakeNBakeWeb/Functions/IIQ_Support_Functions.sh"

#IIQ Variables
StatusID='83a10b14-c3a9-4f3b-b104-da83276f9106'  #Retired Status
LocationId='f007ac90-1ac3-ea11-8b03-0003ffe4d4cc' #GHS
RoomId='dfc65782-5abc-4bf0-9ea4-953d84b662b7' #Summer 2025 BB

NewTagz="RETIRED-2025"

GIVENASSETTAG="$1"
######################################
##  JSON - Formats data to be submitted
######################################
generate_post_data_Verification()
{
	cat <<EOF
   {"AssetId":"$AssetID",
    "AssetVerificationTypeId":"web-manual",
    "CreatedDate":null,
    "VerifiedBy":{
        "UserId":"93ca02b8-12e0-47b0-a0e8-d6ec9bea9ce2" },
    "VerifiedByUserId":"93ca02b8-12e0-47b0-a0e8-d6ec9bea9ce2",
	"Comments":'Device processed to be retired.',
    "LocationId":"$LocationId",
    "IsSuccessful":true }
EOF
}

generate_post_data_Location() 
{
	cat <<EOF
	{"AssetIDs":["$AssetID"],
    "Request":{
        "LocationId":"$LocationId",
        "RoomId":"$RoomId",
        "LocationDetails":'' }
	}
EOF
}

Generate_JSON_Tagz() {
cat <<EOF
	{"accessToken": "$MOSYLE_API_key",
	"elements": [ {
        "serialnumber": "$DeviceSerialNumber",
    	"tags": "$NewTagz"
	} ]
}
EOF
}

######################################
##  Functions
######################################
log_line(){
	echo "DALOG-> $1"
}

AddVerifcationData() {
	InitialQuery=$(curl --location --request POST "$baseurl/assets/$AssetID/verifications/new" \
	--header 'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36' \
	--header 'client: WebBrowser' \
	--header 'content-type: application/json' \
	--header 'accept: application/json, text/plain, */*' \
	--header 'accept-language: en-US,en;q=0.9' \
	--header "Authorization: Bearer $apitoken" \
	--data "$(generate_post_data_Verification)")
}

ChangeStatus2Storage(){
	InitialQuery=$(curl --location --request POST "https://gatewayk12.incidentiq.com/api/v1.0/assets/$AssetID/status/$StatusID" \
	--header 'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36' \
	--header 'client: WebBrowser' \
	--header 'content-type: application/json' \
	--header 'accept: application/json, text/plain, */*' \
	--header 'accept-language: en-US,en;q=0.9' \
	--header "Authorization: Bearer $apitoken" \
	--data '{}' )
}

UnAssignDevice(){
	InitialQuery=$(curl --location --request POST "https://gatewayk12.incidentiq.com/api/v1.0/assets/$AssetID/remove-owner" \
	--header 'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36' \
	--header 'client: WebBrowser' \
	--header 'content-type: application/json' \
	--header 'accept: application/json, text/plain, */*' \
	--header 'accept-language: en-US,en;q=0.9' \
	--header "Authorization: Bearer $apitoken" \
    --data '{}' )
}

SetLocationData() {
	curl --location --request POST 'https://gatewayk12.incidentiq.com/api/v1.0/assets/bulk/set-location' \
	--header 'user-agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36' \
	--header 'client: WebBrowser' \
	--header 'content-type: application/json' \
	--header 'accept: application/json, text/plain, */*' \
	--header 'accept-language: en-US,en;q=0.9' \
	--header "Authorization: Bearer $apitoken" \
	--data "$(generate_post_data_Location)"
}

SetDeviceTagz() {
	#Make sure all variables have NO SPACES!!!!
	NewTagz="${NewTagz//[[:space:]]/}"
	DeviceSerialNumber="${DeviceSerialNumber//[[:space:]]/}"

	
	GetBearerToken
#This is a new CURL call with JSON data - JCS 11/8/23
APIOUTPUT=$(curl --location 'https://managerapi.mosyle.com/v2/devices' \
	--header 'content-type: application/json' \
	--header "Authorization: Bearer $AuthToken" \
	--data "$(Generate_JSON_Tagz)" ) 
}


if [ -z "$GIVENASSETTAG" ]; then
	echo "No tag provided."
	exit 1
else
	echo "Acting on $GIVENASSETTAG"


	#Find by Asset Tag and return serial.
	FoundItIOS=$(cat "$TEMPOUTPUTFILE_MERGEDIOS" | cut -d$'\t' -f 2-5,7-8 | grep "$GIVENASSETTAG")
	#Strip FoundIt down to JUST THE SERIAL #
	DeviceSerialNumber=$(echo "$FoundItIOS" | cut -d$'\t' -f 1)



	#Figure out what the serial numbers asset is
	IIQ_Lookup
	
	if [ "$StatusTypeId" = "83a10b14-c3a9-4f3b-b104-da83276f9106" ]; then
		echo "Device ($DeviceSerialNumber) already has been retired.... Not Running Again."
		log_line "$DeviceSerialNumber has been retired."
		exit 0
	fi
	
	AssetID="$ASSID"

	log_line "$DeviceSerialNumber adding verification record to IIQ."

	AddVerifcationData

	UnAssignDevice

	ChangeStatus2Storage

	SetLocationData

	#Disable Lost Mode
	/usr/local/bin/mosbasic lostmodeoff "$GIVENASSETTAG"
	
	#WIPE DEVICE
	log_line "$DeviceSerialNumber telling Mosyle to wipe/Limbo device."
	/usr/local/bin/mosbasic ioswipe --norts "$GIVENASSETTAG"
	
	#Add RETIRED TAG
	SetDeviceTagz

	log_line "$DeviceSerialNumber has been retired."
fi