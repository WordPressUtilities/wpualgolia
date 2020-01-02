<?php

/*
Plugin Name: WPU Algolia
Plugin URI: https://github.com/WordPressUtilities/wpualgolia
Version: 0.2.1
Description: Handle Algolia index for Custom Post Types
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
*/

class WPUAlgolia {

    private $indexes = array();
    private $db_deleted = false;
    private $db_deleted_name = false;
    private $db_indexed = false;
    private $db_indexed_name = false;
    private $index_batch_limit = 100;
    private $config = array(
        'plugin_id' => 'wpualgolia'
    );

    public function __construct() {
        add_filter('plugins_loaded', array(&$this, 'plugins_loaded'));
        add_action('wpualgolia_index_items', array(&$this, 'index_items'));
        add_action('wpualgolia_purge_from_indexes', array(&$this, 'purge_from_indexes'));
        add_action('save_post', array(&$this, 'save_post'), 90, 3);
        add_action('delete_post', array(&$this, 'delete_post'), 90, 1);
    }

    /* ----------------------------------------------------------
      Plugin features
    ---------------------------------------------------------- */

    public function plugins_loaded() {
        $this->make_settings();
        $this->indexes = $this->get_indexes_structure();
        $this->set_databases();
        $this->index_batch_limit = apply_filters('wpualgolia__index_batch_limit', $this->index_batch_limit);
    }

    public function make_settings() {
        $this->settings_details = array(
            # Admin page
            'create_page' => true,
            'plugin_basename' => plugin_basename(__FILE__),
            # Default
            'plugin_id' => 'wpualgolia',
            'option_id' => 'wpualgolia_options',
            'sections' => array(
                'import' => array(
                    'name' => __('Import Settings', 'wpualgolia')
                )
            )
        );
        $this->settings = array(
            'db_prefix' => array(
                'label' => __('db_prefix', 'wpualgolia')
            ),
            'app_id' => array(
                'label' => __('app_id', 'wpualgolia')
            ),
            'api_key_front' => array(
                'label' => __('api_key_front', 'wpualgolia')
            ),
            'api_key_admin' => array(
                'label' => __('api_key_admin', 'wpualgolia')
            )
        );
        if (is_admin()) {
            include dirname(__FILE__) . '/inc/WPUBaseSettings/WPUBaseSettings.php';
            new \wpualgolia\WPUBaseSettings($this->settings_details, $this->settings);
        }
    }

    public function set_databases() {

        include dirname(__FILE__) . '/inc/WPUBaseAdminDatas/WPUBaseAdminDatas.php';

        /* Handle database for deleted items */
        $this->db_deleted_name = $this->config['plugin_id'] . '_index_deleted';
        $this->db_deleted = new \wpualgolia\WPUBaseAdminDatas();
        $this->db_deleted->init(array(
            'plugin_id' => $this->config['plugin_id'],
            'table_name' => $this->db_deleted_name,
            'handle_database' => false,
            'table_fields' => array(
                'index_key' => array(
                    'public_name' => 'Index',
                    'sql' => 'varchar(100) DEFAULT NULL'
                ),
                'object_id' => array(
                    'public_name' => 'Object ID',
                    'type' => 'number'
                )
            )
        ));

        /* Handle database for indexed items */
        $this->db_indexed_name = $this->config['plugin_id'] . '_index_indexed';
        $this->db_indexed = new \wpualgolia\WPUBaseAdminDatas();
        $this->db_indexed->init(array(
            'plugin_id' => $this->config['plugin_id'],
            'table_name' => $this->db_indexed_name,
            'handle_database' => false,
            'table_fields' => array(
                'index_key' => array(
                    'public_name' => 'Index',
                    'sql' => 'varchar(100) DEFAULT NULL'
                ),
                'object_id' => array(
                    'public_name' => 'Object ID',
                    'type' => 'number'
                ),
                'index_update' => array(
                    'public_name' => 'Updated element',
                    'type' => 'number'
                )
            )
        ));

    }

    /* ----------------------------------------------------------
      Get indexes structure
    ---------------------------------------------------------- */

    public function get_indexes_structure() {
        $indexes = array();
        $indexes_tmp = apply_filters('wpualgolia_indexes', array());
        foreach ($indexes_tmp as $index_key => $index) {

            /* Add to list */
            $indexes[$index_key] = $this->get_index_structure($index);
        }

        return $indexes;
    }

