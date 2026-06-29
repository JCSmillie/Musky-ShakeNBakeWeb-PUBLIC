# Musky – Make-A-Ticket (iPad UI)

## Overview
`MuskyMakeATicket_iPad.php` is the user-facing web interface for creating support tickets related to iPads within the Musky environment.  
It gathers device context, user-entered issue details, and category-specific metadata, then passes the structured JSON payload to the Musky Ticket Submission Handler.

## Security Baseline
- The page should bootstrap `web/check_access.php` before rendering user/device context.
- Submit and monitor flow assumes an authenticated Musky session is already established.

---

## Features
- ✅ Dynamic device lookup based on serial (supports single or multiple devices)
- ✅ Contextual question flow based on selected issue category
- ✅ Auto-prefilled “Last Seen” field using Mosyle data when available
- ✅ Intelligent “Issue” generation logic for consistency across reports
- ✅ Theme and layout match the Musky UI (uses `../theme.css`)
- ✅ Seamless hand-off to Ticket Submission Monitor via `localStorage`
- ✅ Closes itself automatically once the monitor window confirms control
