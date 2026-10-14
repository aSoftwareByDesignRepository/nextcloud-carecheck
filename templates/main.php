<?php
declare(strict_types=1);
/** @var \OCP\IL10N $l */

use OCA\CareCheck\Service\IconCatalog;
use OCA\CareCheck\Support\MobileAppLinks;
use OCA\CareCheck\Support\SupportUsLinks;

$lang = method_exists($l, 'getLanguageCode') ? (string)$l->getLanguageCode() : 'en';
$mobile = new MobileAppLinks();

if (!empty($_['isAppAdmin'])) {
	$cckRoleLabel = $l->t('App admin');
} elseif (!empty($_['isZentrale'])) {
	$cckRoleLabel = $l->t('Zentrale');
} elseif (!empty($_['isBuchhaltung'])) {
	$cckRoleLabel = $l->t('Buchhaltung');
} else {
	$cckRoleLabel = $l->t('Member');
}

$viewHead = static function (string $icon, string $title, string $lead): string {
	return '<header class="cck-view-head">'
		. '<span class="cck-view-head__icon" aria-hidden="true">' . IconCatalog::render($icon, 'cck-view-head__icon-svg') . '</span>'
		. '<div class="cck-view-head__text"><h1 class="cck-view-head__title">' . htmlspecialchars($title, ENT_QUOTES, 'UTF-8') . '</h1>'
		. '<p class="cck-view-head__lead">' . htmlspecialchars($lead, ENT_QUOTES, 'UTF-8') . '</p></div>'
		. '</header>';
};

