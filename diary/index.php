<?php
/**
 * diary/index.php — The Afrovanguard Diary (listing). Being replaced (design
 * "Afrovanguard Diary"); renders an empty page inside the chrome meanwhile.
 */
declare(strict_types=1);
require_once dirname(__DIR__) . '/lib/bootstrap.php';
require_once AV_ROOT . '/lib/partials.php';
render_head(['title' => 'The Afrovanguard Diary', 'desc' => 'Field notes from Afrovanguard.', 'canonical' => diary_url()]);
render_nav('diary');
?>
  <main id="main" tabindex="-1"></main>
<?php render_footer();
