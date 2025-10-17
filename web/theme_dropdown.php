<?php
/**
 * theme_dropdown.php
 * ------------------
 * Shared dropdown for selecting themes.
 * Uses the $theme variable already set by the parent file
 * (loaded from SQLite in users_2fa).
 *
 * IMPORTANT: Keep the list in sync with classes in theme.css
 */
?>
<select id="themeSelect" onchange="changeTheme(this.value)">
  <option value="light-mode" <?= ($theme == 'light-mode') ? 'selected' : '' ?>>Light</option>
  <option value="dark-mode" <?= ($theme == 'dark-mode') ? 'selected' : '' ?>>Dark</option>
  <option value="musky-mode" <?= ($theme == 'musky-mode') ? 'selected' : '' ?>>Musky</option>
  <option value="gator-time-mode" <?= ($theme == 'gator-time-mode') ? 'selected' : '' ?>>GatorTime</option>
</select>
