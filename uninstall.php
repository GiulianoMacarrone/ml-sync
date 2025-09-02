<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;

// Eliminar metadatos personalizados de productos
global $wpdb;
$wpdb->query( "DELETE FROM $wpdb->postmeta WHERE meta_key IN ('_ml_product_url', '_ml_sync_last_error')" );
