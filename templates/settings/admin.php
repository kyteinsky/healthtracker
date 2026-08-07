<?php
/**
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
?>
<div class="section" id="healthtracker-admin">
	<h2><?php p($l->t('Health Tracker')); ?></h2>
	<p class="settings-hint">
		<?php p($l->t('Health Tracker processes uploaded lab reports using Nextcloud\'s Task Processing providers (OCR + a language model).')); ?>
	</p>
	<p class="settings-hint">
		<?php p($l->t('Enable and configure those providers under Administration settings → AI settings.')); ?>
	</p>
	<p id="healthtracker-admin-providers" data-admin-providers-initial-state>
		<?php p($l->t('Current provider availability is loaded via initial state.')); ?>
	</p>
	<div id="healthtracker-admin-settings"></div>
</div>
