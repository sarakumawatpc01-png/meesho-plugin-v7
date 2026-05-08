<?php

class Meesho_Master_Deactivator {

	public static function deactivate() {
		// Cleanup scheduled tasks here if necessary.
		// We do NOT delete tables to preserve data on deactivation.
		wp_clear_scheduled_hook('meesho_seo_batch_process');
		wp_clear_scheduled_hook('meesho_purge_old_snapshots');
	}

}
