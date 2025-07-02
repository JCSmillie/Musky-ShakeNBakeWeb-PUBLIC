#!/bin/zsh

#This IIQ stuff isn't needed because we can get it from 
#our MOSBasic configurations.
# ####File with IncidentIQ Keys in the form of:
# # apitoken="S0M3KeY"
# # siteid="IIQ_SiteID"
# # baseurl="https://YourSite.incidentiq.com/api/v1.0
# source /usr/local/Smillieware/Hash/.incidentIQ
# #apitoken, siteid, and baseurl all come from the source file above		
IIQ_LookUpTicket() {
	#Here the query to use with the Asset ID
	Query="$baseurl/tickets/$TicketID2LookUp"

	#Run the query again using the AssetID this time.
	InitialQuery=$(curl -s -k -H "$siteid" -H "$Auth" -H "Client: ApiClient" -X GET "$Query")
	
	#If debug is enabled dump data grab to file.
	if [ "$MB_DEBUG" = "Y" ]; then
		echo "$InitialQuery" > "/tmp/$DeviceSerialNumber.IIQLookUpTicket.txt"
		echo "IIQ_LookUpTicket----> /tmp/$DeviceSerialNumber.IIQLookUpTicket.txt"
	fi

	# echo "Ticket Info"
	# echo "---------------==="
	# echo "$InitialQuery"
	
	
	#Ask IIQ if the serial number applied has tickets open.  Use cut to just get back true or false
	TicketNumber=$(echo "$InitialQuery" | grep TicketNumber | head -1 | cut -d ':' -f 2 | cut -d ',' -f 1| tr -d \")
	#Hack off white spaces
	TicketNumber="${TicketNumber//[[:space:]]/}"
	
	#Ask IIQ if the serial number applied has tickets open.  Use cut to just get back true or false
	TicketSubject=$(echo "$InitialQuery" | grep Subject | cut -d ':' -f 2 | cut -d ',' -f 1| tr -d \")
	#Hack off white spaces
	TicketSubject="${TicketSubject//[[:space:]]/}"

	#Ask IIQ if the serial number applied has tickets open.  Use cut to just get back true or false
	LastModifiedByUserDate=$(echo "$InitialQuery" | grep LastModifiedByUserDate | cut -d ':' -f 2 | cut -d ',' -f 1| tr -d \")
	#Hack off white spaces
	LastModifiedByUserDate="${LastModifiedByUserDate//[[:space:]]/}"
	
	#Ask IIQ if the serial number applied has tickets open.  Use cut to just get back true or false
	ForID=$(echo "$InitialQuery" | grep ForId | cut -d ':' -f 2 | cut -d ',' -f 1| tr -d \")
	#Hack off white spaces
	ForID="${ForID//[[:space:]]/}"
	
	#Ask IIQ if the serial number applied has tickets open.  Use cut to just get back true or false
	StatusName=$(echo "$InitialQuery" | grep StatusName | cut -d ':' -f 2 | cut -d ',' -f 1| tr -d \")
	#Hack off white spaces
	StatusName="${StatusName//[[:space:]]/}"	
	
	# echo "Ticket Number-> $TicketNumber"
	# echo "Ticket Subject-> $TicketSubject"
	# echo "Last touch of ticket-> $LastModifiedByUserDate"
	# echo "Current Status-> $StatusName"
	# echo "ID of who it was for-> $ForID"
}

IIQ_UserLookUpbyGID() {
	#Here the query to use with the Asset ID
	Query="$baseurl/users/$ForID"

	#Run the query again using the AssetID this time.
	InitialQuery=$(curl -s -k -H "$siteid" -H "$Auth" -H "Client: ApiClient" -X GET "$Query")
	
	#If debug is enabled dump data grab to file.
	if [ "$MB_DEBUG" = "Y" ]; then
		echo "$InitialQuery" > "/tmp/$DeviceSerialNumber.IIQUserLookUpbyGID.txt"
	fi
	
	#Now because the updating of data fields by the serial appears to be broken 
	#lets get the asset ID
	Ticketfor1stName=$(echo "$InitialQuery" | grep FirstName | cut -d ':' -f 2 | cut -d ',' -f 1 | tr -d \")
	TicketforLastName=$(echo "$InitialQuery" | grep LastName | cut -d ':' -f 2 | cut -d ',' -f 1 | tr -d \")
	#Hack off white spaces
	TicketforLastName="${TicketforLastName//[[:space:]]/}"
	TicketforName=$(echo "$Ticketfor1stName $TicketforLastName")
	#Hack off white spaces
	#TicketforName="${TicketforName//[[:space:]]/}"
	
	#Now because the updating of data fields by the serial appears to be broken 
	#lets get the asset ID
	TicketforEmail=$(echo "$InitialQuery" | grep Email | head -1 | cut -d ':' -f 2 | cut -d ',' -f 1 | tr -d \")
	#Hack off white spaces
	TicketforEmail="${TicketforEmail//[[:space:]]/}"

# 	echo "Ticket is for-> $TicketforName"
# 	echo "Ticket if for email-> $TicketforEmail"
}

IIQ_Lookup() {
	Auth=$(echo "Authorization: Bearer $apitoken")
	Query="$baseurl/assets/serial/$DeviceSerialNumber"
	
	#Do initial query with Serial # and cache the result
	InitialQuery=$(curl -s -k -H "$siteid" -H "$Auth" -H "Client: ApiClient" -X GET "$Query")
	
	if [ -z "$InitialQuery" ]; then
		echo "LOOK UP FAILED."
		echo "AUTH-> $Auth"
		echo "Query-> $Query"

		echo "EPIC FAIL!!!!!  SEE LOGS!"
		exit 1
	fi
	
	#If debug is enabled dump data grab to file.
	if [ "$MB_DEBUG" = "Y" ]; then
		echo "$InitialQuery" > "/tmp/$DeviceSerialNumber.IIQLookupQuery1.txt"
		echo "DATA HAS BEEN EXPORTED TO-> /tmp/$DeviceSerialNumber.IIQLookupQuery1.txt"
	fi

	#Now because the updating of data fields by the serial appears to be broken 
	#lets get the asset ID
	ASSID=$(echo "$InitialQuery" | grep AssetId | cut -d ':' -f 2 | cut -d ',' -f 1 | tr -d \" | head -1)
	#Hack off white spaces
	ASSID="${ASSID//[[:space:]]/}"

	#Here the query to use with the Asset ID
	Query="$baseurl/assets/$ASSID"

	#Run the query again using the AssetID this time.
	InitialQuery=$(curl -s -k -H "$siteid" -H "$Auth" -H "Client: ApiClient" -X GET "$Query")



	#Figure out device status per IIQ
	StatusTypeId=$(echo "$InitialQuery" | grep "\"StatusTypeId\":" | tail -1 | cut -d ':' -f 2 | cut -d ',' -f 1 | tr -d \") 
	StatusTypeId="${StatusTypeId//[[:space:]]/}"
	#If debug is enabled dump data grab to file.
	if [ "$MB_DEBUG" = "Y" ]; then
		echo "ASSETID PER IIQ=$ASSID"
		echo "StatusID of Asset-> $StatusTypeId"
		echo "$InitialQuery" > "/tmp/$DeviceSerialNumber.IIQLookupQuery2.txt"
		echo "DATA HAS BEEN EXPORTED TO-> /tmp/$DeviceSerialNumber.IIQLookupQuery2.txt"
	fi	
	

	if [ "$StatusTypeId" = "83a10b14-c3a9-4f3b-b104-da83276f9101" ]; then
		log_line "Device is IN SERVICE per IIQ."
		ACTION="ASSIGNME"
		HayLookAtMe "--> Device is assignable."
		
	elif [ "$StatusTypeId" = "83a10b14-c3a9-4f3b-b104-da83276f9102" ]; then
		log_line "Device is BROKEN per IIQ.  FAIL."
		ACTION="NOASSIGN"
		HayLookAtMe "--> Device is not assignable...  Listed as BROKEN in IIQ!"


	elif [ "$StatusTypeId" = "83a10b14-c3a9-4f3b-b104-da83276f9107" ]; then
		log_line "Device is IN STORAGE per IIQ.  FAIL."
		ACTION="NOASSIGN"
		HayLookAtMe "--> Device is not assignable...  Listed as IN STORAGE in IIQ!"

		
	elif [ "$StatusTypeId" = "83a10b14-c3a9-4f3b-b104-da83276f9103" ]; then
		log_line "Device is MISSING per IIQ.  FAIL."
		ACTION="NOASSIGN"
		HayLookAtMe "--> Device is not assignable...  Listed as MISSING in IIQ!"

		
	elif [ "$StatusTypeId" = "83a10b14-c3a9-4f3b-b104-da83276f9106" ]; then
		log_line "Device is RETIRED per IIQ.  FAIL."
		ACTION="NOASSIGN"
		HayLookAtMe "--> Device is not assignable...  Listed as RETIRED in IIQ!"

		
	elif [ "$StatusTypeId" = "883a10b14-c3a9-4f3b-b104-da83276f9105" ]; then
		log_line "Device is SOLD per IIQ.  FAIL."
		ACTION="NOASSIGN"
		HayLookAtMe "--> Device is not assignable...  Listed as SOLD in IIQ!"

		
	elif [ "$StatusTypeId" = "1d04f70f-b566-7112-c4af-1b9c37419570" ]; then
		log_line "Device is STOLEN, but paid for per IIQ.  FAIL."
		ACTION="NOASSIGN"
		HayLookAtMe "--> Device is not assignable...  Listed as STOLEN, but paid for in IIQ!"

		
	elif [ "$StatusTypeId" = "83a10b14-c3a9-4f3b-b104-da83276f9104" ]; then
		log_line "Device is STOLEN per IIQ.  FAIL."
		ACTION="NOASSIGN"
		HayLookAtMe "--> Device is not assignable...  Listed as STOLEN in IIQ!"

		
	else
		log_line "Unknown Device Status ($StatusTypeId)"
	fi


	#Ask IIQ if the serial number applied has tickets open.  Use cut to just get back true or false
	HasTickets=$(echo "$InitialQuery" | grep HasOpenTickets | cut -d ':' -f 2 | cut -d ',' -f 1)
	#Hack off white spaces
	HasTickets="${HasTickets//[[:space:]]/}"
	
	#Ask IIQ if iPad has the PreviousOwner field attributed.  Will return nothing if iPad is deployed
	#and a users ID number if it is NOT deployed.  ID number is that of the former user.  We only care
	#about this to know if the ipad is deploy or not.  Value of the data doesn't matter.
	PreviousOwner=$(echo "$InitialQuery" | grep PreviousOwner | cut -d ':' -f 2 | cut -d ',' -f 1 | tr -d \")
	PreviousOwner="${PreviousOwner//[[:space:]]/}"
	
	#Ask IIQ the username of who the iPad is assigned to.  This could be either current or previous
	#depending on the state of the iPad (assigned/unassigned)
	DeviceAssignd=$(echo "$InitialQuery" | grep "Username\":" | head -1 | cut -d ':' -f 2 | cut -d ',' -f 1 | tr -d \")
	#Hack off white spaces
	DeviceAssignd="${DeviceAssignd//[[:space:]]/}"
	
	#Ask IIQ if the serial number applied has a status of Stolen.
	#If its status is STOLEN then the AssetStatusTypeId will be-> 83a10b14-c3a9-4f3b-b104-da83276f9104
	IsStolen=$(echo "$InitialQuery" | grep "AssetStatusTypeId" | grep "83a10b14-c3a9-4f3b-b104-da83276f9104" | cut -d ':' -f 2 | cut -d ',' -f 1  | tr -d \")
	#Hack off white spaces
	IsStolen="${IsStolen//[[:space:]]/}"

	#Ask IIQ if there is any reference to Poolname.  If so it means this device is a sparepool device and has been issued.
	TicketID2LookUp=$(echo "$InitialQuery" | grep PoolName | cut -d ',' -f5 | cut -d ':' -f 2 | tr -d \" | tr -d }] | cut -d '\' -f 2)
	# #Hack off white spaces
	TicketID2LookUp="${TicketID2LookUp//[[:space:]]/}"

	LoanerIssuedDate=$(echo "$InitialQuery" | grep PoolName | cut -d ',' -f4 | cut -d ':' -f 2 | tr -d \" | tr -d }] | cut -d '\' -f 2)
	# #Hack off white spaces
	LoanerIssuedDate="${LoanerIssuedDate/[[:space:]]/}"
}
