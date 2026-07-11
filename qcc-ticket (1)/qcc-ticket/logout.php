<?php
require_once 'conexao.php';
session_unset();
session_destroy();
header("Location: login.php");
exit;