$settingsNav = static function () use ($l): string {
	$items = [
		'general' => $l->t('General'),
		'companion' => $l->t('Companion app'),
		'access' => $l->t('Access'),
		'support' => $l->t('Support & us'),
	];
	$out = '<nav class="cck-chipbar" aria-label="' . htmlspecialchars($l->t('Settings sections'), ENT_QUOTES, 'UTF-8') . '">';
	foreach ($items as $id => $label) {
		$out .= '<a class="cck-chipbar__chip" data-cck-subnav="' . $id . '" href="#/settings/' . $id . '">' . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>';
	}
	return $out . '</nav>';
};
?>
<?php include __DIR__ . '/common/navigation.php'; ?>
<div id="app-content" class="cck-app" data-cck-app lang="<?php p($lang); ?>" data-cck-locale="<?php p($lang); ?>">
	<a class="cck-skip-link" href="#cck-main"><?php p($l->t('Skip to content')); ?></a>
	<a class="cck-skip-link cck-skip-link--nav" href="#app-navigation"><?php p($l->t('Skip to navigation')); ?></a>
	<div id="cck-live-region" class="cck-sr-only" role="status" aria-live="polite" aria-atomic="true"></div>
	<div id="cck-alert-region" class="cck-sr-only" role="alert" aria-live="assertive" aria-atomic="true"></div>
	<div id="app-content-wrapper" class="cck-shell">
		<header class="cck-topbar">
			<button type="button" class="cck-btn cck-btn--secondary cck-nav-toggle" id="cck-nav-toggle"
				aria-controls="app-navigation" aria-expanded="false"
				aria-label="<?php p($l->t('Open navigation menu')); ?>"
				data-cck-nav-toggle
				data-aria-label-open="<?php p($l->t('Open navigation menu')); ?>"
				data-aria-label-close="<?php p($l->t('Close navigation menu')); ?>">
				<span class="cck-nav-toggle__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('menu')); ?></span>
				<span class="cck-nav-toggle__label"><?php p($l->t('Menu')); ?></span>
			</button>
		</header>
		<main id="cck-main" class="cck-main" tabindex="-1">

		<div class="cck-view" data-cck-view="board" hidden>
			<?php print_unescaped($viewHead('layout-grid', $l->t('Coverage board'), $l->t('Today at a glance — every stop, every caregiver.'))); ?>
			<p class="cck-hint" data-cck-today></p>
			<div data-cck-cron-banner class="cck-banner cck-banner--error" role="alert" hidden>
				<?php p($l->t('Background jobs are not running — generated tours may be missing. Check the instance cron setup.')); ?>
			</div>
			<div data-cck-setup class="cck-setup" hidden>
				<h2><?php p($l->t('Getting started')); ?></h2>
				<ol class="cck-setup-steps">
					<li data-cck-step="catalog"><a href="#/catalog"><?php p($l->t('Add catalog tasks')); ?></a></li>
					<li data-cck-step="members"><a href="#/members"><?php p($l->t('Add staff')); ?></a></li>
					<li data-cck-step="clients"><a href="#/clients"><?php p($l->t('Add first client')); ?></a></li>
					<li data-cck-step="tours"><a href="#/tours"><?php p($l->t('Create first tour')); ?></a></li>
				</ol>
			</div>
			<div data-cck-uncovered class="cck-uncovered" hidden>
				<h2><?php p($l->t('Unassigned stops')); ?></h2>
				<ul data-cck-uncovered-list></ul>
			</div>
			<div data-cck-board class="cck-board"></div>
			<div class="cck-board-actions">
				<button type="button" data-cck-generate class="cck-btn cck-btn--primary"><?php p($l->t('Generate tours now')); ?></button>
			</div>
		</div>

		<div class="cck-view" data-cck-view="clients" hidden>
			<?php print_unescaped($viewHead('user-round', $l->t('Clients'), $l->t('People receiving care — records stay encrypted.'))); ?>
			<form data-cck-client-form class="cck-form">
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Name')); ?></span>
					<input type="text" name="name" required maxlength="190" placeholder="<?php p($l->t('e.g. Johanna Meier')); ?>"></label>
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Street')); ?></span>
					<input type="text" name="street" maxlength="190"></label>
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Required qualification')); ?></span>
					<select name="requires_qual">
						<option value=""><?php p($l->t('Any qualification')); ?></option>
						<option value="hilfskraft"><?php p($l->t('Care assistant')); ?></option>
						<option value="betreuer"><?php p($l->t('Caregiver')); ?></option>
						<option value="fachkraft"><?php p($l->t('Qualified caregiver')); ?></option>
					</select></label>
				<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Add client')); ?></button>
			</form>
			<div class="cck-table-wrap" tabindex="0" role="region" aria-label="<?php p($l->t('Client table — scroll horizontally for more columns')); ?>">
				<table class="cck-table" data-cck-clients><thead><tr>
					<th><?php p($l->t('Name')); ?></th><th><?php p($l->t('Street')); ?></th><th><?php p($l->t('Qualification')); ?></th><th></th>
				</tr></thead><tbody><tr><td colspan="4" class="cck-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody></table>
			</div>
		</div>

		<div class="cck-view" data-cck-view="client" hidden>
			<header class="cck-view-head">
				<span class="cck-view-head__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('user-round', 'cck-view-head__icon-svg')); ?></span>
				<div class="cck-view-head__text">
					<h1 class="cck-view-head__title" data-cck-client-name></h1>
				</div>
				<button type="button" data-cck-client-back class="cck-btn cck-btn--secondary cck-view-head__back"><?php p($l->t('Back to list')); ?></button>
			</header>

			<section class="cck-panel">
				<h2><?php p($l->t('Client record')); ?></h2>
				<form data-cck-client-edit class="cck-form-col">
					<input type="hidden" name="base_revision">
					<label><?php p($l->t('Name')); ?> <input name="name" required maxlength="190"></label>
					<label><?php p($l->t('Street')); ?> <input name="street" maxlength="190"></label>
					<label><?php p($l->t('Access note')); ?> <input name="accessNote" maxlength="190"></label>
					<label><?php p($l->t('Risks (comma separated)')); ?> <input name="risks" maxlength="400"></label>
					<fieldset class="cck-refpick-field"><legend><?php p($l->t('Primary caregiver')); ?></legend>
						<div class="cck-refpick" data-cck-refpick="primary_ref"></div>
					</fieldset>
					<fieldset class="cck-refpick-field"><legend><?php p($l->t('Substitute caregiver')); ?></legend>
						<div class="cck-refpick" data-cck-refpick="sub_ref"></div>
					</fieldset>
					<label><?php p($l->t('Team')); ?> <select name="team_id" data-cck-client-team><option value=""><?php p($l->t('No team')); ?></option></select></label>
					<label class="cck-check"><input type="checkbox" name="photo_consent" value="1">
						<span><?php p($l->t('Photo documentation consent given')); ?></span></label>
					<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Save')); ?></button>
				</form>
			</section>

			<section class="cck-panel">
				<h2><?php p($l->t('Care plan')); ?></h2>
				<form data-cck-plan-form class="cck-form-col">
					<input type="hidden" name="base_revision">
					<div data-cck-plan-tasks></div>
					<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Publish plan')); ?></button>
				</form>
			</section>

			<section class="cck-panel">
				<h2><?php p($l->t('Client log')); ?></h2>
				<form data-cck-journal-form class="cck-form">
					<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Log entry')); ?></span>
						<input type="text" name="text" required maxlength="4000" placeholder="<?php p($l->t('What happened?')); ?>"></label>
					<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Add entry')); ?></button>
				</form>
				<ol data-cck-journal class="cck-journal" reversed></ol>
			</section>

			<section class="cck-panel" data-cck-photo-panel>
				<h2><?php p($l->t('Photos')); ?></h2>
				<p class="cck-muted" data-cck-photo-hint hidden><?php p($l->t('Photo documentation requires the client\'s consent — enable it in the client record above.')); ?></p>
				<div class="cck-form" data-cck-photo-controls hidden>
					<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Add photo')); ?></span>
						<input type="file" name="photo" accept="image/jpeg,image/png,image/webp" data-cck-photo-file></label>
				</div>
				<div class="cck-photo-grid" data-cck-photos></div>
			</section>

			<section class="cck-panel">
				<h2><?php p($l->t('Exports')); ?></h2>
				<label><?php p($l->t('Month')); ?> <input type="month" name="export_period" data-cck-export-period></label>
				<div class="cck-form">
					<button type="button" data-cck-export-csv class="cck-btn cck-btn--secondary"><?php p($l->t('Service record (CSV)')); ?></button>
					<button type="button" data-cck-export-pdf class="cck-btn cck-btn--secondary"><?php p($l->t('Service record (PDF)')); ?></button>
					<button type="button" data-cck-export-json class="cck-btn cck-btn--secondary"><?php p($l->t('Billing data (JSON)')); ?></button>
					<button type="button" data-cck-export-xml class="cck-btn cck-btn--secondary"><?php p($l->t('Billing data (XML)')); ?></button>
				</div>
			</section>
		</div>

		<div class="cck-view" data-cck-view="tours" hidden>
			<?php print_unescaped($viewHead('route', $l->t('Tours'), $l->t('Weekly templates — stops materialize into daily tours.'))); ?>
			<form data-cck-tour-form class="cck-form-col">
				<label><?php p($l->t('Tour name')); ?> <input name="name" required maxlength="190"></label>
				<fieldset class="cck-weekdays"><legend><?php p($l->t('Weekdays')); ?></legend>
					<label><input type="checkbox" name="wd" value="1"> <?php p($l->t('Mon')); ?></label>
					<label><input type="checkbox" name="wd" value="2"> <?php p($l->t('Tue')); ?></label>
					<label><input type="checkbox" name="wd" value="3"> <?php p($l->t('Wed')); ?></label>
					<label><input type="checkbox" name="wd" value="4"> <?php p($l->t('Thu')); ?></label>
					<label><input type="checkbox" name="wd" value="5"> <?php p($l->t('Fri')); ?></label>
					<label><input type="checkbox" name="wd" value="6"> <?php p($l->t('Sat')); ?></label>
					<label><input type="checkbox" name="wd" value="7"> <?php p($l->t('Sun')); ?></label>
				</fieldset>
				<fieldset class="cck-refpick-field"><legend><?php p($l->t('Lead caregiver')); ?></legend>
					<div class="cck-refpick" data-cck-refpick="lead_ref"></div>
				</fieldset>
				<label><?php p($l->t('Status')); ?> <select name="status"><option value="draft"><?php p($l->t('Draft')); ?></option><option value="published"><?php p($l->t('Published')); ?></option></select></label>
				<h3><?php p($l->t('Stops')); ?></h3>
				<div data-cck-tour-stops></div>
				<div class="cck-form">
					<button type="button" data-cck-tour-add-stop class="cck-btn cck-btn--secondary"><?php p($l->t('Add stop')); ?></button>
					<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Save tour template')); ?></button>
				</div>
			</form>
			<div class="cck-table-wrap" tabindex="0" role="region" aria-label="<?php p($l->t('Tour template table — scroll horizontally for more columns')); ?>">
				<table class="cck-table" data-cck-templates><thead><tr>
					<th><?php p($l->t('Name')); ?></th><th><?php p($l->t('Weekdays')); ?></th><th><?php p($l->t('Status')); ?></th>
				</tr></thead><tbody><tr><td colspan="3" class="cck-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody></table>
			</div>
		</div>

		<div class="cck-view" data-cck-view="members" hidden>
			<?php print_unescaped($viewHead('users', $l->t('Members'), $l->t('Care staff — pair devices and track absences here.'))); ?>
			<form data-cck-member-form class="cck-form">
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Display name')); ?></span>
					<input type="text" name="display_name" required maxlength="190"></label>
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Qualification')); ?></span>
					<select name="qualification">
						<option value="hilfskraft"><?php p($l->t('Care assistant')); ?></option>
						<option value="betreuer"><?php p($l->t('Caregiver')); ?></option>
						<option value="fachkraft"><?php p($l->t('Qualified caregiver')); ?></option>
					</select></label>
				<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Add member')); ?></button>
			</form>
			<div class="cck-table-wrap" tabindex="0" role="region" aria-label="<?php p($l->t('Member table — scroll horizontally for more columns')); ?>">
				<table class="cck-table" data-cck-members><thead><tr>
					<th><?php p($l->t('Name')); ?></th><th><?php p($l->t('Qualification')); ?></th><th><?php p($l->t('Team')); ?></th><th></th>
				</tr></thead><tbody><tr><td colspan="3" class="cck-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody></table>
			</div>

			<div data-cck-paircard class="cck-paircard" hidden>
				<h2><?php p($l->t('Pairing card')); ?></h2>
				<p><?php p($l->t('Scan with the CareCheck app or type the code.')); ?></p>
				<img data-cck-pair-qr alt="<?php p($l->t('QR pairing code')); ?>" width="220" height="220">
				<p class="cck-paircode" data-cck-pair-text></p>
				<button type="button" data-cck-paircard-done class="cck-btn cck-btn--secondary"><?php p($l->t('Done')); ?></button>
			</div>

			<section class="cck-panel">
				<h2><?php p($l->t('Absences')); ?></h2>
				<form data-cck-absence-form class="cck-form">
					<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Staff member')); ?></span>
						<div class="cck-refpick" data-cck-refpick="staff_ref"></div></label>
					<label class="cck-field"><span class="cck-field__label"><?php p($l->t('From')); ?></span>
						<input type="date" name="from" required></label>
					<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Until')); ?></span>
						<input type="date" name="until" required></label>
					<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Kind')); ?></span>
						<select name="kind">
							<option value="sick"><?php p($l->t('Sick')); ?></option>
							<option value="vacation"><?php p($l->t('Vacation')); ?></option>
							<option value="other"><?php p($l->t('Other')); ?></option>
						</select></label>
					<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Mark absent')); ?></button>
				</form>
				<ul data-cck-absences class="cck-absences"></ul>
			</section>
		</div>

		<div class="cck-view" data-cck-view="catalog" hidden>
			<?php print_unescaped($viewHead('clipboard-list', $l->t('Catalog'), $l->t('Reusable care tasks for plans and tour stops.'))); ?>
			<form data-cck-catalog-form class="cck-form">
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Code')); ?></span>
					<input type="text" name="code" required maxlength="48" placeholder="<?php p($l->t('e.g. body-care')); ?>" pattern="[A-Za-z0-9_.\-]+"></label>
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Label')); ?></span>
					<input type="text" name="label" required maxlength="190" placeholder="<?php p($l->t('e.g. Personal hygiene')); ?>"></label>
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Icon (optional)')); ?></span>
					<input type="text" name="icon" maxlength="48"></label>
				<label class="cck-field"><span class="cck-field__label"><?php p($l->t('Required qualification')); ?></span>
					<select name="requires_qual">
						<option value=""><?php p($l->t('Any qualification')); ?></option>
						<option value="hilfskraft"><?php p($l->t('Care assistant')); ?></option>
						<option value="betreuer"><?php p($l->t('Caregiver')); ?></option>
						<option value="fachkraft"><?php p($l->t('Qualified caregiver')); ?></option>
					</select></label>
				<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Add task')); ?></button>
			</form>
			<div class="cck-table-wrap" tabindex="0" role="region" aria-label="<?php p($l->t('Task catalog table — scroll horizontally for more columns')); ?>">
				<table class="cck-table" data-cck-catalog><thead><tr>
					<th><?php p($l->t('Code')); ?></th><th><?php p($l->t('Label')); ?></th><th><?php p($l->t('Qualification')); ?></th>
				</tr></thead><tbody><tr><td colspan="3" class="cck-empty"><?php p($l->t('Loading…')); ?></td></tr></tbody></table>
			</div>
		</div>

		<div class="cck-view" data-cck-view="inbox" hidden>
			<?php print_unescaped($viewHead('inbox', $l->t('Inbox'), $l->t('Notices and rejected visit bundles that need a decision.'))); ?>
			<section class="cck-panel" data-cck-review-panel hidden>
				<h2><?php p($l->t('Review queue')); ?> <span class="cck-badge" data-cck-review-badge hidden></span></h2>
				<p class="cck-hint"><?php p($l->t('Rejected visit bundles wait here — check the client log, correct if needed, then resolve.')); ?></p>
				<div data-cck-reviews></div>
			</section>
			<section class="cck-panel">
				<h2><?php p($l->t('Notices')); ?></h2>
				<div data-cck-notices></div>
			</section>
		</div>

		<?php foreach (['general', 'companion', 'access', 'support'] as $sec): ?>
		<div class="cck-view" data-cck-view="settings-<?php p($sec); ?>" hidden>
			<?php print_unescaped($viewHead('settings', $l->t('Settings'), $l->t('Provider-wide configuration — one section per page.'))); ?>
			<?php print_unescaped($settingsNav()); ?>
			<div data-cck-settings-section="<?php p($sec); ?>"></div>
		</div>
		<?php endforeach; ?>

		<div class="cck-view" data-cck-view="getapp" hidden>
			<header class="cck-view-head">
				<span class="cck-view-head__icon" aria-hidden="true"><?php print_unescaped(IconCatalog::render('smartphone', 'cck-view-head__icon-svg')); ?></span>
				<div class="cck-view-head__text">
					<h1 class="cck-view-head__title"><?php p($l->t('Get the app')); ?></h1>
					<p class="cck-view-head__lead"><?php p($l->t('CareCheck Mobile — the companion for tours and visit documentation.')); ?></p>
				</div>
			</header>
			<section class="cck-panel cck-getapp">
				<h2><?php p($l->t('CareCheck Mobile for Android')); ?></h2>
				<ul class="cck-getapp__features">
					<li><?php p($l->t('Today’s tour at a glance — offline, encrypted on the device.')); ?></li>
					<li><?php p($l->t('Check in, document tasks, collect signatures.')); ?></li>
					<li><?php p($l->t('Break-glass cover access with reason — fully audited.')); ?></li>
				</ul>
				<?php if (MobileAppLinks::PLAY_LISTED): ?>
					<p><a class="cck-btn cck-btn--primary" href="<?php p(MobileAppLinks::PLAY_STORE_URL); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Get it on Google Play')); ?></a></p>
				<?php else: ?>
					<p class="cck-hint"><?php p($l->t('Not yet on Google Play — the app is distributed as a signed build by your provider.')); ?></p>
				<?php endif; ?>
				<h3><?php p($l->t('Pair a device')); ?></h3>
				<p><?php p($l->t('Members get a pairing code and QR card from Members → Pair. Staff with a Nextcloud account sign in inside the app.')); ?></p>
				<p><a class="cck-btn cck-btn--secondary" href="#/members"><?php p($l->t('Open Members')); ?></a></p>
				<?php if ($mobile->productPageUrl($lang) !== ''): ?>
				<p class="cck-getapp__links">
					<a href="<?php p($mobile->productPageUrl($lang)); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Product page')); ?></a>
					· <a href="<?php p($mobile->privacyPageUrl($lang)); ?>" target="_blank" rel="noopener noreferrer"><?php p($l->t('Privacy notes (mobile)')); ?></a>
				</p>
				<?php endif; ?>
			</section>
		</div>

		</main>
	</div>
