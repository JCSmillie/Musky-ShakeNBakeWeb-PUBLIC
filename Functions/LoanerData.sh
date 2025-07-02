#!/bin/zsh

# Enable debugging if needed
MB_DEBUG="Y"

# Safer BAGCLI setup
BAGCLI_WORKDIR="$(dirname "$(realpath /usr/local/bin/mosbasic)")"
export BAGCLI_WORKDIR



source "$BAGCLI_WORKDIR/config"
source "$BAGCLI_WORKDIR/common"
source ".MuskyConfig"

# Load Mosyle API Key
source "$LOCALCONF/.MosyleAPI"
APIKey="$MOSYLE_API_key"

source "/AddonStorage/webcontent/MuskyFunctions/IIQ_Support_Functions.sh"


#Determine which group we should be looking up
if [ -z "$1" ]; then
	LoanerGroup="GSDIT-Loaner"
else
	LoanerGroup="$1"
fi

echo "Will query loaner group: $LoanerGroup"

# Load IIQ API Key
if [ ! -s "$LOCALCONF/.incidentIQ" ]; then
    echo "HEADS UP: No local IncidentIQ API info available. See README for setup."
    exit 1
else
    source "$LOCALCONF/.incidentIQ"
    IIQAuth=$(echo "Authorization: Bearer $apitoken")
fi

# Function to get bearer token and fetch device data
GetCurrentInfo-ios() {
    GetBearerToken
	echo "DEBUG-> Auth Token->$AuthToken "
	echo "DEBUG-> Mosyle API Key-> $MOSYLE_API_key"
    curl -s --location 'https://managerapi.mosyle.com/v2/listdevices' \
        --header 'content-type: application/json' \
        --header "Authorization: Bearer $AuthToken" \
        --data '{
            "accessToken": "'"$MOSYLE_API_key"'",
            "options": {
                "os": "ios",
                "tags": "'"$LoanerGroup"'",
                "page": "1",
                "specific_columns": "deviceudid,serial_number,asset_tag,userid,last_ip_beat,date_last_beat,needosupdate,enrollment_type"
            }
        }'
}

# Convert JSON to CSV using Python
MosyleCSV() {
    printf '%s' "$json_response" | python3 -c '
import sys, json, csv, io

# Read raw data first
raw = sys.stdin.buffer.read()

# Decode raw bytes into safe unicode (ignore bad chars)
text = raw.decode("utf-8", errors="ignore")

# Now load JSON safely
try:
    data = json.loads(text)
except Exception as e:
    print(f"❌ Error loading JSON: {e}", file=sys.stderr)
    sys.exit(1)

devices = data.get("response", {}).get("devices", [])

writer = csv.writer(sys.stdout, lineterminator="\n")
writer.writerow(["deviceudid", "serial_number", "asset_tag", "userid", "last_ip_beat", "date_last_beat", "needosupdate", "enrollment_type"])
for d in devices:
    writer.writerow([
        d.get("deviceudid", ""),
        d.get("serial_number", ""),
        d.get("asset_tag", ""),
        d.get("userid", ""),
        d.get("last_ip_beat", ""),
        d.get("date_last_beat", ""),
        d.get("needosupdate", ""),
        d.get("enrollment_type", "")
    ])
'
}

log_line(){
	echo "$1"
}

HayLookAtMe(){
	log_line
}

# MAIN WORK
CSVdataRELOADED=""

# Fetch JSON response
json_response=$(GetCurrentInfo-ios)

# Remove any junk before real JSON
json_response=$(echo "$json_response" | sed -n '/^{/,$p')

# Check if cleaned response is empty
if [[ -z "$json_response" ]]; then
    echo "❌ Cleaned JSON response is empty. Exiting."
    exit 1
	
elif [[ -z "$MB_DEBUG" ]]; then
	echo "DEBUG--> Mosyle Data PreProcess-> $json_response"
fi

# Convert Mosyle JSON to CSV
#MosyleDataCSV=$(MosyleCSV)
#Make an Echo php can feed off of for Status.
echo "<<MUSKY-BACKCHANNEL>> (STEPS:1of3)Querying Mosyle for Tagged devices"

