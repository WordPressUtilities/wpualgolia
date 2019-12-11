# wpualgolia
Handle Algolia index for Custom Post Types


## Example

```php
add_filter('wpualgolia_indexes', 'wpualgoliatest_wpualgolia_indexes', 10, 1);
function wpualgoliatest_wpualgolia_indexes($indexes) {
    $indexes['indextest'] = array(
        'post_type' => array(
            'post'
        ),
        'fields' => array(
            'post_title' => array(
                'type' => 'post'
            )
        ),
        'settings' => array(
            'searchableAttributes' => array(
                'post_title'
            )
        )
    );
    return $indexes;
}

/* Check for items to reindex at every page load */
add_action('plugins_loaded', 'wpualgoliatest_plugins_loaded', 20, 1);
function wpualgoliatest_plugins_loaded() {
    do_action('wpualgolia_index_items');
}

```
