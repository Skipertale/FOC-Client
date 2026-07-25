<?php
session_start();
session_destroy();
header("Location: loginh3.php");
exit;
?>
