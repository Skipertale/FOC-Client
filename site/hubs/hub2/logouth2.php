<?php
session_start();
session_destroy();
header("Location: loginh2.php");
exit;
?>
