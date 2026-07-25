<?php
session_start();
session_destroy();
header("Location: loginh5.php");
exit;
?>
