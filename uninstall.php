<?php
/** Remove the only data the plugin stores: published report snapshots. */
defined('WP_UNINSTALL_PLUGIN') || exit;
delete_option('community_pulse_snapshots');
