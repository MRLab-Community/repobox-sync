<?php
require_once __DIR__ . '/auth.php';
logoutUser();
header('Location: login.php?logged_out=1');
exit;