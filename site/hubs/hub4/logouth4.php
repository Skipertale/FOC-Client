<?php
session_start();
session_destroy();
header("Location: loginh4.php");
exit;
?>
