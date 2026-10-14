<?php
/**
 * CareCheck sidebar — Nextcloud #app-navigation (Check-family chrome, §2.5).
 * Hash-routed SPA: active state is applied client-side via data-cck-nav;
 * this partial owns brand, grouping, icons, and the role badge.
 *
 * @var \OCP\IL10N $l
 * @var string $cckRoleLabel resolved role label (Member / Zentrale / App admin)
 */

use OCA\CareCheck\Service\IconCatalog;

$navItem = static function (string $view, string $icon, string $label): string {
	return sprintf(
		'<li class="cck-nav__item"><a href="#/%s" class="cck-nav-link cck-nav__link" data-cck-nav="%s">'
		. '<span class="cck-nav__icon" aria-hidden="true">%s</span>'
		. '<span class="cck-nav__label">%s</span></a></li>',
		$view,
		htmlspecialchars($view, ENT_QUOTES, 'UTF-8'),
		IconCatalog::render($icon, 'cck-nav__icon-svg'),
		htmlspecialchars($label, ENT_QUOTES, 'UTF-8')
	);
};
?>
<nav id="app-navigation" class="cck-nav" role="navigation" aria-label="<?php p($l->t('CareCheck')); ?>">
	<div class="app-brand cck-brand">
		<span class="app-icon cck-brand__icon" aria-hidden="true">
			<?php print_unescaped(IconCatalog::render('heart-pulse', 'cck-brand__icon-svg')); ?>
		</span>
		<div class="app-info cck-brand__text">
			<h3 class="cck-brand__title"><?php p($l->t('CareCheck')); ?></h3>
			<p class="cck-brand__subtitle"><?php p($l->t('Care documentation')); ?></p>
		</div>
	</div>
	<ul class="nav-menu cck-nav__list">
		<li class="cck-nav__group" aria-hidden="true"><?php p($l->t('Work')); ?></li>
		<?php print_unescaped($navItem('board', 'layout-grid', $l->t('Coverage board'))); ?>
		<?php print_unescaped($navItem('tours', 'route', $l->t('Tours'))); ?>
		<?php print_unescaped($navItem('clients', 'user-round', $l->t('Clients'))); ?>
		<li class="cck-nav__item">
			<a href="#/inbox" class="cck-nav-link cck-nav__link" data-cck-nav="inbox">
				<span class="cck-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('inbox', 'cck-nav__icon-svg')); ?></span>
				<span class="cck-nav__label"><?php p($l->t('Inbox')); ?></span>
				<span class="cck-badge" data-cck-inbox-badge hidden></span>
			</a>
		</li>
		<li class="cck-nav__group" aria-hidden="true"><?php p($l->t('Organisation')); ?></li>
		<?php print_unescaped($navItem('members', 'users', $l->t('Members'))); ?>
		<?php print_unescaped($navItem('catalog', 'clipboard-list', $l->t('Catalog'))); ?>
		<li class="cck-nav__group" aria-hidden="true"><?php p($l->t('Administration')); ?></li>
		<li class="cck-nav__item">
			<a href="#/settings" class="cck-nav-link cck-nav__link" data-cck-nav="settings">
				<span class="cck-nav__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('settings', 'cck-nav__icon-svg')); ?></span>
				<span class="cck-nav__label"><?php p($l->t('Settings')); ?></span>
			</a>
			<ul class="cck-nav__sublist">
				<li class="cck-nav__subitem"><a class="cck-nav__sublink" href="#/settings/general" data-cck-subnav="general"><?php p($l->t('General')); ?></a></li>
				<li class="cck-nav__subitem"><a class="cck-nav__sublink" href="#/settings/companion" data-cck-subnav="companion"><?php p($l->t('Companion app')); ?></a></li>
				<li class="cck-nav__subitem"><a class="cck-nav__sublink" href="#/settings/access" data-cck-subnav="access"><?php p($l->t('Access')); ?></a></li>
				<li class="cck-nav__subitem"><a class="cck-nav__sublink" href="#/settings/support" data-cck-subnav="support"><?php p($l->t('Support & us')); ?></a></li>
			</ul>
		</li>
		<?php print_unescaped($navItem('getapp', 'smartphone', $l->t('Get the app'))); ?>
	</ul>
	<div class="cck-nav__footer">
		<span class="cck-nav__role"><?php p((string)($cckRoleLabel ?? $l->t('Member'))); ?></span>
	</div>
	<?php include __DIR__ . '/../parts/feedback-nav-footer.php'; ?>
</nav>
