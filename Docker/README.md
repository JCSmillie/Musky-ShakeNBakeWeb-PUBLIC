# Musky-MOSBasic-Docker Setup
Warning up front.  This is my FIRST Docker project and I'm sure I got all sorts of stuff wrong, but I can deal with that as we get there.

# To Use:
Download the appropriate Zip from this Repo.  Different versions (which may not be public) could be available.  Now go to your Docker Server. All of mine are //Fedora release 41 (Forty One)//:
 - Go to where you keep your dockers.  Make a folder for Musky.  Enter that folder.  
 - Unzip your Docker set into this directory.
 - Run the script marked //setup-musky-lamp.sh//
 - Enter Devilbox directory.  Type in //set -a && source .env && set +a// and //docker compose up//.
 
 ## Example
 <code>
     mkdir /usr/local/dockerdetails/Musky; cd /usr/local/dockerdetails/Musky;
     curl <<LINK TO YOUR ZIP>> .
     unzip <<YourDockerZip.zip>> 
     ./Setup-musky-lamp.sh
     cd devilbox; set -a && source .env && set +a; docker compose up
</code>

## Public Musky Docker Zip Users
**NOTE IF USING TYHE PUBLIC ZIP** you need to edit these files BEFORE running the setup script.  Everything else should be fine.:
  - Check cfg/CommonConfigurations/musky/config.php
  - Check cfg/CommonConfigurations/musky/decode_tags.php <-Fill in with your Own Tags and what they mean.
   - Check cfg/CommonConfigurations/musky/loaner_constants.php
  - Check cfg/CommonConfigurations/.incidentIQ <-Needs YOUR IIQ API Data and Link
  - Check cfg/CommonConfigurations/.MosyleAPI <-Needs YOUR MosyleAPI username, password, and API Token.
  
  
