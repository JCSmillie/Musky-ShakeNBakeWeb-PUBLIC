<?php
// decode_tags.php

$tagTranslations = [
  'ADE' => 'ASM Good!',
  'BROKEN' => 'Device is in inventory as Broken, undeployed.',
  'ChildrensInstitute' => 'Student Enrolled at Childrens Institute',
  'ChromeUser' => 'Chrome allowed on this iPad',
  'CSE-Loaner' => 'CSE Loaner Pool Device',
  'CYBER' => 'Student Enrolled in Gator Cyber',
  'DePaul' => 'Student Enrolled at DePaul',
  'Ele_Allow_Camera' => 'K-4 Camera Always On',
  'EV-Loaner' => 'EV Loaner Pool Device',
  'GMS-Loaner' => 'GMS Loaner Pool Device',
  'RAM-Loaner' => 'Ramsey Ele Loaner Pool Device',
  'GSDIT-Loaner' => 'GSD IT Loaner Pool Device',
  'HomeSchool' => 'Student Home Schooled',
  'InStorage' => 'IIQ says undeployed',
  'NoYouTube4U' => 'YouTube Block on Device',
  'Out2AGi' => '@AGI for Repair',
  'PACE' => 'Student Enrolled at PACE',
  'STOLEN' => 'STOLEN DEVICE',
  'Student' => 'iPad-STUDENT ASSIGNED',
  'Sunrise' => 'Student Enrolled at Sunrise',
  'Staff' => 'iPad-STAFF ASSIGNED',
  'Teacher' => 'iPad-TEACHER ASSIGNED',
  'UP-Loaner' => 'UP Loaner Pool Device',
  'DaNewz' => 'Ele Student News Team',
  'RETIRED-2025' => 'RETIRED 2025',  
  'HDK' => 'Help Desk Student, Respect.',
];

if (!empty($parsedData['TAGS'])) {
    echo "<h3>Device Tags:</h3><ul>";

    $tagList = explode(',', $parsedData['TAGS']);
    foreach ($tagList as $tag) {
        $tag = trim($tag);
        if (isset($tagTranslations[$tag])) {
            echo "<li>📌" . htmlspecialchars($tagTranslations[$tag]) . "</li>";
        } else {
            echo "<li>📌Unknown Tag: " . htmlspecialchars($tag) . "</li>";
        }
    }

    echo "</ul>";
}
?>