    public function get_index_structure($index) {
        /* Build query */

        if (!isset($index['post_type'])) {
            $index['post_type'] = array('post');
        }
        if (!is_array($index['post_type'])) {
            $index['post_type'] = array($index['post_type']);
        }

        /* Build fields */
        if (!isset($index['fields'])) {
            $index['fields'] = array();
        }

        if (!isset($index['settings']) || !is_array($index['settings'])) {
            $index['settings'] = array();
        }

        foreach ($index['fields'] as $field_id => $field) {
            if (!isset($field['type'])) {
                $fields[$field_id]['type'] = 'post';
            }
        }

        return $index;
    }

    /* ----------------------------------------------------------
      Save post : clear index
    ---------------------------------------------------------- */

    public function save_post($post_id, $post, $update) {

        $post_type = get_post_type($post);

        foreach ($this->indexes as $index_key => $index) {
            /* Only on correct post type */
            if (!in_array($post_type, $index['post_type'])) {
                continue;
            }
            $this->insert_or_update_item($index_key, $post_id, 0);
        }
    }

    /* ----------------------------------------------------------
      Delete post : remove from index & add to delete list
    ---------------------------------------------------------- */

    public function delete_post($post_id) {
        global $wpdb;
        $post_type = get_post_type($post_id);

        foreach ($this->indexes as $index_key => $index) {
            /* Only on correct post type */
            if (!in_array($post_type, $index['post_type'])) {
                continue;
            }

            /* Add to deleted list */
            $wpdb->insert(
                $wpdb->prefix . $this->db_deleted_name,
                array(
                    'index_key' => $index_key,
                    'object_id' => $post_id
                )
            );

            /* Remove from main index */
            $wpdb->delete(
                $wpdb->prefix . $this->db_indexed_name,
                array(
                    'index_key' => $index_key,
                    'object_id' => $post_id
                )
            );

        }
    }

    /* ----------------------------------------------------------
      Index items
    ---------------------------------------------------------- */

    public function index_items() {
        foreach ($this->indexes as $index_key => $index) {
            $this->index_item($index_key, $index);
        }
    }

    public function index_item($index_key, $index) {
        global $wpdb;

        /* Select all items from database */
        $post_type = array_map(function ($v) {
            return "'" . esc_sql($v) . "'";
        }, $index['post_type']);
        $q = "SELECT ID,post_title,post_status FROM $wpdb->posts WHERE post_type IN(" . implode(',', $post_type) . ") AND post_status IN ('publish')";
        $q = apply_filters('wpualgolia__post_query', $q, $index_key);

        $posts = $wpdb->get_results($q, ARRAY_A);

        /* Select all items from index */
        $q = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$this->db_indexed_name} WHERE index_key=%s", $index_key);
        $index_items_raw = $wpdb->get_results($q, ARRAY_A);
        $index_items = array();
        foreach ($index_items_raw as $index_item) {
            $index_items[$index_item['object_id']] = $index_item;
        }
        unset($index_items_raw);

        /* Add or update new posts */
        $item_to_index = array();
        foreach ($posts as $post_tmp) {

            /* Not in index : add to algolia */
            if (!array_key_exists($post_tmp['ID'], $index_items)) {
                $post_tmp['create'] = 1;
                $post_tmp['update'] = 1;
                $item_to_index[] = $post_tmp;
                continue;
            }
            /* In index but needs update : update in algolia */
            if ($index_items[$post_tmp['ID']]['index_update'] != '1') {
                $post_tmp['update'] = 1;
                $item_to_index[] = $post_tmp;
            }
        }
        unset($posts);

        $algolia_items = array();
        $item_indexed = array();
        $iii = 0;
        foreach ($item_to_index as $item) {
            $object = $this->prepare_object($item, $index_key, $index);
            if ($iii >= $this->index_batch_limit) {
                break;
            }
            if ($object !== false) {
                $algolia_items[] = $object;
                $item_indexed[] = $item;
                $iii++;
            }
        }

        /* Send to algolia */
        if (!empty($algolia_items)) {
            $this->algolia_index_data($index_key, $algolia_items, $index);
        }

