# Musky – Create Ticket System Overview

## Purpose
This subsystem handles the end-to-end creation of support tickets from Musky’s user interface through NORA’s backend processing framework.

---

## Components
| File | Purpose |
|------|----------|
| `MuskyMakeATicket_iPad.php` | UI for collecting ticket data from users |
| `MuskyTicketSubmissionHandler.php` | Inserts new NORA errands and triggers IIQ flow |
| `MuskyTicketSubmissionMonitor.php` | Provides real-time progress updates |

## Security Baseline
- All direct helper endpoints in this chain should include `web/check_access.php`.
- The handler and monitor API paths should not be callable anonymously.
- This keeps ticket creation behavior consistent with Musky session/auth policy.

---

## Data Flow Summary
1. **User Form Submission** → `MuskyMakeATicket_iPad.php`
2. **Errand Insertion** → `MuskyTicketSubmissionHandler.php`
3. **Live Feedback + Subtask Tracking** → `MuskyTicketSubmissionMonitor.php`
4. **Ticket Processor (Next Stage)** → `NoraSubHandler_IIQ.php` (future integration)
