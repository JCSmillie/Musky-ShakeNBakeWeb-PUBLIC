<img src="https://github.com/JCSmillie/Musky-ShakeNBakeWeb-PUBLIC/blob/main/mascot.png?raw=true" alt="Musky our Fearless Mascot." width="200"/>

# What is Musky?
Musky is a home grown tool created to support our student help desk and faculty who support iPads. In its current configuration the goal is to provide a tool that lets our help desk students experience "Quick Wins" when helping end users with technology problems.  Finding iPads is one of the most common reasons people come to the help desk.  Musky has an extremely easy Lost device ability.  

Musky went live at Gateway School District for production use in April 2025 almost one year from when the first line of code was written to see if I could do any of this.  Its actively deployed still today and will be utilized by Faculty next year in addition to our Help Desk kids.

# Why was Musky Created?
Winter 2024 we noticed that there was a significant delay between when ASM learned of new students and when those students would be available to assign a new device to.  So effectly, at the time by hand, you would do the following:
1. Go to IncidentIQ (ticket system,) lookup the student who needs the device, assign device in IIQ.
2. Go to Mosyle.  Assign device to student there.  If student is missing wait 12-24hrs for student to show up.

As you can imagine this creates backlog because if the student doesn't exist in Mosyle then the device can't be assigned completely.  To address this issue I wrote a very simple cgi script.  This script not only automated the two steps noted above but also makes sure the end user DOES EXIST in Mosyle and if not will create them on the fly and then assign the iPad.  Again I can't stress now simple this script was.  Simple ZSH.  You can find this script in the Examples directory as reference.  Effectively I put this on my server and protected it with an htaccess file.  As the server already had MOSBasic installed it just works.  So with these in place I could go to say http://localhost/assign.cgi?**SERIALNUMBER** which would trigger the script and assign the device.  No worry if account exists.  As long as its proper in IncidentIQ it will be proper in Mosyle.  With this working I also wrote wipeRTS.cgi (also in the Example directory) which is called the same way but uses Return to Service mode to wipe the iPad and put it back to Limbo (unassigned but waiting) state.  Musky is the next interation of those scripts.

<!-- NOTE --> Both assign.cgi and wipeRTS.cgi are in the Example directory.  They are definately NOT drop in and use as they depend on MOSBasic and some other custom stuff not published.  I list these files only to show you how easily this can all be done.


Something else I should mention and its where this project gets it's name from.  My Father, George E. Smillie, had a nasty health episode that started in September 23' which pretty much ensured my sister and I were daily visitors to a hospital and spending hours with our Dad at his bed side.  assign.cgi and wipeRTS.cgi was written, tested, and whatever else during this time.  I can pretty much tell you I was at Selec t Specialty in Latrobe Hospital when these were created.  My father passed in April 2024.  The mind and heart were so willing but the rest of his body just couldn't do it anymore.  My Dad's nickname was Musky in reference to a muskrat.  I never got a chance to get to the bottom of that nickname, but as this project is dedicated to my dad the name seemed proper.  The logo is what chat GPT thinks my dad would look like as a Muskrat.  

## How was Musky Created?
So not to toot my own horn too much but I'm pretty decent at shell languages.  I've been writing shell scripts for a very long time, however I have absolutely no PHP game.  To fill in my gaps I have used ChatGPT to create the majority of the PHP code you see.  All the shell scripts depended on as well as MOSBasic have no ChatGPT in them.  Back before the internet was so vast I was into CircleMUDs.  When it came time to build our own MUD I learned what I needed to know about C+ by looking at other people's code (posted to the CircleMUD mailing list,) hack it to do something different, and keep hacking at it until it did what I wanted it to do.  I feel this is pretty much the same thing.  I tell ChatGPT how my script works, how I want it interacted with, and it puts out the code.  I use GitHub Desktop so I can easily compare changes to understnad what changed and why.  Over time of doing this I'm starting to pickup **SOME** PHP knowledge and even correcting my own errors as we go along but it will be a long time before I can just write some PHP.  That being said I see nothing wrong with using AI to code as long as you proof read and try to understand what it happening to the best of your knowledge.  Working with ChatGPT is like having a conversation with an amazingly smart person who has no real world skill set and often makes simple mistakes.  You have to read along and catch those mistakes, but the code is completely usable.

# Install:

## Prerequisits:
* Linux server with Apache to run MOSBasic and Musky out of.  
* MOSBasic installed, configured, and tested to be working.

