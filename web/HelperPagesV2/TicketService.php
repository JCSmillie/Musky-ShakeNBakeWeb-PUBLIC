<?php
// ============================================================================
// HelpFilesV2/TicketService.php
// Extracted ticket logic from gold master.
// ============================================================================

// -----------------------------------------------------------------------------
// SECURITY BASELINE FOR LIBRARY-STYLE FILES:
//   TicketService is a shared function file, not a page endpoint. If someone
//   browses to this file directly, run centralized auth bootstrap and reject
//   execution. Includes from real pages remain unaffected.
// -----------------------------------------------------------------------------
$pandaTicketServiceDirectAccess = isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string)$_SERVER['SCRIPT_FILENAME']) === __FILE__;

if ($pandaTicketServiceDirectAccess) {
    require_once __DIR__ . '/../check_access.php';
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "TicketService is a helper library and is not a standalone endpoint.";
    exit;
}

function v2_lookup_ticket(string $ticketId): array {
    if ($ticketId === '') return [];

    return [
        'ticket_id'   => $ticketId,
        'summary'     => "[STUB] Ticket $ticketId",
        'description' => "Placeholder ticket data. Real IIQ integration pending.",
    ];
}