</div>

<!-- Settings section bodies live outside the views: the JS moves them into the
     active section's slot so each settings URL shows exactly one page. -->
<template data-cck-settings-tpl="general">
	<form data-cck-settings-form="general" class="cck-form-col cck-panel">
		<h2><?php p($l->t('General')); ?></h2>
		<label><?php p($l->t('Provider timezone')); ?> <input name="provider_tz" maxlength="64" placeholder="Europe/Berlin" pattern="[A-Za-z_/+\-0-9]+"></label>
		<label><?php p($l->t('GPS check-in evidence')); ?> <input type="checkbox" name="gps_enabled"></label>
		<label><?php p($l->t('Incident SLA (minutes)')); ?> <input type="number" name="sla_minutes" min="5" max="240"></label>
		<label><?php p($l->t('Retention (years)')); ?> <input type="number" name="retention_years" min="1" max="99"></label>
		<label><?php p($l->t('Break-glass max hours')); ?> <input type="number" name="breakglass_hours_max" min="1" max="72"></label>
		<fieldset>
			<legend><?php p($l->t('Time-of-day presets')); ?></legend>
			<label><?php p($l->t('early morning (min)')); ?> <input name="preset_early" pattern="\d{1,4}-\d{1,4}" placeholder="360-540"></label>
			<label><?php p($l->t('morning (min)')); ?> <input name="preset_morning" pattern="\d{1,4}-\d{1,4}" placeholder="540-720"></label>
			<label><?php p($l->t('midday (min)')); ?> <input name="preset_noon" pattern="\d{1,4}-\d{1,4}" placeholder="720-840"></label>
			<label><?php p($l->t('afternoon (min)')); ?> <input name="preset_afternoon" pattern="\d{1,4}-\d{1,4}" placeholder="840-1020"></label>
			<label><?php p($l->t('evening (min)')); ?> <input name="preset_evening" pattern="\d{1,4}-\d{1,4}" placeholder="1020-1200"></label>
		</fieldset>
		<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Save')); ?></button>
	</form>
