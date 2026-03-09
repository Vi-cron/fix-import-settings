<?php
/**
 * Plugin Name: Fix Import Settings
 * Plugin URI:  
 * Description: Набор фиксов для импорта: разрешает внешние хосты, увеличивает память, включает отладку импорта
 * Version:     1.1
 * Author:      Victor Romanov
 * Author URI: 
 * License: GPL v2 or later 
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
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
    * Автоматически создаёт недостающие атрибуты WooCommerce
    */
    public function create_missing_attributes( $posts ) {
        foreach ( $posts as $index => $post ) {
            // Нас интересуют только товары (post_type 'product')
            if ( $post['post_type'] !== 'product' ) {
                continue;
            }
            
            // Ищем мета-поля с атрибутами
            if ( isset( $post['postmeta'] ) && is_array( $post['postmeta'] ) ) {
                foreach ( $post['postmeta'] as $meta ) {
                    // Атрибуты хранятся в ключах, начинающихся с 'attribute_'
                    if ( strpos( $meta['key'], 'attribute_' ) === 0 ) {
                        // Извлекаем имя атрибута (убираем 'attribute_')
                        $attribute_name = substr( $meta['key'], 10 );
                        
                        // Проверяем, не pa_ ли это атрибут
                        if ( strpos( $attribute_name, 'pa_' ) === 0 ) {
                            $taxonomy_name = $attribute_name; // Например, 'pa_room'
                            $attribute_slug = substr( $attribute_name, 3 ); // Убираем 'pa_' -> 'room'
                            
                            // Проверяем, существует ли уже такая таксономия
                            if ( ! taxonomy_exists( $taxonomy_name ) ) {
                                // Создаём глобальный атрибут
                                $this->create_woo_attribute( $attribute_slug, $taxonomy_name );
                                
                                if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
                                    error_log( "Создан атрибут: {$taxonomy_name} (slug: {$attribute_slug})" );
                                }
                            }
                            
                            // Если есть значение атрибута, проверяем/создаём термин
                            if ( isset( $meta['value'] ) && ! empty( $meta['value'] ) ) {
                                $this->ensure_attribute_term( $taxonomy_name, $meta['value'] );
                            }
                        }
                    }
                }
            }
        }
        
        return $posts;
    }
    
    /**
     * Создаёт глобальный атрибут WooCommerce
     */
    private function create_woo_attribute( $slug, $taxonomy_name ) {
        // Проверяем, активен ли WooCommerce
        if ( ! function_exists( 'wc_get_attribute_taxonomies' ) ) {
            return;
        }
        
        // Проверяем, не существует ли уже такой атрибут
        $existing = wc_get_attribute_taxonomies();
        foreach ( $existing as $attr ) {
            if ( $attr->attribute_name === $slug ) {
                return; // Уже существует
            }
        }
        
        // Подготавливаем данные для создания атрибута
        $args = array(
            'name'         => ucfirst( str_replace( '_', ' ', $slug ) ), // Человеко-понятное название
            'slug'         => $slug,
            'type'         => 'select',
            'order_by'     => 'menu_order',
            'has_archives' => true,
        );
        
        // Создаём атрибут
        $result = wc_create_attribute( $args );
        
        if ( ! is_wp_error( $result ) ) {
            // Очищаем кэш таксономий
            delete_transient( 'wc_attribute_taxonomies' );
            
            if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
                error_log( "WooCommerce атрибут создан: {$slug}" );
            }
        }
    }
    
    /**
     * Проверяет существование термина атрибута и создаёт его при необходимости
     */
    private function ensure_attribute_term( $taxonomy, $term_name ) {
        // Разделяем значения, если их несколько (через запятую)
        $terms = array_map( 'trim', explode( ',', $term_name ) );
        
        foreach ( $terms as $term ) {
            if ( empty( $term ) ) continue;
            
            // Проверяем, существует ли термин
            if ( ! term_exists( $term, $taxonomy ) ) {
                // Создаём термин
                wp_insert_term(
                    $term,
                    $taxonomy,
                    array(
                        'slug' => sanitize_title( $term )
                    )
                );
                
                if ( defined( 'IMPORT_DEBUG' ) && IMPORT_DEBUG ) {
                    error_log( "Создан термин '{$term}' для таксономии {$taxonomy}" );
                }
            }
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

// Очистка при деактивации
register_deactivation_hook( __FILE__, 'fix_import_settings_deactivate' );
function fix_import_settings_deactivate() {

}