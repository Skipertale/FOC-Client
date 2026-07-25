<?php
session_start();
session_destroy();
header("Location: loginh1.php");
exit;
?>
