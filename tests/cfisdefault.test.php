<?php
/**
 * tests/cfisdefault.test.php — finance requests are on by default.
 *
 * CFIS's address and Afrovanguard's property code are known, so they are
 * defaults; the private key (Studio → Setup, or CFIS_SECRET) is the one
 * thing that turns finance requests and the CFIS checkout on. An explicitly
 * empty setting is still an off switch.
 *
 * Run via tests/run.php (provides ck()).
 */
declare(strict_types=1);

$cfKeep = ['CFIS_URL' => getenv('CFIS_URL'), 'CFIS_SOURCE' => getenv('CFIS_SOURCE'), 'CFIS_SECRET' => getenv('CFIS_SECRET')];
$cfSet = static function (array $env): void { foreach ($env as $k => $v) putenv($v === false ? $k : "$k=$v"); };
if (!defined('CFIS_URL') && !defined('CFIS_SOURCE') && !defined('CFIS_SECRET')) {
    $cfSet(['CFIS_URL' => false, 'CFIS_SOURCE' => false, 'CFIS_SECRET' => false]);
    $noStudio = AvSettings::get('CFIS_SECRET') === null && AvSettings::get('CFIS_URL') === null;
    if ($noStudio) {
        ck('cfis: the finance address and the property code are defaults',
           CfisCheckout::base() === CfisCheckout::DEFAULT_URL && CfisCheckout::source() === 'avg');
        ck('cfis: without the key, finance requests stay off', !CfisRequests::configured());
        $cfSet(['CFIS_SECRET' => str_repeat('k', 40)]);
        ck('cfis: with only the key, finance requests are on', CfisRequests::configured());
        $cfSet(['CFIS_URL' => '']);
        ck('cfis: an address set empty is still an off switch', !CfisRequests::configured());
    }
    $cfSet($cfKeep);
}