</template>
<template data-cck-settings-tpl="companion">
	<div class="cck-settings-stack">
		<section class="cck-panel" data-cck-license-panel hidden>
			<h2><?php p($l->t('Companion license')); ?></h2>
			<p class="cck-hint"><?php p($l->t('The web app is free — the license unlocks companion-app seats only.')); ?></p>
			<dl class="cck-license-state" data-cck-license-state></dl>
			<form data-cck-license-form class="cck-form-col">
				<label><?php p($l->t('License key')); ?>
					<textarea name="license_key" rows="4" maxlength="4000" placeholder="CCK2-…" aria-label="<?php p($l->t('License key')); ?>"></textarea>
				</label>
				<div class="cck-form">
					<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Apply license')); ?></button>
					<button type="button" data-cck-license-clear class="cck-btn cck-btn--secondary"><?php p($l->t('Remove license')); ?></button>
				</div>
			</form>
		</section>
		<form data-cck-settings-form="companion" class="cck-form-col cck-panel">
			<h2><?php p($l->t('Companion app')); ?></h2>
			<label><?php p($l->t('Fallback seat cap (license is authoritative)')); ?> <input type="number" name="seat_limit" min="1" max="999"></label>
			<label><?php p($l->t('Companion requests per minute / device')); ?> <input type="number" name="device_rate_per_min" min="10" max="600"></label>
			<label><?php p($l->t('Minimum companion version')); ?> <input name="min_companion_version" pattern="\d+\.\d+\.\d+"></label>
			<label><?php p($l->t('TSA URL (optional)')); ?> <input type="url" name="tsa_url"></label>
			<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Save')); ?></button>
		</form>
		<section class="cck-panel" data-cck-diag-panel hidden>
			<h2><?php p($l->t('Diagnostics')); ?></h2>
			<p class="cck-hint"><?php p($l->t('Instance state only — never client data.')); ?></p>
			<dl class="cck-license-state" data-cck-diag-state></dl>
		</section>
	</div>
