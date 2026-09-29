<?php
/**
 * portal/crm.php — the portal's own way into the CACENTRE CRM.
 *
 * Kept as its own address because the portal sidebar links here and links
 * outlive the page that made them. Everything it used to do now lives at
 * /cacentre, which is reachable from the whole site rather than from one
 * sidebar — so this forwards rather than keeping a second copy of the
 * sign-in handoff that would drift from the first.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/lib/CacSso.php';

$to = (string) ($_GET['next'] ?? $_GET['to'] ?? '');
header('Location: ' . CacSso::door($to), true, 302);
exit;
