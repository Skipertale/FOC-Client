<?php
// logout.php - TERMINATE SESSION
session_start();
session_unset();
session_destroy();
header("Location: index.php");
exit;
?>