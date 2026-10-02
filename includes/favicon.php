<?php
// Shared DCW favicon. Include inside <head> on every page:
//   <?php require __DIR__ . '/../includes/favicon.php'; ?>
// To change the icon site-wide, edit this one file.
$dcwFaviconSvg = 'https://upload.wikimedia.org/wikipedia/commons/0/0a/Deoband_Community_Wikimedia_logo.svg';
$dcwFaviconPng = 'https://dcwwiki.org/dcwwiki/images/5/56/DCW_logo.png'; // fallback for browsers without SVG favicon support
?>
<link rel="icon" type="image/svg+xml" href="<?= htmlspecialchars($dcwFaviconSvg) ?>">
<link rel="icon" type="image/png" href="<?= htmlspecialchars($dcwFaviconPng) ?>">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($dcwFaviconPng) ?>">