</template>
<template data-cck-settings-tpl="access">
	<form data-cck-settings-form="access" class="cck-form-col cck-panel" data-cck-access>
		<h2><?php p($l->t('Access')); ?></h2>
		<label><?php p($l->t('Access mode')); ?>
			<select name="access_mode">
				<option value="open"><?php p($l->t('Open — every Nextcloud user')); ?></option>
				<option value="restricted"><?php p($l->t('Restricted — allow list below')); ?></option>
			</select>
		</label>
		<fieldset class="cck-refpick-field"><legend><?php p($l->t('Allowed users')); ?></legend>
			<div class="cck-refpick cck-refpick--multi" data-cck-refpick="allowed_users" data-cck-refpick-kind="uid"></div>
		</fieldset>
		<fieldset class="cck-refpick-field"><legend><?php p($l->t('Allowed groups')); ?></legend>
			<div class="cck-refpick cck-refpick--multi" data-cck-refpick="allowed_groups" data-cck-refpick-kind="group"></div>
		</fieldset>
		<fieldset class="cck-refpick-field"><legend><?php p($l->t('Delegated app admins')); ?></legend>
			<div class="cck-refpick cck-refpick--multi" data-cck-refpick="app_admins" data-cck-refpick-kind="uid"></div>
		</fieldset>
		<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Save')); ?></button>
	</form>

	<section class="cck-panel" data-cck-teams>
		<h2><?php p($l->t('Teams')); ?></h2>
		<p class="cck-hint"><?php p($l->t('Team leads see only the clients and tours of the teams they lead. Zentrale keeps full visibility.')); ?></p>
		<form data-cck-team-form class="cck-form">
			<label class="cck-field"><span class="cck-field__label"><?php p($l->t('New team')); ?></span>
				<input type="text" name="name" required maxlength="190" placeholder="<?php p($l->t('e.g. Team North')); ?>"></label>
			<button type="submit" class="cck-btn cck-btn--primary"><?php p($l->t('Create team')); ?></button>
		</form>
		<div data-cck-team-list class="cck-team-list"><p class="cck-empty"><?php p($l->t('Loading…')); ?></p></div>
	</section>
