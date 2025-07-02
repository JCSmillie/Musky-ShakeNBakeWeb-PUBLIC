<?php
// =====================================================================
// sidebar.php
// ---------------------------------------------------------------------
// Sidebar layout for Loaner Device Manager
// ---------------------------------------------------------------------
// This file assumes $choice and $theme are passed in scope
// =====================================================================
?>

<div class="sidebar">

    <!-- Pool Selector Section -->
    <div class="sidebar-section" id="poolSection">
        <h2><?= htmlspecialchars($currentPoolLabel ?? 'Select Pool') ?></h2>
        <form id="poolForm" method="post">
            <select name="pool" id="poolSelect" onchange="this.form.submit();">
                <option value="">--Choose Pool--</option>
                <?php foreach (LOANER_POOLS as $key => $label): ?>
                    <option value="<?= htmlspecialchars($key) ?>" <?= ($choice == $key) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($key) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="button" onclick="clearPoolSelection()" style="margin-top: 10px;">Clear Pool</button>
        </form>
    </div>

    <hr>

    <!-- Device Action Buttons Section -->
    <div class="sidebar-section" id="actionSection">
        <button id="multiWipe" onclick="multiWipeAction()" disabled class="action-button-red">Mass Wipe Selected</button>
        <button id="multiVerify" onclick="multiVerifyAction()" disabled class="action-button-orange">Verify Assignment</button>
        <button id="multiMessage" onclick="multiMessageAction()" disabled class="action-button-orange">Message User</button>
        <button id="toggleMoreDataBtn" onclick="toggleMoreData()">More Data</button>
        <button onclick="toggleDebug()">Toggle Debug</button>
    </div>

    <hr>

    <!-- Theme Selector Section -->
    <div class="sidebar-section" id="themeSection">
        <label for="themeSelect"><strong>Theme:</strong></label>
        <select id="themeSelect" onchange="changeTheme()" style="margin-top: 5px;">
            <option value="light" <?= ($theme == 'light') ? 'selected' : '' ?>>Light</option>
            <option value="dark" <?= ($theme == 'dark') ? 'selected' : '' ?>>Dark</option>
            <option value="musky" <?= ($theme == 'musky') ? 'selected' : '' ?>>Musky Mode</option>
        </select>
    </div>

</div>
