Loaner Device Manager – /Loaners/

A dedicated portal for managing Loaner iPads at Gateway School District.
Supports viewing, searching, reporting problems, and multi-device mass actions.

------------------------------------------------------------

Project History / How We Got Here

1. Initial Goals
- Create a dedicated loaner iPad management portal.
- Base structure on DeviceManager /DeviceManager/index.php, but customized.
- Use shared config and theme assets (e.g., theme.css, mascot.png).
- Dynamically load device data from:
  /AddonStorage/webcontent/MuskyFunctions/LoanerData.sh <LoanerPool>

2. File Structure Setup

Loaners/
├── index.php            # Main page (device display, multi-select, etc.)
├── loaner_helpers.php   # Helper functions to decode data fields
├── loaner_utils.php     # CSV parsing utilities
├── sidebar.php          # Sidebar HTML/UI (theme, actions)

3. Core Features Added

- Dynamic Loaner Pool Selector (CSE, EV, RAM, UP, GMS, DistrictIT)
- Theme Switching (Light, Dark, Musky, Gator Time)
- Clickable Asset Tags -> Open /DeviceManager/index.php?assettag=XXXX
- Problem Button (Screenshots current page for issue reports)
- More Data / Less Data toggle (shows/hides extra fields)

4. Data Handling

- Raw output from shell script processed after ====================.
- CSV parsing extracts device info.
- Built-in field decoders:
  - Last Check-In time ("10 min ago", "3 hrs ago")
  - iOS Update needed or Up To Date
  - Campus vs Off-Campus based on IP list
  - User matching (Mosyle User vs IIQ User)

5. Multi-Device Action Support

- Checkbox column added for device row selection.
- Dynamically builds 3 variables when checkboxes are selected:
  MultipleUDIDz    # Comma-separated list of selected UDIDs
  MultipleSerialz  # Comma-separated list of Serial Numbers
  MultipleTagz     # Comma-separated list of Asset Tags

- New Action Buttons added above Reload/More/Debug:
  - Wipe Device -> Mass wipe using:
    $MOSBASIC_PATH ioswipe --mass "$MultipleUDIDz"
  - Verify IIQ (Placeholder, disabled for now)
  - Send Message (Placeholder, disabled for now)

Button Behavior:
- Wipe Device: Enabled if any selected
- Reload Data: Disabled if any selected
- Verify/Message: Always disabled (placeholders)

6. Modularity & Future Proofing

- Moved parsing and decoding logic out of index.php into helper files.
- Sidebar HTML isolated for easier UI updates.
- Easily extendable for future mass actions or API calls.

------------------------------------------------------------

Code Modules Overview

- index.php             Main orchestrator; includes helpers, builds page
- loaner_helpers.php    Decode Last Checkin, iOS Updates, IPs, User matches
- loaner_utils.php      Parse shell CSV output safely
- sidebar.php           Theme Switcher, Pool Selector, Multi-Action Buttons

------------------------------------------------------------

Key Usage Notes

- Multi-Select Mass Wipe: Confirmed working — builds shell command.
- Verify IIQ / Send Message: Placeholder buttons, ready for future.
- Problem Reporting: Full screenshot-based reporting integrated.
- Theme Persistence: Cookie-based; reload safe.

------------------------------------------------------------

Future Enhancements

- Actually trigger real mass Wipe commands.
- Build IIQ Verification and Messaging features.
- Export device lists to CSV.
- Add mass Lost Mode, Restart options.

------------------------------------------------------------

Summary

- Modular, Expandable, Production-grade.
- Supports single and multi-device workflows.
- Full documentation and future readiness.

------------------------------------------------------------

Author: Jesse Smillie / Gateway School District
Built: April 2025
Dedicated to: Musky's Legacy ❤️

------------------------------------------------------------

END OF README
