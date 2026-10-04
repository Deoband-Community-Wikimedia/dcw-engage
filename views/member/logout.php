<?php
require_once __DIR__ . '/../../includes/init.php';
require_once __DIR__ . '/../../includes/member_session.php';

// Ends the member session only. An organizer who is also signed in on this browser stays signed in.
MemberSession::logout();
$_SESSION['member_notice'] = 'You have been signed out.';
header('Location: /member/login');
exit;