</template>
<template data-cck-settings-tpl="support">
	<?php
		$supportUsLinks = new SupportUsLinks('CareCheck', true, '/apps/carecheck#/settings/companion');
		$supportUsCssPrefix = 'cck';
		$supportUsShellPrefix = 'cck';
		$supportUsBtnPrimaryClass = 'cck-btn cck-btn--primary';
		$supportUsBtnSecondaryClass = 'cck-btn cck-btn--secondary';
		$supportUsPresentation = 'page';
		include __DIR__ . '/parts/support-us-section.php';
	?>
</template>

<dialog class="cck-dialog" data-cck-assign-dialog aria-labelledby="cck-assign-title">
	<h2 id="cck-assign-title"><?php p($l->t('Assign stop')); ?></h2>
	<p class="cck-dialog__hint"><?php p($l->t('Pick a caregiver — the list shows staff members and Nextcloud users.')); ?></p>
	<div class="cck-refpick" data-cck-refpick="assign_ref"></div>
	<div class="cck-dialog__actions">
		<button type="button" data-cck-assign-cancel class="cck-btn cck-btn--secondary"><?php p($l->t('Cancel')); ?></button>
		<button type="button" class="cck-btn cck-btn--primary" data-cck-assign-confirm disabled><?php p($l->t('Assign')); ?></button>
	</div>
</dialog>

<dialog class="cck-dialog" data-cck-resolve-dialog aria-labelledby="cck-resolve-title">
	<h2 id="cck-resolve-title"><?php p($l->t('Resolve rejected bundle')); ?></h2>
	<p class="cck-dialog__hint"><?php p($l->t('Rejected visit bundles wait here — check the client log, correct if needed, then resolve.')); ?></p>
	<label class="cck-dialog__label"><?php p($l->t('How was this corrected?')); ?>
		<textarea data-cck-resolve-note rows="3" maxlength="2000" required></textarea>
	</label>
	<div class="cck-dialog__actions">
		<button type="button" data-cck-resolve-cancel class="cck-btn cck-btn--secondary"><?php p($l->t('Cancel')); ?></button>
		<button type="button" data-cck-resolve-confirm class="cck-btn cck-btn--primary"><?php p($l->t('Resolve')); ?></button>
	</div>
</dialog>
