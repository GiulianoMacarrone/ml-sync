<?php
/**
 * Plugin Name:       ML Sync
 * Description:       Sincroniza título y precio desde Mercado Libre (API oficial) a WooCommerce.
 * Version:           3.0
 * Author:            Giuliano Sebastian Macarrone
 */

if ( ! defined( 'ABSPATH' ) ) exit;

add_action('woocommerce_product_options_general_product_data', function () {
    echo '<div class="options_group">';
    woocommerce_wp_text_input([
        'id'          => '_ml_product_url',
        'label'       => __('URL de Mercado Libre', 'ml-sync'),
        'placeholder' => 'https://articulo.mercadolibre.com.ar/MLA123456789',
        'desc_tip'    => true,
        'description' => __('Introduce la URL del producto de Mercado Libre. Se sincroniza el título y el precio.', 'ml-sync'),
    ]);
    wp_nonce_field('ml_sync_save', 'ml_sync_nonce');
    echo '</div>';
});

add_action('woocommerce_process_product_meta', function ($post_id) {
    if ( ! isset($_POST['ml_sync_nonce']) || ! wp_verify_nonce($_POST['ml_sync_nonce'], 'ml_sync_save') ) return;
    if ( ! current_user_can('edit_product', $post_id) ) return;

    $url = isset($_POST['_ml_product_url']) ? esc_url_raw($_POST['_ml_product_url']) : '';
    if ($url) {
        update_post_meta($post_id, '_ml_product_url', $url);
        ml_sync_update_product_from_api($post_id, $url);
    } else {
        delete_post_meta($post_id, '_ml_product_url');
    }
}, 20);

/** Obtiene Item ID desde la URL de ML */
function ml_sync_extract_item_id($url) {
    if (preg_match('/(MLA-?\d+)/', $url, $matches)) {
        return str_replace('-', '', $matches[1]);
    }
    return false;
}

/**
 * Sincroniza con la API oficial y actualiza directamente la base de datos.
 */
function ml_sync_update_product_from_api($post_id, $url) {
    $item_id = ml_sync_extract_item_id($url);
    if (!$item_id) {
        update_post_meta($post_id, '_ml_sync_last_error', 'No se pudo extraer el ID de la URL.');
        return;
    }

    // --- Obtener el token de acceso de Mercado Libre desde el plugin de Wanderlust ---
    $wmeli_data = get_option('wmeli_data', true);
    $access_token = isset($wmeli_data['wmeli_access_token']) ? $wmeli_data['wmeli_access_token'] : '';

    if (empty($access_token)) {
        update_post_meta($post_id, '_ml_sync_last_error', 'No se encontró un token de acceso para la API de Mercado Libre. Por favor, configura el plugin de Wanderlust.');
        return;
    }

    // Usar la API de Mercado Libre con el token de acceso.
    $endpoint = "https://api.mercadolibre.com/items/$item_id?access_token=$access_token";
    $response = wp_remote_get($endpoint, ['timeout' => 15]);

    if (is_wp_error($response)) {
        update_post_meta($post_id, '_ml_sync_last_error', 'Error HTTP: ' . $response->get_error_message());
        return;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if ($http_code !== 200) {
        $error_message = isset($data['message']) ? $data['message'] : 'Error desconocido de la API.';
        update_post_meta($post_id, '_ml_sync_last_error', "Error de la API. Código: $http_code. Mensaje: $error_message");
        error_log("ML Sync: Error en la API para el ítem $item_id. Código HTTP: $http_code. Respuesta: " . $body);
        return;
    }

    if (empty($data) || !isset($data['title']) || !isset($data['price'])) {
        update_post_meta($post_id, '_ml_sync_last_error', 'La API no devolvió datos válidos.');
        error_log("ML Sync: La API no devolvió datos válidos para el ítem $item_id. Respuesta: " . $body);
        return;
    }

    $title = sanitize_text_field($data['title']);
    $price = floatval($data['price']);

    // Usar el método de "bypass" para actualizar directamente en la base de datos
    wp_update_post([
        'ID'         => $post_id,
        'post_title' => $title,
    ]);

    update_post_meta($post_id, '_regular_price', $price);
    update_post_meta($post_id, '_price', $price);
    
    wc_delete_product_transients($post_id);

    delete_post_meta($post_id, '_ml_sync_last_error');
    error_log("ML Sync: Producto $post_id actualizado directamente. Título: $title - Precio: $price");
}



/** Muestra errores en el admin */
add_action('admin_notices', function () {
    global $pagenow;
    if ($pagenow === 'post.php' && isset($_GET['post'])) {
        $post_id = (int) $_GET['post'];
        $err = get_post_meta($post_id, '_ml_sync_last_error', true);
        if ($err) {
            echo '<div class="notice notice-error"><p><strong>ML Sync:</strong> ' . esc_html($err) . '</p></div>';
        }
    }
});
