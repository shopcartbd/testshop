<?php
declare(strict_types=1);
if (!defined('ALLSTORE_BOOTSTRAPPED')) { exit('Forbidden'); }
/** Standalone error page: deliberately does not touch the database or layout. */
http_response_code(500);
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, private');
$msg = htmlspecialchars((string)($GLOBALS['error_message'] ?? 'Something went wrong. Our team has been notified.'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Something went wrong · AllStore</title>
<link rel="stylesheet" href="/assets/css/tokens.css"><link rel="stylesheet" href="/assets/css/app.css"></head>
<body><main class="section"><div class="container error-page"><div class="error-code">500</div><h1>Something went wrong</h1><p class="text-muted" style="max-width:52ch;margin:14px auto 24px"><?= $msg ?></p><p class="field-hint">No technical details are shown here. The error has been logged securely server-side.</p><div class="row" style="justify-content:center;gap:10px;margin-top:20px"><a class="btn btn-primary" href="/">Back to homepage</a><a class="btn btn-outline" href="/contact">Contact support</a></div></div></main></body></html>
