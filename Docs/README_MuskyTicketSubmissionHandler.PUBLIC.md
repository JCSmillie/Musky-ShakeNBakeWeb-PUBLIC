# Musky – Ticket Submission Handler

## Overview
`MuskyTicketSubmissionHandler.php` serves as the bridge between Musky’s user-facing ticket submission UI and the NORA task processing backend.  
It inserts new errands into the `nora_errands` table, assigns proper routing fields, and streams live feedback to the monitor window.

## Security Baseline
- This endpoint is expected to run behind `web/check_access.php`.
- Requests without a valid Musky session should be redirected/blocked by shared auth bootstrap before handler logic runs.
- This keeps JSON submission endpoints aligned with the same login/session rules as full UI pages.

---

## Features
- ✅ Accepts payloads from the ticket creation UI (via POST JSON)
- ✅ Creates one or more NORA errands (multi-device capable)
- ✅ Prevents duplicate tickets via deduplication logic
- ✅ Marks new errands as `submitted`
- ✅ Sets `ExtraDataField05 = 'CREATE'` to flag IIQ ticket creation path
- ✅ Streams structured JSON status updates for live display
- ✅ Detects and handles existing active errands of the same type