MosyleDataCSV=$(printf '%s' "$json_response" | /usr/local/github/Musky-ShakeNBakeWeb/Functions/ConvertLoanerData.py )

echo "DEBUG-> $MosyleDataCSV"

if [ -z "$MosyleDataCSV" ]; then
	echo "No Group Data reported by Mosyle.  FAIL."
	exit 1
fi

# Loop over each device
echo "<<MUSKY-BACKCHANNEL>> (STEPS:2of3)Parsing Reported Data."
echo "$MosyleDataCSV" | while IFS= read -r OneDeviceMosyleDataCSV; do

    # Skip empty lines
    if [[ -z "$OneDeviceMosyleDataCSV" ]]; then
        continue
    fi

    # Parse line into fields array
    IFS=',' read -r -A DeviceFields <<< "$OneDeviceMosyleDataCSV"

    # Assign fields
    UDID="${DeviceFields[1]}"
    DeviceSerialNumber="${DeviceFields[2]}"
    ASSETTAG="${DeviceFields[3]}"
    USERID="${DeviceFields[4]}"
    LAST_IP_BEAT="${DeviceFields[5]}"
    LASTCHECKIN="${DeviceFields[6]}"
    NEEDOSUPDATE="${DeviceFields[7]}"
    ENROLLMENT_TYPE="${DeviceFields[8]}"
	
	echo "<<MUSKY-BACKCHANNEL>> (STEPS:2of3)Parsing device $ASSETTAG"

    # Clean enrollment type
    ENROLLMENT_TYPE="${ENROLLMENT_TYPE//[[:space:]]/}"

    # Skip header
    if [[ "$DeviceSerialNumber" = "serial_number" ]]; then
		echo "<<NODATA>>"
		Data2Add="UDID,Serial Number,Asset Tag,Mosyle User,IIQ User,Last Ip of Connection,Last Check In Date,Needs iOS Update,Enrollment Type,Device Status,Ticket Number"
		
    # Handle device state
	elif [[ "$ENROLLMENT_TYPE" = "GENERAL" ]]; then
		Data2Add="$UDID,$DeviceSerialNumber,$ASSETTAG,$USERID,IIQNOTASSIGN,$LAST_IP_BEAT,$LASTCHECKIN,$NEEDOSUPDATE,$ENROLLMENT_TYPE,AVILABLE,NoInfo"

	else
		IIQ_Lookup
		IIQ_LookUpTicket
		IIQ_UserLookUpbyGID
		TicketforEmail=$(echo "$TicketforEmail" | cut -d@ -f1)
		
		#We need to think about. a few things here.
		#At this point we know Mosyle says device is
		# assigned.  Now we use IIQ info to see if this
		#is a LEGIT handed out Loaner.
		if [[ "$USERID" =  "$TicketforEmail" ]] && [[ ! -z "$TicketNumber" ]]; then
			#Ticket exists.  User on iPad and User per ticket Match.  This is GOOD
			Data2Add="$UDID,$DeviceSerialNumber,$ASSETTAG,$USERID,$TicketforEmail,$LAST_IP_BEAT,$LASTCHECKIN,$NEEDOSUPDATE,$ENROLLMENT_TYPE,NOASSIGN,$TicketNumber"
			
		else
			#Any other status without a match above is NOASSIGN (BAD.)  We
			#will have logic on website to cover explaining this.
			Data2Add="$UDID,$DeviceSerialNumber,$ASSETTAG,$USERID,$TicketforEmail,$LAST_IP_BEAT,$LASTCHECKIN,$NEEDOSUPDATE,$ENROLLMENT_TYPE,NOASSIGN,$TicketNumber"
		fi
		
		echo "<<MUSKY-BACKCHANNEL>> (STEPS:2of3)Data grab complete device $ASSETTAG"
    fi

    # Add to final CSV
    CSVdataRELOADED+="$Data2Add"$'\n'

done

echo "<<MUSKY-BACKCHANNEL>> (STEPS:3of3) Full data grab complete.  POSTING"

# Finish progress
echo ""
echo "Processing complete!"
echo ""

# Show final result
echo "FINAL CSV DATA:"
echo "===================="
echo "$CSVdataRELOADED"

# Optionally save to file
# echo "$CSVdataRELOADED" > final_output.csv