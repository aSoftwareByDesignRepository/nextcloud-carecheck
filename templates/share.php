<?php
declare(strict_types=1);
/** NH-05 public share view — minimal projection, guest layout. */
?>
<div class="cck-share-page">
	<header class="cck-share-head">
		<h1><?php p($l->t('CareCheck — Besuchsinformation')); ?></h1>
	</header>
	<?php if (empty($_['ok'])): ?>
		<p class="cck-share-missing"><?php p($l->t('Dieser Link ist nicht mehr gültig.')); ?></p>
	<?php else: ?>
		<section class="cck-share-card" aria-label="<?php p($l->t('Besuchsstatus')); ?>">
			<p class="cck-share-name"><strong><?php p($_['client']); ?></strong><?php if (!empty($_['label'])): ?> — <?php p($_['label']); ?><?php endif; ?></p>
			<?php if (!empty($_['lastVisit'])): ?>
				<p><?php p($l->t('Letzter Besuch:')); ?> <?php p($_['lastVisit']); ?></p>
			<?php endif; ?>
			<?php if (!empty($_['nextVisit'])): ?>
				<p><?php p($l->t('Nächster geplanter Besuch:')); ?> <?php p($_['nextVisit']); ?></p>
			<?php else: ?>
				<p><?php p($l->t('Aktuell ist kein weiterer Besuch geplant.')); ?></p>
			<?php endif; ?>
		</section>
	<?php endif; ?>
</div>