        /* Store index info */
        foreach ($item_indexed as $item) {
            if (isset($item['create'])) {
                $this->insert_item($index_key, $item['ID'], 1);
            }
            if (isset($item['update'])) {
                $this->update_item($index_key, $item['ID'], 1);
            }
        }

    }

    /* ----------------------------------------------------------
      Purge from index
    ---------------------------------------------------------- */

    public function purge_from_indexes() {
        foreach ($this->indexes as $index_key => $index) {
            $this->purge_from_index($index_key, $index);
        }
    }

    public function purge_from_index($index_key, $index) {
        global $wpdb;

        /* Get items */
        $q = $wpdb->prepare("SELECT object_id FROM {$wpdb->prefix}{$this->db_deleted_name} WHERE index_key=%s", $index_key);
        $items_object_id = $wpdb->get_col($q);
        $items_to_delete = array();
        foreach ($items_object_id as $obj_id) {
            if ($obj_id) {
                $items_to_delete[] = $obj_id;
            }
        }

        /* Delete from algolia */
        $options = get_option($this->settings_details['option_id']);
        $client = $this->get_algolia_client($options);
        $index_algo = $client->initIndex($options['db_prefix'] . $index_key);
        $index_algo->deleteObjects($items_to_delete);

        /* Delete from index */
        $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}{$this->db_deleted_name} WHERE index_key=%s", $index_key));

    }

    /* ----------------------------------------------------------
      Helpers
    ---------------------------------------------------------- */

    public function insert_or_update_item($index_key, $item_id, $index_update = 0) {
        $item = $this->get_item_obj($index_key, $item_id);
        if ($item) {
            $this->update_item($index_key, $item_id, $index_update);
        } else {
            $this->insert_item($index_key, $item_id, $index_update);
        }
    }

    public function get_item_obj($index_key, $item_id) {
        global $wpdb;
        $q = $wpdb->prepare("SELECT * FROM {$wpdb->prefix}{$this->db_indexed_name} WHERE index_key=%s AND object_id=%s", $index_key, $item_id);
        return $wpdb->get_var($q);
    }

    public function insert_item($index_key, $item_id, $index_update = 0) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . $this->db_indexed_name,
            array(
                'index_key' => $index_key,
                'object_id' => $item_id,
                'index_update' => $index_update
            )
        );
    }

    public function update_item($index_key, $item_id, $index_update = 0) {
        global $wpdb;
        $az = $wpdb->update(
            $wpdb->prefix . $this->db_indexed_name,
            array(
                'index_update' => $index_update
            ),
            array(
                'index_key' => $index_key,
                'object_id' => $item_id
            )
        );
    }

    /* ----------------------------------------------------------
      Algolia helpers
    ---------------------------------------------------------- */

    public function algolia_index_data($index_key, $_datas, $index) {
        $options = get_option($this->settings_details['option_id']);
        $client = $this->get_algolia_client($options);
        $index_algo = $client->initIndex($options['db_prefix'] . $index_key);
        $index_algo->saveObjects($_datas, array());
        if ($index['settings']) {
            $index_algo->setSettings($index['settings']);
        }
    }

    public function get_algolia_client($options) {

        /* Send index */
        require_once __DIR__ . '/vendor/autoload.php';
        $client = Algolia\AlgoliaSearch\SearchClient::create(
            $options['app_id'],
            $options['api_key_admin']
        );

        return $client;
    }

    /* ----------------------------------------------------------
      Object : prepare
    ---------------------------------------------------------- */

    public function prepare_object($item, $index_key, $index) {
        $object = array(
            'objectID' => $item['ID']
        );
        foreach ($index['fields'] as $field_id => $field) {
            if ($field['type'] == 'post' && isset($item[$field_id])) {
                $object[$field_id] = $item[$field_id];
            }
            if ($field['type'] == 'meta') {
                $object[$field_id] = get_post_meta($item['ID'], $field_id, 1);
            }
            if ($field['type'] == 'callback' && isset($field['callback'])) {
                $object[$field_id] = call_user_func($field['callback'], $item['ID'], $field_id);
            }
        }

        $object = apply_filters('wpualgolia__prepare_object', $object, $item, $index_key, $index);

        return $object;
    }

}

$WPUAlgolia = new WPUAlgolia();
