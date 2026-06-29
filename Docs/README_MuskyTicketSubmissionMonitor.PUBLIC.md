# Musky – Ticket Submission Monitor

## Overview
`MuskyTicketSubmissionMonitor.php` provides real-time visual feedback for ticket creation progress.  
It listens for streamed output from `MuskyTicketSubmissionHandler.php`, displays it in a log window, and monitors any spawned sub-errands or user messages until completion.

## Security Baseline
- This page should always bootstrap `web/check_access.php` before both:
  - HTML rendering mode
  - `?api=status` polling mode
- Polling APIs must follow the same authenticated session rules as page UI routes.

---

## Features
- ✅ Displays live log feed as tasks progress
- ✅ Detects “silent exits” and prevents premature auto-close
- ✅ Optional auto-close toggle (default enabled)
- ✅ Deduplication protection — prevents duplicate ticket submissions
- ✅ Sub-errand monitoring (via `ExtraDataField05` array)
- ✅ User message relay from `ExtraDataField02`
- ✅ Themed to match the Musky UI
