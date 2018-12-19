<?php
/**
 * @wordpress-plugin
 * Plugin Name:       Remote Media Staging
 * Plugin URI:        https://github.com/dmhendricks/remote-media-staging
 * Description:       Load media from a remote URL, such as production or a CDN, in your staging and development instances.
 * Version:           0.8.0
 * Requires at least: 4.7
 * Requires PHP:      5.6
 * Tested up to:      5.0.2
 * Stable tag:        0.8.0
 * Author:            Daniel M. Hendricks
 * Author URI:        https://www.danhendricks.com
 * License:           GPL-2.0
 * License URI:       https://opensource.org/licenses/GPL-2.0
 * Text Domain:       remote-media-staging
 * Domain Path:       languages
 * GitHub Plugin URI: dmhendricks/remote-media-staging
 */

 /*	Copyright 2018	  Daniel M. Hendricks (https://www.danhendricks.com/)

    This program is free software; you can redistribute it and/or
    modify it under the terms of the GNU General Public License
    as published by the Free Software Foundation; either version 2
    of the License, or (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

if( !defined( 'ABSPATH' ) ) die();

if( !class_exists( 'Remote_Media_Staging' ) ) {

    class Remote_Media_Staging {

        private static $instance;
        private static $media_url;
        protected static $prefix = 'remest';
        protected static $cache_expire = 604800; // 1 week

        public static function instance() {

            if ( !isset( self::$instance ) && !( self::$instance instanceof Remote_Media_Staging ) ) {

                self::$instance = new Remote_Media_Staging;

                // Exit if environment not defined
                if( !defined( 'REMOTE_MEDIA_URL' ) ) return;
                self::$media_url = REMOTE_MEDIA_URL;

                // Override default cache expiration
                if( defined( 'REMOTE_MEDIA_CACHE_TTL' ) && intval( REMOTE_MEDIA_CACHE_TTL ) ) self::$cache_expire = REMOTE_MEDIA_CACHE_TTL;

                // Rewrite media library URLs
                self::$media_url = rtrim( filter_var( self::$media_url, FILTER_VALIDATE_URL ), '/' );
                if( !self::$media_url ) return;

                // Rewrite remote media URLs
                add_filter( 'wp_get_attachment_url', array( self::$instance, 'rewrite_media_urls' ) );
                add_filter( 'wp_calculate_image_srcset', array( self::$instance, 'rewrite_media_url_srcsets' ) );

                // Mark local media uploads
                add_filter( 'add_attachment', array( self::$instance, 'add_local_media_meta_data' ), 10, 2 );

            }

        }


        /**
         * Rewrite individual media library URLs
         *
         * @return string URL of media asset
         * @since 0.8.0
         */
        public function rewrite_media_urls( $url ) {

            // Check if we should rewrite uploads since last sync
            $is_local_image = get_post_meta( self::get_media_id_by_url( $url ), 'remest_local_media' );
            if( $is_local_image ) return $url;

            $endpoint = trailingslashit( self::$media_url );
            $link = parse_url( $url );
            $link[ 'scheme' ] = parse_url( self::$media_url, PHP_URL_SCHEME ) . '://';
            $link[ 'host' ] = parse_url( self::$media_url, PHP_URL_HOST );
            $link = implode( '', $link );

            return $link;

        }
    
        /**
         * Rewrite media library srcset URLs
         *
         * @return string Image source sets
         * @since 0.8.0
         * @see https://permalinkmanager.pro/2017/09/17/rewrite-shorten-wordpress-uploads-files-urls/
         */
        public function rewrite_media_url_srcsets( $sources ) {

            // Check if we should rewrite uploads since last sync
            $source = current( $sources );
            $source['url'] = preg_replace( '/-\d+[Xx]\d+/', '', $source['url'] );

            $is_local_image = get_post_meta( self::get_media_id_by_url( $source['url'] ), 'remest_local_media' );
            if( $is_local_image ) return $sources;

            foreach( $sources as $img_id => $source ) {
                $sources[ $img_id ][ 'url' ] = $this->rewrite_media_urls( $source['url'] );
            }

            return $sources;

        }

        /**
         * Mark media uploads since last data sync as local
         * @since 0.8.0
         */
        public function add_local_media_meta_data( $attachment_id ) {
            update_post_meta( $attachment_id, 'remest_local_media', 1 );
        }

        /**
         * Get attachment ID from media URL
         * @param string $url The URL of the media file to retrieve
         * @return int The ID of the media library upload
         * @since 0.8.0
         */
        public static function get_media_id_by_url( $url ) {

            $url = parse_url( $url );
            $url = $url['path'];
            
            $attachment = self::get_cache_object( md5( $url ), function() use ( &$url ) {
                global $wpdb;
                return $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE guid LIKE '%%%s%%'", $url ) );
            });

            return isset( $attachment[0] ) && $attachment[0] ? $attachment[0] : null;
        
        }

        /**
         * Retrieves value from cache, if enabled/present, else returns value
         *    generated by callback().
         *
         * @param string $key Key value of cache to retrieve
         * @param function $callback Result to return/set if does not exist in cache
         * @since 0.8.0
         */
        private static function get_cache_object( $key, $callback ) {

            $object_cache_group = self::prefix( 'cache_group' );
            $object_cache_expire = self::$cache_expire;
        
            $result = null;
        
            // Set key variable
            $object_cache_key = $key . ( is_multisite() ? '_' . get_current_blog_id() : '' );
            $cache_hit = false;
        
            // Try to get the value of the cache
            $result = wp_cache_get( $object_cache_key, $object_cache_group, false, $cache_hit );
            if( $cache_hit ) {
                if( $result && is_serialized( $result ) ) $result = unserialize( $result );
            } else {
                $result = $callback();
                wp_cache_set( $object_cache_key, ( is_countable( $result ) || is_object( $result ) || is_bool( $result ) ? serialize( $result ) : $result ), $object_cache_group, $object_cache_expire );
            }
        
            return $result;
        
        }
  
        /**
         * Append a field prefix as defined in $config
         *
         * @param string|null $field_name The string/field to prefix
         * @param string $after String to add after the prefix
         * @return string Prefixed string/field value
         * @since 0.8.0
         */
        private static function prefix( $field_name = null, $after = '_' ) {
            return $field_name !== null ? self::$prefix . $after . $field_name : self::$prefix;
        }

    }

    Remote_Media_Staging::instance();

}

