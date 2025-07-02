#!/bin/zsh

DeviceSerialNumber="$1"
MB_DEBUG="Y"
DEBUG="Y"

source "../../../ConfigFiles//Mosyle_Support_Functions.sh"
source "../../../ConfigFiles/IIQ_Support_Functions.sh"
source "../../../ConfigFiles/.incidentIQ"
source "../../../ConfigFiles/.MosyleAPI"


log_line() {
	echo "<pre>$1</rpre>"

    LINE=$1
    TODAY=`date '+%a %x %X'`
    #Print on stdout
    #echo "$TODAY =====>$LINE"
    #Log to file
    echo "iPadWipeRTS.sh ++> $TODAY =====> $LINE" >> ../../../ConfigFiles/ipadactions.txt
}


HayLookAtMe() {
	echo "<strong>$1</strong>"

}

echo "Content-type: text/html"
echo ""
echo "<html><head><title>iPad Wipe w/RTS"
echo "</title></head><body>"



if [ -z "$DeviceSerialNumber" ]; then
	echo '<body style="background-color:red;">'
	echo ' <font size="+2">'
	echo "<strong>No Serial given or bad serial!!!</strong>"
	echo '</font>'

	exit 1

else
	echo "<h1>Device serial number is $DeviceSerialNumber</h1>"
	#Query IncidentIQ
	IIQ_Lookup
fi


if [ "$ACTION" = "NOASSIGN" ]; then
	echo '<body style="background-color:red;">'
	echo ' <font size="+2">'
	log_line "ACTION variable set to NOASSIGN.  See above in the logs."
#	HayLookAtMe "ACTION variable set to NOASSIGN.  Check logs...  FAIL"

# elif  [ -z "$DeviceAssignd" ]; then
# 	log_line "IncidentIQ doesn't think this device ($ASSTAG) is assigned to anyone."
# 	HayLookAtMe "${Yellow}GSD Device #$ASSTAG ($DeviceSerialNumber)-> IIQ says device is not deployed.  Not Assigning."
# 	#echo "$PreviousOwner / $DeviceAssignd"

elif [ ! -z "$IsStolen" ]; then
	log_line "IIQ says $ASSTAG is known to be Stolen.  Not Assigning."
	#HayLookAtMe "${Red}IIQ says $ASSTAG is stolen.  Not Assigning."

	echo '<body style="background-color:red;">'
	echo ' <font size="+4">'
	echo "<strong>IIQ says $ASSTAG is stolen..  Not Wiping... and forgetting I know you...</strong>"
	echo '</font>'

	exit 1

elif [ "$DeviceAssignd" ] || [ -z "$DeviceAssignd" ]; then

	USERIDperIIQ=$(echo "$DeviceAssignd" | cut -d '@' -f1 )
	log_line "Per IIQ $DeviceSerialNumber is assigned to $USERIDperIIQ and listed as DEPLOYABLE and CAN BE WIPED."
	GetCurrentInfo-ios

	log_line "Mosyle says this device ($DeviceSerialNumber) is currently assigned to $USERID"

	if [ ! -z "$DUDID" ]; then
		echo "Device CAN be Wiped."
		rtswipeipad

	else
		log_line "Device ($DeviceSerialNumber) cannot be wiped this way.  See Logs..."
		exit 1
	fi

else
	log_line "IIQ lookup for $ASSTAG ($DeviceSerialNumber) gave no reason to not wipe by lookup.  IsStolen-> $IsStolen / DeviceAssignd-> $DeviceAssignd"
	#HayLookAtMe "${Yellow}IIQ lookup for $ASSTAG ($DeviceSerialNumber) gave no reason to assign by lookup.  Doing nothing."

fi



echo "THIS IS THE LAST LINE OF CODE"