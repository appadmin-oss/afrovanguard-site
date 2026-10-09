<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
if (!LmsAuth::user()) { header('Location: ' . av_login_url('/portal/')); exit; }
?><!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>Member portal</title></head>
<body><main id="main"></main></body>
</html>
