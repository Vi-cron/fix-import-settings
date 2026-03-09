<?php
/**
 * Plugin Name: Fix Import Settings
 * Plugin URI:  
 * Description: Набор фиксов для импорта: разрешает внешние хосты, увеличивает память, включает отладку импорта
 * Version:     1.0
 * Author:      Victor Romanov
 * Author URI:  
 * License:     GPL v2 or later
 * Text Domain: fix-import-settings
 */

// Защита от прямого доступа к файлу
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FixImportSettings {
    
    public function __construct() {
        // Разрешаем загрузку с внешних доменов
        add_filter( 'http_request_host_is_external', '__return_true' );
        
        // Увеличиваем лимиты
        add_filter( 'wp_import_upload_size_limit', array( $this, 'increase_upload_limit' ) );
        
        // Настройки для импорта медиафайлов
        add_filter( 'wp_import_post_meta', array( $this, 'fix_media_import' ), 10, 3 );
        
        // Включаем отладку импорта (будет работать только при активированном плагине)
        if ( ! defined( 'IMPORT_DEBUG' ) ) {
            define( 'IMPORT_DEBUG', true );
        }
        
        // Увеличиваем лимиты памяти (если ещё не установлены)
        if ( ! defined( 'WP_MEMORY_LIMIT' ) ) {
            define( 'WP_MEMORY_LIMIT', '256M' );
        }
        if ( ! defined( 'WP_MAX_MEMORY_LIMIT' ) ) {
            define( 'WP_MAX_MEMORY_LIMIT', '512M' );
        }
    }
    
    /**
     * Увеличиваем лимит загрузки файлов при импорте
     */
    public function increase_upload_limit( $size ) {
        return 64 * MB_IN_BYTES; // 64 MB
    }
    
    /**
     * Фикс для импорта медиафайлов
     */
    public function fix_media_import( $post_meta, $post_id, $post ) {
        // Если это attachment (медиафайл) и у него есть внешний URL
        if ( $post['post_type'] === 'attachment' && isset( $post_meta['_wp_attached_file'] ) ) {
            // Дополнительная обработка при необходимости
            // Например, можно логировать проблемные файлы
            if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
                error_log( 'Importing attachment: ' . print_r( $post_meta['_wp_attached_file'], true ) );
            }
        }
        return $post_meta;
    }
    
    /**
     * Добавляем информацию о плагине в админку
     */
    public static function add_plugin_admin_notice() {
        if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
            echo '<div class="notice notice-info is-dismissible">
                <p>🔧 <strong>Fix Import Settings активен:</strong> Режим отладки импорта включён, лимиты увеличены, внешние хосты разрешены.</p>
            </div>';
        }
    }
}

// Инициализируем плагин
$fix_import_settings = new FixImportSettings();

// Добавляем уведомление в админку (только на страницах импорта)
add_action( 'admin_notices', function() {
    $screen = get_current_screen();
    if ( $screen && $screen->id === 'tools_page_import' ) {
        FixImportSettings::add_plugin_admin_notice();
    }
});

// Очистка при деактивации (опционально)
register_deactivation_hook( __FILE__, 'fix_import_settings_deactivate' );
function fix_import_settings_deactivate() {
    // Здесь можно убрать временные настройки, если нужно
    // Но define() нельзя убрать, они действуют только на время выполнения
}