Files to Edit to make this work in your environment:
The following files must be edited for your environment.  Files are heavily commented:
* **PROJECT**/web/DeviceManager/decode_tags.php <-List of any notable tags (as they appear in Mosyle) and what should be displayed if that tag is found.
* **PROJECT**/web/config.php <-Base configuration.  This is where you tell Musky where to find your MOSBasic install, local locations for Musky's Support scripts, log locations, etc.
* **PROJECT**/web/loaner_constants.php <-List of Loaner Pools to be displayed and what to show those loaner pools name as.  See understanding Loaners.
* **PROJECT**/web/mascot.png & musky_favicon.png <-Musky Themed graphics.  You can replace these, but I ask you don't mess with the about.php file.
  
Open up your browser to whereeveryouputit/index.php and away you go.

## SECURING ACCESS:
Musky pages support .htaccess restrictions and is the recommended way to set this up.  Onsite (and in various places in the code comments) you can see I have our own 2FA solution tied in there.  That documentaion will not be provided at this time.  Search for apache .htaccess; you should find a ton of info.



# Pages Available:
This is the landing page.  Not much to look at:

<img src="https://github.com/JCSmillie/Musky-ShakeNBakeWeb-PUBLIC/blob/main/Imagery/main.png" alt="Landing Page" width="600"/>

## DeviceManager Page:
<img src="https://github.com/JCSmillie/Musky-ShakeNBakeWeb-PUBLIC/blob/main/Imagery/DeviceManager.png" alt="Device Manager View." width="600"/>
This is the core of what will become the Musky suite.  This page (**PROJECT**/Web/DeviceManger/index.php) is where you will look up devices (by asset tag as noted in your Mosyle install) and perform most actions.  Post lookup the buttons available are:
* Wipe Device -> Tell iPad to use RTS to wipe and return to Limbo state.
* Enable Lost Mode -> Eanble lost mode.  
* Play Sound -> Only appears when device is in Lost mode.
* Show Location -> Only appears when device is in lost mode  Query's location and opens a popup in Apple Maps to show location.
* Assign Device -> Assign iPad.  This button is disabled in the Public version at this time, but on the road Map.
* Restart iPad -> Restart iPad (unavailable if device is in Lost Mode)
* Look Up Again -> Rerun query in Mosyle
* DEBUG -> Shows all the CSV and other output data.  

And in the bottom left corner the PROBLEM button for reporting issues.  This button is special in that when clicked a popup will appear asking you the issue.  You type and submit.  The submission is then emailed to the address listed in config.php with a screenshot of how Musky was as of the report.  

## Loaners Page
<img src="https://github.com/JCSmillie/Musky-ShakeNBakeWeb-PUBLIC/blob/main/Imagery/LoanerView.png" alt="Loaner Device View." width="600"/>
This page allows group actions.  Mainly used to get a list of preselected groups and then you can click the assset tag to get more info on that particular device:  Buttons available are:
* Mass Wipe Selected
* Verify Assignment  <-Disabled.  Future.
* Message User <-Disabled.  Future.
* Reload Data

### Understanding "Loaners":
In our school district we have pool of iPads which are loaned out if a student has a breakage or has forgotten their device at home.  These "Loaner" iPads are setup the same way as everything else here.  They are kept in a cabinet in each Elementary, in limbo state, until needed.  We do not use Shared iPad mode for these.

In Mosyle we define our loaner devices with a tag.  That tag is what we "Search" for when we utilize the Loaners page.  So for example all the Loaners at Ramsey Elementary have the tag RAM-LOANER.  So if we would go to the loaner page and select "Ramsey Loaner" from the pull down menu its going to search for that tag and return device that has it.  This results in a nice list explaining pretty much where the loaners are: IE in the cabinet, on loan, or broken.  

I also have a cart of iPads deployed in Art at the High School.  It has a tag.  It too can be visited/accessed through the loaner page since we reference by tag.  To add I just edit **PROJECT**/web/loaner_constants.php and add the additional tag info.

When looking at the loaner page you can see that the asset tags are clickable.  Doing so will open a new page with Musky Device Manager showing that particular iPad.


## 3rd Party Modules:
In Device Manager there is a section titled 3rd Party Modules.  Placing a PHP file in **PROJECT**/Web/DeviceManger/Modules will allow that code to appear in this window.  Currently in this directory all of the included script names end in .DISABLED which tells the page not to load the module.  3rd Party modules when launched have full access to any of the data we looked up in Musky prior to running the 3rd party module.  

# Whats not working/Road Map:
* Theme selection is suppose to follow users page to page, but doesn't.  Totally a bug but something I have to fix.
* Slack Settings are in config.php but I still have to revisit how thats going to work.
* Class listings so Teacher can login, see their class (like the Loaners panel) at a glance.
* 2FA Auth against common systems?
* Docker Image of MosBASIC and MUSKY together allowing quick deployment.
* Make Assign Device button work.
* assign.cgi and wipeRTS.cgi need rewritten to be more graphical.
* Expanded/additional logging.
* Write 3rd party Module guide
* DOCUMENT TONS OF STUFF.
