<?php
// decode_tags.php

require_once __DIR__ . '/../../Functions/MuskyConfig.php';
require_once __DIR__ . '/../../Functions/MuskyTagDecode.php';

$tagTranslations = function_exists('musky_tag_decode_translations')
    ? musky_tag_decode_translations()
    : [];
$tagTranslationsUpper = [];
foreach ($tagTranslations as $tagKey => $translation) {
    $tagTranslationsUpper[strtoupper(trim((string)$tagKey))] = (string)$translation;
}

if (!empty($parsedData['TAGS'])) {
    echo "<h3>Device Tags:</h3><ul>";

    $tagList = explode(',', $parsedData['TAGS']);
    foreach ($tagList as $tag) {
        $tag = trim($tag);
        $tagUpper = strtoupper($tag);
        if (isset($tagTranslations[$tag])) {
            echo "<li>📌" . htmlspecialchars($tagTranslations[$tag]) . "</li>";
        } elseif (isset($tagTranslationsUpper[$tagUpper])) {
            echo "<li>📌" . htmlspecialchars($tagTranslationsUpper[$tagUpper]) . "</li>";
        } else {
            echo "<li>📌Unknown Tag: " . htmlspecialchars($tag) . "</li>";
        }
    }

    echo "</ul>";
}
?>
