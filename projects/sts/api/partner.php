<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/ai.php';
require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/ratelimit.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Method not allowed', 405);

check_honeypot();
csrf_require();
rate_limit('partner.draft', 10, 300);

$orgName = require_field('org_name', input_post('org_name'));
$orgType = input_post('org_type', 'school');
$interest = require_field('partnership_interest', input_post('partnership_interest'));

$allowed = ['school','ngo','corporate','government','faith_based'];
if (!in_array($orgType, $allowed, true)) json_error('Invalid org_type', 422, 'org_type');

$prompt = "Draft a professional 1-paragraph partnership proposal preview.\n"
    . "Partner: {$orgType} named '" . substr($orgName, 0, 160) . "'.\n"
    . "Their interest: " . substr($interest, 0, 600) . "\n"
    . "Reference specific STS programs where relevant: Next Gen Genius Club, Alimosho Summer School, LCASP, STREET Storm.\n"
    . "80-120 words. No emotional language. Output only the paragraph.";

$proposal = ai_generate($prompt, ['timeout' => 10]);

if (!$proposal || strlen(trim($proposal)) < 40) {
    // Build a deterministic fallback so the form always proceeds
    $proposal = "Street-To-Stardom is exploring a partnership with " . $orgName . " (" . str_replace('_', ' ', $orgType) . "). Their stated interest: " . trim($interest) . ". Potential alignment includes our Next Gen Genius Club, Alimosho Summer School, LCASP and STREET Storm programs. We would propose a discovery call to identify which of our four streams maps most clearly to their stated priorities, followed by a short scoping memo and a six-month pilot proposal with shared evaluation metrics. Cohort sizes remain capped at 24, and all outcomes would be reported per the published baseline-endline framework.";
}

json_response(['ok' => true, 'proposal' => trim($proposal)]);
