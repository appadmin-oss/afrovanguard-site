<?php
/**
 * portal/views/attendance.php — "Attendance & pass": the CACENTRE gate inside
 * the portal (portal/_attendance.php draws it). Listed under the Vanguard
 * Quest, because turning up, and on time, is something the Quest awards.
 */
declare(strict_types=1);
?>
      <section class="pview avp-view" id="view-attendance" data-view="attendance" hidden>
<?php require dirname(__DIR__) . '/_attendance.php'; ?>
      </section>
