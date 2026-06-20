<?php
defined('WP_UNINSTALL_PLUGIN') || exit;

require_once plugin_dir_path(__FILE__) . 'includes/class-install.php';
OdooConnect_Install::uninstall();
