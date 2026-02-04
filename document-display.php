<?php
/**
 * Plugin Name: Document Display
 * Plugin URI: https://github.com/nonatech-uk/wp-document-display
 * Description: Display directory contents as a table using [docdisplay path] shortcode
 * Version: 1.17.0
 * Author: NonaTech Services Ltd
 * License: CC BY-NC 4.0
 */

if (!defined('ABSPATH')) {
    exit;
}

// Plugin version constant
define('DOCDISPLAY_VERSION', '1.17.0');

// Initialize GitHub updater
require_once plugin_dir_path(__FILE__) . 'includes/class-github-updater.php';

new DocumentDisplay_GitHub_Updater(
    __FILE__,
    'nonatech-uk/wp-document-display',
    DOCDISPLAY_VERSION
);

class DocDisplay {

    private static $instance = null;
    private $option_name = 'docdisplay_base_path';

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_shortcode('docdisplay', array($this, 'shortcode_handler'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_styles'));
        add_action('wp_enqueue_scripts', array($this, 'enqueue_scripts'));
        add_action('rest_api_init', array($this, 'register_search_handler'));
    }

    /**
     * Register the REST API search handler for document link picker integration
     */
    public function register_search_handler() {
        require_once plugin_dir_path(__FILE__) . 'includes/class-docdisplay-search-handler.php';

        // Register our search handler with WordPress
        add_filter('wp_rest_search_handlers', function($handlers) {
            $handlers[] = new DocDisplay_Search_Handler();
            return $handlers;
        });

        // Inject document results into block editor link picker searches
        add_filter('rest_request_after_callbacks', array($this, 'inject_document_search_results'), 10, 3);

        // Register a test endpoint for debugging
        register_rest_route('docdisplay/v1', '/search', array(
            'methods' => 'GET',
            'callback' => array($this, 'rest_search_documents'),
            'permission_callback' => '__return_true',
            'args' => array(
                'search' => array(
                    'required' => true,
                    'type' => 'string',
                ),
            ),
        ));
    }

    /**
     * REST endpoint for testing document search
     */
    public function rest_search_documents($request) {
        require_once plugin_dir_path(__FILE__) . 'includes/class-docdisplay-search-handler.php';
        $handler = new DocDisplay_Search_Handler();

        $search_request = new WP_REST_Request('GET', '/wp/v2/search');
        $search_request->set_param('search', $request->get_param('search'));
        $search_request->set_param('page', 1);
        $search_request->set_param('per_page', 10);

        $results = $handler->search_items($search_request);

        $items = array();
        foreach ($results['ids'] as $id) {
            $items[] = $handler->prepare_item($id, array());
        }

        return new WP_REST_Response(array(
            'total' => $results['total'],
            'items' => $items,
            'base_path' => get_option('docdisplay_base_path', ''),
        ), 200);
    }

    /**
     * Inject document search results into the REST search response
     * This ensures documents appear in the block editor link picker
     *
     * @param WP_REST_Response $response Response object
     * @param array $handler Handler array
     * @param WP_REST_Request $request Request object
     * @return WP_REST_Response Modified response
     */
    public function inject_document_search_results($response, $handler, $request) {
        // Only intercept search endpoint requests
        $route = $request->get_route();
        if (strpos($route, '/wp/v2/search') === false) {
            return $response;
        }

        // Make sure response is valid
        if (is_wp_error($response) || !($response instanceof WP_REST_Response)) {
            return $response;
        }

        // Only inject when searching (not when type=Document is explicitly requested)
        $requested_type = $request->get_param('type');
        if ($requested_type === 'Document') {
            return $response;
        }

        $search_term = $request->get_param('search');
        if (empty($search_term) || strlen($search_term) < 2) {
            return $response;
        }

        // Get document search results
        require_once plugin_dir_path(__FILE__) . 'includes/class-docdisplay-search-handler.php';
        $doc_handler = new DocDisplay_Search_Handler();

        // Create a modified request for documents
        $doc_request = new WP_REST_Request('GET', '/wp/v2/search');
        $doc_request->set_param('search', $search_term);
        $doc_request->set_param('page', 1);
        $doc_request->set_param('per_page', 5); // Limit document results

        $doc_results = $doc_handler->search_items($doc_request);

        if (empty($doc_results['ids'])) {
            return $response;
        }

        // Prepare document items
        $doc_items = array();
        foreach ($doc_results['ids'] as $id) {
            $item = $doc_handler->prepare_item($id, array());
            if (!empty($item)) {
                $doc_items[] = $item;
            }
        }

        if (empty($doc_items)) {
            return $response;
        }

        // Get existing response data
        $data = $response->get_data();

        // Prepend document results to the response
        $data = array_merge($doc_items, $data);

        $response->set_data($data);

        // Update total count in headers
        $total = $response->get_headers()['X-WP-Total'] ?? 0;
        $response->header('X-WP-Total', $total + count($doc_items));

        return $response;
    }

    /**
     * Enqueue plugin scripts
     */
    public function enqueue_scripts() {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'docdisplay')) {
            wp_enqueue_script(
                'docdisplay-scripts',
                plugin_dir_url(__FILE__) . 'assets/js/docdisplay.js',
                array(),
                DOCDISPLAY_VERSION,
                true
            );
        }
    }

    /**
     * Add settings page to admin menu
     */
    public function add_admin_menu() {
        add_options_page(
            'Parish Documents Settings',
            'Parish Documents',
            'manage_options',
            'docdisplay',
            array($this, 'settings_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'docdisplay_settings',
            $this->option_name,
            array($this, 'sanitize_base_path')
        );

        add_settings_section(
            'docdisplay_main',
            'Main Settings',
            null,
            'docdisplay'
        );

        add_settings_field(
            'base_path',
            'Base Directory Path',
            array($this, 'base_path_field'),
            'docdisplay',
            'docdisplay_main'
        );
    }

    /**
     * Sanitize and validate base path
     */
    public function sanitize_base_path($input) {
        $path = sanitize_text_field($input);
        $path = rtrim($path, '/');

        if (!empty($path) && !is_dir($path)) {
            add_settings_error(
                $this->option_name,
                'invalid_path',
                'The specified directory does not exist.'
            );
            return get_option($this->option_name);
        }

        return $path;
    }

    /**
     * Render base path input field
     */
    public function base_path_field() {
        $value = get_option($this->option_name, '');
        echo '<input type="text" name="' . esc_attr($this->option_name) . '" value="' . esc_attr($value) . '" class="regular-text" />';
        echo '<p class="description">Absolute server path to the base documents directory (e.g., /var/www/html/documents)</p>';
    }

    /**
     * Render settings page
     */
    public function settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                settings_fields('docdisplay_settings');
                do_settings_sections('docdisplay');
                submit_button('Save Settings');
                ?>
            </form>
            <hr>
            <h2>Usage</h2>
            <p>Use the shortcode <code>[docdisplay path="relative/path"]</code> to display files from a directory.</p>
            <p>The path is relative to the base directory configured above.</p>
            <h3>Options</h3>
            <ul>
                <li><code>recursive="true"</code> - Enable subfolder navigation</li>
                <li><code>include="pdf"</code> - Only display files with the specified extension</li>
                <li><code>exclude="doc,docx,odt"</code> - Hide files with specified extensions (comma-separated)</li>
                <li><code>exclude_pattern="compressed|draft"</code> - Hide files matching regex pattern (case-insensitive)</li>
                <li><code>hide_extension="pdf,docx"</code> - Hide specified extensions from displayed filenames</li>
                <li><code>show_title="true"</code> - Show breadcrumb navigation (default: off)</li>
                <li><code>show_empty="true"</code> - Show "No documents found" message (default: off)</li>
                <li><code>directory_sort="asc"</code> - Sort directories ascending (default: desc)</li>
                <li><code>hide_empty_dirs="false"</code> - Show empty directories (default: hidden)</li>
                <li><code>sort_by="name,desc"</code> - Sort files by field and direction: "name,asc", "name,desc", "date,asc", or "date,desc"</li>
                <li><code>show_current="true"</code> - Show "Current" button in folder list (default: off)</li>
                <li><code>limit="10"</code> - Number of documents shown in "Current" view (default: 10)</li>
                <li><code>flatten="true"</code> - Display all files from subdirectories in one flat table (requires recursive="true")</li>
                <li><code>per_page="20"</code> - Enable pagination with specified items per page (default: 0 = show all)</li>
            </ul>
            <h3>Virtual "Current" Folder</h3>
            <p>When <code>recursive="true"</code> and <code>show_current="true"</code> are enabled, a "Current" button appears at the top of the subfolder list. Clicking it shows the most recent documents from all subfolders, sorted by modification date. Use the <code>limit</code> attribute to control how many documents are shown.</p>
            <p><strong>Direct embedding:</strong> You can embed the "current" view directly by ending the path with <code>/current</code>:</p>
            <pre>[docdisplay path="Meeting Documents/Full Council/Minutes/current" recursive="true" flatten="true" limit="20"]</pre>
            <p>This is useful for creating dedicated "latest documents" pages. An error will be shown if a real folder named "current" exists at that location.</p>
            <p><strong>Note:</strong> The <code>/current</code> path requires <code>flatten="true"</code> to show the flat list of recent documents. With <code>flatten="false"</code> (default), the <code>/current</code> path is ignored and normal folder navigation is shown instead.</p>
            <h3>Special Files</h3>
            <ul>
                <li><strong>Commentary.md</strong> - If present in a folder, its content is displayed above the file table (supports basic markdown)</li>
                <li><strong>meta_filename.txt</strong> - Provides a description for the corresponding file</li>
            </ul>
            <h3>Annex Folders</h3>
            <p>Create a folder named <code>{filename}_annexes</code> to add annexes to a document. For example, create <code>Report.pdf_annexes</code> to add annexes to <code>Report.pdf</code>.</p>
        </div>
        <?php
    }

    /**
     * Enqueue plugin styles
     */
    public function enqueue_styles() {
        global $post;
        if (is_a($post, 'WP_Post') && has_shortcode($post->post_content, 'docdisplay')) {
            wp_enqueue_style(
                'docdisplay-styles',
                plugin_dir_url(__FILE__) . 'css/docdisplay.css',
                array(),
                DOCDISPLAY_VERSION
            );
        }
    }

    /**
     * Handle the [docdisplay] shortcode
     */
    public function shortcode_handler($atts) {
        $atts = shortcode_atts(array(
            'path' => '',
            'recursive' => 'false',
            'include' => '',
            'exclude' => '',
            'exclude_pattern' => '',
            'hide_extension' => '',
            'show_title' => 'false',
            'show_empty' => 'false',
            'directory_sort' => 'desc',
            'hide_empty_dirs' => 'true',
            'sort_by' => 'name,desc',
            'limit' => 10,
            'show_current' => 'false',
            'flatten' => 'false',
            'per_page' => 0
        ), $atts, 'docdisplay');

        $base_path = get_option($this->option_name, '');
        $recursive = filter_var($atts['recursive'], FILTER_VALIDATE_BOOLEAN);
        $include_ext = strtolower(ltrim(trim($atts['include']), '.'));

        // Parse exclude as comma-separated list of extensions
        $exclude_ext = array();
        if (!empty($atts['exclude'])) {
            $exclude_ext = array_map(function($ext) {
                return strtolower(ltrim(trim($ext), '.'));
            }, explode(',', $atts['exclude']));
            $exclude_ext = array_filter($exclude_ext);
        }

        // Parse hide_extension as comma-separated list of extensions
        $hide_extensions = array();
        if (!empty($atts['hide_extension'])) {
            $hide_extensions = array_map(function($ext) {
                return strtolower(ltrim(trim($ext), '.'));
            }, explode(',', $atts['hide_extension']));
            $hide_extensions = array_filter($hide_extensions);
        }

        $show_title = filter_var($atts['show_title'], FILTER_VALIDATE_BOOLEAN);
        $show_empty = filter_var($atts['show_empty'], FILTER_VALIDATE_BOOLEAN);
        $directory_sort = strtolower(trim($atts['directory_sort'])) === 'asc' ? 'asc' : 'desc';
        $hide_empty_dirs = filter_var($atts['hide_empty_dirs'], FILTER_VALIDATE_BOOLEAN);

        // Parse sort_by attribute (format: "field,direction" e.g. "name,desc" or "date,asc")
        $sort_by_parts = array_map('trim', explode(',', strtolower($atts['sort_by'])));
        $sort_field = isset($sort_by_parts[0]) && in_array($sort_by_parts[0], array('name', 'date')) ? $sort_by_parts[0] : 'name';
        $sort_direction = isset($sort_by_parts[1]) && in_array($sort_by_parts[1], array('asc', 'desc')) ? $sort_by_parts[1] : 'desc';

        $limit = intval($atts['limit']);
        if ($limit < 1) {
            $limit = 10;
        }
        $show_current = filter_var($atts['show_current'], FILTER_VALIDATE_BOOLEAN);
        $flatten = filter_var($atts['flatten'], FILTER_VALIDATE_BOOLEAN);
        $per_page = intval($atts['per_page']);
        if ($per_page < 0) {
            $per_page = 0;
        }

        // Get current page from URL parameter (1-indexed for users, convert to 0-indexed internally)
        $current_page = 1;
        if (isset($_GET['docdisplay_page'])) {
            $current_page = max(1, intval($_GET['docdisplay_page']));
        }

        if (empty($base_path)) {
            return '<p class="docdisplay-error">Parish Document Display: Base path not configured.</p>';
        }

        // Validate parameter combinations
        if ($show_current && !$recursive) {
            return '<p class="docdisplay-error">Parish Document Display: show_current="true" requires recursive="true".</p>';
        }

        if ($flatten && !$recursive) {
            return '<p class="docdisplay-error">Parish Document Display: flatten="true" requires recursive="true".</p>';
        }

        if (!empty($include_ext) && in_array($include_ext, $exclude_ext)) {
            return '<p class="docdisplay-error">Parish Document Display: Cannot include and exclude the same extension ("' . esc_html($include_ext) . '").</p>';
        }

        // Get the shortcode's base relative path
        $shortcode_path = $this->sanitize_relative_path($atts['path']);

        if ($shortcode_path === false) {
            return '<p class="docdisplay-error">Parish Document Display: Invalid path specified.</p>';
        }

        // Check if shortcode path ends with "/current" (virtual current folder in shortcode)
        $shortcode_current = false;
        $shortcode_current_base = '';
        if (preg_match('#^(.*/)?current$#i', $shortcode_path, $matches)) {
            $shortcode_current = true;
            $shortcode_current_base = isset($matches[1]) ? rtrim($matches[1], '/') : '';

            // Require recursive mode for current folder only when flatten=true (showing recent files)
            if ($flatten && !$recursive) {
                return '<p class="docdisplay-error">Parish Document Display: The "current" virtual folder with flatten="true" requires recursive="true".</p>';
            }

            // Check if a real "current" directory exists - error if so
            $check_path = rtrim($base_path, '/');
            if (!empty($shortcode_current_base)) {
                $check_path .= '/' . $shortcode_current_base;
            }
            $check_path .= '/current';

            if (is_dir($check_path)) {
                return '<p class="docdisplay-error">Parish Document Display: Cannot use virtual "current" folder - a real directory named "current" exists at this location.</p>';
            }
        }

        // Check for URL subpath parameter (for recursive navigation)
        $url_subpath = '';
        if (isset($_GET['docdisplay_path'])) {
            if (!$recursive) {
                return '<p class="docdisplay-error">Parish Document Display: URL navigation requires recursive="true" in shortcode.</p>';
            }
            $url_subpath = $this->sanitize_relative_path($_GET['docdisplay_path']);
            if ($url_subpath === false) {
                return '<p class="docdisplay-error">Parish Document Display: Invalid navigation path.</p>';
            }
        }

        // Validate exclude_pattern regex if provided
        $exclude_pattern = trim($atts['exclude_pattern']);
        if (!empty($exclude_pattern)) {
            // Test if the regex is valid
            if (@preg_match('/' . $exclude_pattern . '/', '') === false) {
                return '<p class="docdisplay-error">Parish Document Display: Invalid exclude_pattern regex.</p>';
            }
        }

        // Build options array
        $options = array(
            'include_ext' => $include_ext,
            'exclude_ext' => $exclude_ext,
            'exclude_pattern' => $exclude_pattern,
            'hide_extensions' => $hide_extensions,
            'show_title' => $show_title,
            'show_empty' => $show_empty,
            'directory_sort' => $directory_sort,
            'hide_empty_dirs' => $hide_empty_dirs,
            'sort_field' => $sort_field,
            'sort_direction' => $sort_direction,
            'limit' => $limit,
            'show_current' => $show_current,
            'flatten' => $flatten,
            'per_page' => $per_page,
            'current_page' => $current_page
        );

        // Check if this is a request for the virtual "current" folder (via URL)
        if ($recursive && strtolower($url_subpath) === 'current') {
            // Build the base directory path (shortcode path only, not url subpath)
            $current_base_path = rtrim($base_path, '/');
            if (!empty($shortcode_path)) {
                $current_base_path .= '/' . $shortcode_path;
            }

            if (!is_dir($current_base_path) || !is_readable($current_base_path)) {
                return '<p class="docdisplay-error">Parish Document Display: Base directory not found or not readable.</p>';
            }

            // Only show "current" flat view if flatten=true, otherwise show normal folder navigation
            if ($flatten) {
                return $this->render_current_folder($current_base_path, $base_path, $shortcode_path, $options);
            } else {
                // Render normal content for the base path (ignore /current)
                return $this->render_content($current_base_path, $base_path, $recursive, $shortcode_path, '', $options);
            }
        }

        // Check if shortcode path itself specifies the virtual "current" folder
        if ($shortcode_current) {
            $current_base_path = rtrim($base_path, '/');
            if (!empty($shortcode_current_base)) {
                $current_base_path .= '/' . $shortcode_current_base;
            }

            if (!is_dir($current_base_path) || !is_readable($current_base_path)) {
                return '<p class="docdisplay-error">Parish Document Display: Base directory not found or not readable.</p>';
            }

            // Only show "current" flat view if flatten=true, otherwise show normal folder navigation
            if ($flatten) {
                return $this->render_current_folder($current_base_path, $base_path, $shortcode_current_base, $options);
            } else {
                // Render normal content for the base path (ignore /current)
                return $this->render_content($current_base_path, $base_path, $recursive, $shortcode_current_base, '', $options);
            }
        }

        // Combine paths: base_path + shortcode_path + url_subpath
        $relative_path = $shortcode_path;
        if (!empty($url_subpath)) {
            $relative_path = !empty($relative_path) ? $relative_path . '/' . $url_subpath : $url_subpath;
        }

        $full_path = rtrim($base_path, '/');
        if (!empty($relative_path)) {
            $full_path .= '/' . $relative_path;
        }

        if (!is_dir($full_path) || !is_readable($full_path)) {
            return '<p class="docdisplay-error">Parish Document Display: Directory not found or not readable.</p>';
        }

        return $this->render_content($full_path, $base_path, $recursive, $shortcode_path, $url_subpath, $options);
    }

    /**
     * Sanitize relative path to prevent directory traversal
     * Allows forward slashes for subdirectory paths
     */
    private function sanitize_relative_path($path) {
        $path = trim($path);

        if (empty($path)) {
            return '';
        }

        // Remove leading/trailing slashes
        $path = trim($path, '/');

        // Block directory traversal attempts
        if (strpos($path, '..') !== false) {
            return false;
        }

        // Remove any null bytes
        $path = str_replace("\0", '', $path);

        // Sanitize each path segment individually
        $segments = explode('/', $path);
        $clean_segments = array();
        foreach ($segments as $segment) {
            $segment = trim($segment);
            if (empty($segment)) {
                continue;
            }
            // Remove dangerous characters but allow alphanumeric, dash, underscore, space, dot
            $segment = preg_replace('/[^a-zA-Z0-9\-_\. ]/', '', $segment);
            if (!empty($segment)) {
                $clean_segments[] = $segment;
            }
        }

        return implode('/', $clean_segments);
    }

    /**
     * Get list of displayable files from directory
     * @param string $dir_path Directory to scan
     * @param string $include_ext Optional extension to include (e.g., 'pdf')
     * @param array $exclude_ext Optional extensions to exclude (e.g., ['doc', 'docx'])
     * @param string $exclude_pattern Optional regex pattern to exclude matching filenames
     */
    private function get_files($dir_path, $include_ext = '', $exclude_ext = array(), $exclude_pattern = '') {
        $files = array();
        $items = scandir($dir_path);

        if ($items === false) {
            return $files;
        }

        foreach ($items as $item) {
            // Skip hidden files
            if ($item[0] === '.') {
                continue;
            }

            $full_item_path = $dir_path . '/' . $item;

            // Skip directories
            if (is_dir($full_item_path)) {
                continue;
            }

            // Skip meta files
            if (strpos($item, 'meta_') === 0) {
                continue;
            }

            // Skip Commentary.md (case-insensitive)
            if (strtolower($item) === 'commentary.md') {
                continue;
            }

            // Filter by extension if specified
            $file_ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));

            if (!empty($include_ext) && $file_ext !== $include_ext) {
                continue;
            }

            if (!empty($exclude_ext) && in_array($file_ext, $exclude_ext)) {
                continue;
            }

            // Filter by exclude_pattern regex if specified
            if (!empty($exclude_pattern) && preg_match('/' . $exclude_pattern . '/i', $item)) {
                continue;
            }

            $files[] = $item;
        }

        // Sort alphabetically
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /**
     * Recursively get all files from a directory tree
     * Returns array of file info with path, name, and mtime
     * @param string $dir_path Directory to scan
     * @param string $include_ext Optional extension to include (e.g., 'pdf')
     * @param array $exclude_ext Optional extensions to exclude
     * @param string $exclude_pattern Optional regex pattern to exclude matching filenames
     * @return array Array of ['path' => full_path, 'name' => filename, 'mtime' => timestamp]
     */
    private function get_all_files_recursive($dir_path, $include_ext = '', $exclude_ext = array(), $exclude_pattern = '', $max_depth = -1, $current_depth = 0) {
        $all_files = array();
        $items = scandir($dir_path);

        if ($items === false) {
            return $all_files;
        }

        // Get list of annex folder names to exclude
        $annex_folders = array();
        foreach ($items as $item) {
            if ($item[0] === '.') continue;
            $full_path = $dir_path . '/' . $item;
            if (!is_dir($full_path)) {
                $annex_folders[] = strtolower($item . '_annexes');
            }
        }

        foreach ($items as $item) {
            // Skip hidden files
            if ($item[0] === '.') {
                continue;
            }

            $full_item_path = $dir_path . '/' . $item;

            // Handle directories - recurse into them (but skip annex folders)
            // Only recurse if max_depth not reached (-1 = unlimited)
            if (is_dir($full_item_path)) {
                if (!in_array(strtolower($item), $annex_folders)) {
                    if ($max_depth === -1 || $current_depth < $max_depth) {
                        $subdir_files = $this->get_all_files_recursive($full_item_path, $include_ext, $exclude_ext, $exclude_pattern, $max_depth, $current_depth + 1);
                        $all_files = array_merge($all_files, $subdir_files);
                    }
                }
                continue;
            }

            // Skip meta files
            if (strpos($item, 'meta_') === 0) {
                continue;
            }

            // Skip Commentary.md (case-insensitive)
            if (strtolower($item) === 'commentary.md') {
                continue;
            }

            // Filter by extension if specified
            $file_ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));

            if (!empty($include_ext) && $file_ext !== $include_ext) {
                continue;
            }

            if (!empty($exclude_ext) && in_array($file_ext, $exclude_ext)) {
                continue;
            }

            // Filter by exclude_pattern regex if specified
            if (!empty($exclude_pattern) && preg_match('/' . $exclude_pattern . '/i', $item)) {
                continue;
            }

            // Get modification time
            $mtime = filemtime($full_item_path);

            $all_files[] = array(
                'path' => $full_item_path,
                'name' => $item,
                'mtime' => $mtime !== false ? $mtime : 0
            );
        }

        return $all_files;
    }

    /**
     * Get files from an annex subfolder matching the document name
     * Annex folders must be named {filename}_annexes (e.g., "Report.pdf_annexes" for "Report.pdf")
     * @param array $exclude_ext Optional extensions to exclude
     * @param string $exclude_pattern Optional regex pattern to exclude matching filenames
     */
    private function get_subfolder_files($dir_path, $filename, $exclude_ext = array(), $exclude_pattern = '') {
        $subfolder_path = $dir_path . '/' . $filename . '_annexes';

        if (!is_dir($subfolder_path) || !is_readable($subfolder_path)) {
            return array();
        }

        $files = array();
        $items = scandir($subfolder_path);

        if ($items === false) {
            return $files;
        }

        foreach ($items as $item) {
            // Skip hidden files
            if ($item[0] === '.') {
                continue;
            }

            $full_item_path = $subfolder_path . '/' . $item;

            // Skip directories
            if (is_dir($full_item_path)) {
                continue;
            }

            // Skip meta files
            if (strpos($item, 'meta_') === 0) {
                continue;
            }

            // Filter by excluded extensions
            if (!empty($exclude_ext)) {
                $file_ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
                if (in_array($file_ext, $exclude_ext)) {
                    continue;
                }
            }

            // Filter by exclude_pattern regex if specified
            if (!empty($exclude_pattern) && preg_match('/' . $exclude_pattern . '/i', $item)) {
                continue;
            }

            $files[] = $item;
        }

        // Sort alphabetically
        sort($files, SORT_NATURAL | SORT_FLAG_CASE);

        return $files;
    }

    /**
     * Get meta description for a file
     */
    private function get_meta_description($dir_path, $filename) {
        $meta_file = $dir_path . '/meta_' . $filename . '.txt';

        if (file_exists($meta_file) && is_readable($meta_file)) {
            $content = file_get_contents($meta_file);
            return sanitize_textarea_field($content);
        }

        return '';
    }

    /**
     * Convert server path to URL
     */
    private function get_file_url($file_path, $base_path) {
        // Get the path relative to base
        $relative = str_replace($base_path, '', $file_path);
        $relative = ltrim($relative, '/');

        // Assume base_path is within the web root
        // Try to determine URL from DOCUMENT_ROOT
        $doc_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/');

        if (strpos($base_path, $doc_root) === 0) {
            $url_path = str_replace($doc_root, '', $base_path);
            return home_url($url_path . '/' . $relative);
        }

        // Fallback: check if it's in wp-content/uploads
        $upload_dir = wp_upload_dir();
        if (strpos($base_path, $upload_dir['basedir']) === 0) {
            $url_path = str_replace($upload_dir['basedir'], $upload_dir['baseurl'], $base_path);
            return $url_path . '/' . $relative;
        }

        // Last resort: construct URL assuming base is relative to site root
        return home_url(str_replace($doc_root, '', $file_path));
    }

    /**
     * Check if file is a PDF
     */
    private function is_pdf($filename) {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'pdf';
    }

    /**
     * Check if file is an ODF document (viewable with ViewerJS)
     */
    private function is_odf($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array('odt', 'ods', 'odp'));
    }

    /**
     * Check if file is a Microsoft Office document (viewable with Google Docs Viewer)
     */
    private function is_office($filename) {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, array('doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx'));
    }

    /**
     * Check if file is viewable in browser (PDF, ODF, or Office)
     */
    private function is_viewable($filename) {
        return $this->is_pdf($filename) || $this->is_odf($filename) || $this->is_office($filename);
    }

    /**
     * Get the viewer URL for a file
     * PDFs open directly, ODF files use ViewerJS, Office files use Google Docs Viewer
     */
    private function get_viewer_url($file_url, $filename) {
        if ($this->is_pdf($filename)) {
            return $file_url;
        }

        if ($this->is_odf($filename)) {
            // ViewerJS URL format: /path/to/viewerjs/#/path/to/document.odt
            $viewerjs_url = plugin_dir_url(__FILE__) . 'viewerjs/index.html#' . $file_url;
            return $viewerjs_url;
        }

        if ($this->is_office($filename)) {
            // Microsoft Office Online Viewer for Office documents
            return 'https://view.officeapps.live.com/op/view.aspx?src=' . urlencode($file_url);
        }

        return $file_url;
    }

    /**
     * Generate a sanitized anchor ID from a filename
     * Used for linking directly to documents via URL fragments
     */
    private function get_document_anchor_id($filename) {
        // Remove extension
        $name = pathinfo($filename, PATHINFO_FILENAME);
        // Replace dots and spaces with hyphens
        $name = preg_replace('/[\.\s]+/', '-', $name);
        // Remove any characters that aren't alphanumeric or hyphens
        $name = preg_replace('/[^a-zA-Z0-9\-]/', '', $name);
        // Collapse multiple hyphens
        $name = preg_replace('/-+/', '-', $name);
        // Trim hyphens from ends
        $name = trim($name, '-');
        return 'doc-' . $name;
    }

    /**
     * Get file modification date formatted
     */
    private function get_file_date($file_path) {
        $mtime = filemtime($file_path);
        if ($mtime === false) {
            return '';
        }
        return date('j M Y', $mtime);
    }

    /**
     * Check if any meta files exist for the given files
     */
    private function has_any_meta_files($dir_path, $files) {
        foreach ($files as $file) {
            $meta_file = $dir_path . '/meta_' . $file . '.txt';
            if (file_exists($meta_file)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get navigable subfolders (excludes annex folders named {filename}_annexes)
     */
    private function get_subfolders($dir_path) {
        $folders = array();
        $items = scandir($dir_path);

        if ($items === false) {
            return $folders;
        }

        // Get list of annex folder names to exclude (filename_annexes for each file)
        $annex_folders = array();
        foreach ($items as $item) {
            if ($item[0] === '.') continue;
            $full_path = $dir_path . '/' . $item;
            if (!is_dir($full_path)) {
                $annex_folders[] = strtolower($item . '_annexes');
            }
        }

        foreach ($items as $item) {
            // Skip hidden folders
            if ($item[0] === '.') {
                continue;
            }

            $full_item_path = $dir_path . '/' . $item;

            // Only include directories
            if (!is_dir($full_item_path)) {
                continue;
            }

            // Skip annex folders (those named {filename}_annexes)
            if (in_array(strtolower($item), $annex_folders)) {
                continue;
            }

            $folders[] = $item;
        }

        // Sort alphabetically
        sort($folders, SORT_NATURAL | SORT_FLAG_CASE);

        return $folders;
    }

    /**
     * Check if a directory has displayable content (recursively)
     */
    private function directory_has_content($dir_path, $include_ext = '', $exclude_ext = array(), $exclude_pattern = '') {
        // Check if this directory has files
        $files = $this->get_files($dir_path, $include_ext, $exclude_ext, $exclude_pattern);
        if (!empty($files)) {
            return true;
        }

        // Check subdirectories recursively
        $subfolders = $this->get_subfolders($dir_path);
        foreach ($subfolders as $subfolder) {
            if ($this->directory_has_content($dir_path . '/' . $subfolder, $include_ext, $exclude_ext, $exclude_pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Render breadcrumb navigation
     */
    private function render_breadcrumbs($shortcode_path, $url_subpath) {
        // Get the root name from shortcode path or use "Documents"
        $root_name = !empty($shortcode_path) ? basename($shortcode_path) : 'Documents';
        $root_name = ucfirst($root_name);

        // Get current page URL without query params
        $current_url = strtok($_SERVER['REQUEST_URI'], '?');

        $output = '<nav class="docdisplay-breadcrumbs">';

        if (empty($url_subpath)) {
            // At root level - just show root name (not clickable)
            $output .= '<span>' . esc_html($root_name) . '</span>';
        } else {
            // Show clickable root
            $output .= '<a href="' . esc_url($current_url) . '">' . esc_html($root_name) . '</a>';

            // Build path segments
            $segments = explode('/', $url_subpath);
            $path_so_far = '';

            foreach ($segments as $index => $segment) {
                $output .= ' &rsaquo; ';
                $path_so_far .= ($path_so_far ? '/' : '') . $segment;

                if ($index === count($segments) - 1) {
                    // Last segment - not clickable
                    $output .= '<span>' . esc_html(ucfirst($segment)) . '</span>';
                } else {
                    // Intermediate segment - clickable
                    $link_url = $current_url . '?docdisplay_path=' . urlencode($path_so_far);
                    $output .= '<a href="' . esc_url($link_url) . '">' . esc_html(ucfirst($segment)) . '</a>';
                }
            }
        }

        $output .= '</nav>';

        return $output;
    }

    /**
     * Render Commentary.md content if present
     */
    private function render_commentary($dir_path) {
        $commentary_file = $dir_path . '/Commentary.md';

        // Case-insensitive check
        if (!file_exists($commentary_file)) {
            $commentary_file = $dir_path . '/commentary.md';
        }

        if (!file_exists($commentary_file) || !is_readable($commentary_file)) {
            return '';
        }

        $content = file_get_contents($commentary_file);
        if (empty($content)) {
            return '';
        }

        // Convert basic markdown to HTML
        $html = $this->parse_markdown($content);

        return '<div class="docdisplay-commentary">' . $html . '</div>';
    }

    /**
     * Basic markdown to HTML conversion
     */
    private function parse_markdown($text) {
        // Normalize line endings
        $text = str_replace("\r\n", "\n", $text);
        $text = str_replace("\r", "\n", $text);

        // Split into lines for processing
        $lines = explode("\n", $text);
        $html_lines = array();
        $in_list = false;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            // Skip empty lines but close any open list
            if (empty($trimmed)) {
                if ($in_list) {
                    $html_lines[] = '</ul>';
                    $in_list = false;
                }
                continue;
            }

            // Headers
            if (preg_match('/^### (.+)$/', $trimmed, $matches)) {
                if ($in_list) { $html_lines[] = '</ul>'; $in_list = false; }
                $html_lines[] = '<h4>' . esc_html($matches[1]) . '</h4>';
                continue;
            }
            if (preg_match('/^## (.+)$/', $trimmed, $matches)) {
                if ($in_list) { $html_lines[] = '</ul>'; $in_list = false; }
                $html_lines[] = '<h3>' . esc_html($matches[1]) . '</h3>';
                continue;
            }
            if (preg_match('/^# (.+)$/', $trimmed, $matches)) {
                if ($in_list) { $html_lines[] = '</ul>'; $in_list = false; }
                $html_lines[] = '<h2>' . esc_html($matches[1]) . '</h2>';
                continue;
            }

            // List items
            if (preg_match('/^[\-\*] (.+)$/', $trimmed, $matches)) {
                if (!$in_list) {
                    $html_lines[] = '<ul>';
                    $in_list = true;
                }
                $html_lines[] = '<li>' . $this->parse_inline_markdown(esc_html($matches[1])) . '</li>';
                continue;
            }

            // Close list if we hit a non-list line
            if ($in_list) {
                $html_lines[] = '</ul>';
                $in_list = false;
            }

            // Regular paragraph
            $html_lines[] = '<p>' . $this->parse_inline_markdown(esc_html($trimmed)) . '</p>';
        }

        // Close any remaining open list
        if ($in_list) {
            $html_lines[] = '</ul>';
        }

        return implode("\n", $html_lines);
    }

    /**
     * Parse inline markdown (bold, italic, links)
     */
    private function parse_inline_markdown($text) {
        // Bold (**text** or __text__)
        $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__(.+?)__/', '<strong>$1</strong>', $text);

        // Italic (*text* or _text_) - be careful not to match already processed strong tags
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
        $text = preg_replace('/(?<!_)_([^_]+)_(?!_)/', '<em>$1</em>', $text);

        // Links [text](url)
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '<a href="$2" target="_blank" rel="noopener">$1</a>', $text);

        return $text;
    }

    /**
     * Render complete content (breadcrumbs, commentary, table, subfolders)
     */
    private function render_content($dir_path, $base_path, $recursive, $shortcode_path, $url_subpath, $options = array()) {
        $output = '';
        $show_title = isset($options['show_title']) ? $options['show_title'] : false;
        $flatten = isset($options['flatten']) ? $options['flatten'] : false;

        // Breadcrumbs (only if recursive and show_title enabled)
        if ($recursive && $show_title) {
            $output .= $this->render_breadcrumbs($shortcode_path, $url_subpath);
        }

        // Commentary
        $output .= $this->render_commentary($dir_path);

        // If flatten mode, show all files from all subdirectories in one table
        if ($flatten && $recursive) {
            $output .= $this->render_flattened_table($dir_path, $base_path, $options);
            return $output;
        }

        // File table
        $output .= $this->render_file_table($dir_path, $base_path, $options);

        // Subfolders list (only if recursive mode)
        if ($recursive) {
            $output .= $this->render_subfolders($dir_path, $url_subpath, $options);
        }

        return $output;
    }

    /**
     * Render a flattened table showing all files from all subdirectories
     */
    private function render_flattened_table($dir_path, $base_path, $options = array()) {
        $output = '';
        $show_empty = isset($options['show_empty']) ? $options['show_empty'] : false;
        $include_ext = isset($options['include_ext']) ? $options['include_ext'] : '';
        $exclude_ext = isset($options['exclude_ext']) ? $options['exclude_ext'] : array();
        $exclude_pattern = isset($options['exclude_pattern']) ? $options['exclude_pattern'] : '';
        $hide_extensions = isset($options['hide_extensions']) ? $options['hide_extensions'] : array();
        $sort_field = isset($options['sort_field']) ? $options['sort_field'] : 'name';
        $sort_direction = isset($options['sort_direction']) ? $options['sort_direction'] : 'desc';
        $per_page = isset($options['per_page']) ? $options['per_page'] : 0;
        $current_page = isset($options['current_page']) ? $options['current_page'] : 1;

        // Get all files recursively (unlimited depth)
        $all_files = $this->get_all_files_recursive($dir_path, $include_ext, $exclude_ext, $exclude_pattern, -1);

        if (empty($all_files)) {
            if ($show_empty) {
                $output .= '<p class="docdisplay-empty">No documents found.</p>';
            }
            return $output;
        }

        // Sort files by specified field
        usort($all_files, function($a, $b) use ($sort_field, $sort_direction) {
            if ($sort_field === 'date') {
                $cmp = $a['mtime'] - $b['mtime'];
            } else {
                $cmp = strnatcasecmp($a['name'], $b['name']);
            }
            return $sort_direction === 'asc' ? $cmp : -$cmp;
        });

        // Pagination: get total count before slicing
        $total_files = count($all_files);

        // Apply pagination if per_page is set
        if ($per_page > 0) {
            $offset = ($current_page - 1) * $per_page;
            $all_files = array_slice($all_files, $offset, $per_page);
        }

        // Render table
        $output .= '<table class="docdisplay-table">';
        $output .= '<thead>';
        $output .= '<tr>';
        $output .= '<th>File Name</th>';
        $output .= '<th>Document Date</th>';
        $output .= '<th>Download</th>';
        $output .= '</tr>';
        $output .= '</thead>';
        $output .= '<tbody>';

        foreach ($all_files as $file_info) {
            $file_path = $file_info['path'];
            $file_name = $file_info['name'];
            $file_dir = dirname($file_path);
            $file_url = $this->get_file_url($file_path, $base_path);
            $file_date = date('j M Y', $file_info['mtime']);
            $is_viewable = $this->is_viewable($file_name);

            // Display name (hide extension if in hide_extensions list)
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $display_name = (!empty($hide_extensions) && in_array($file_ext, $hide_extensions))
                ? pathinfo($file_name, PATHINFO_FILENAME)
                : $file_name;

            // Generate anchor ID for direct linking
            $anchor_id = $this->get_document_anchor_id($file_name);

            $output .= '<tr id="' . esc_attr($anchor_id) . '" class="docdisplay-file-row">';

            // File Name column
            $output .= '<td>';
            if ($is_viewable) {
                $viewer_url = $this->get_viewer_url($file_url, $file_name);
                $output .= '<a href="' . esc_url($viewer_url) . '" target="_blank" rel="noopener">' . esc_html($display_name) . '</a>';
            } else {
                $output .= esc_html($display_name);
            }
            $output .= '</td>';

            // Document Date column
            $output .= '<td>' . esc_html($file_date) . '</td>';

            // Download column
            $output .= '<td>';
            $output .= '<a href="' . esc_url($file_url) . '" download="' . esc_attr($file_name) . '" class="docdisplay-download">Download</a>';
            $output .= '</td>';

            $output .= '</tr>';

            // Check for matching annex subfolder (named {filename}_annexes)
            $subfolder_files = $this->get_subfolder_files($file_dir, $file_name, $exclude_ext, $exclude_pattern);
            if (!empty($subfolder_files)) {
                $subfolder_path = $file_dir . '/' . $file_name . '_annexes';

                $output .= '<tr class="docdisplay-subdocs-row docdisplay-annexes-for-' . esc_attr($anchor_id) . '">';
                $output .= '<td colspan="3">';
                $output .= '<div class="docdisplay-subdocs">';

                foreach ($subfolder_files as $subfile) {
                    $subfile_path = $subfolder_path . '/' . $subfile;
                    $subfile_url = $this->get_file_url($subfile_path, $base_path);
                    $is_subfile_viewable = $this->is_viewable($subfile);

                    // Display name (hide extension if in hide_extensions list)
                    $subfile_ext = strtolower(pathinfo($subfile, PATHINFO_EXTENSION));
                    $subfile_display = (!empty($hide_extensions) && in_array($subfile_ext, $hide_extensions))
                        ? pathinfo($subfile, PATHINFO_FILENAME)
                        : $subfile;

                    $output .= '<span class="docdisplay-subdoc-item">';
                    if ($is_subfile_viewable) {
                        $subfile_viewer_url = $this->get_viewer_url($subfile_url, $subfile);
                        $output .= '<a href="' . esc_url($subfile_viewer_url) . '" target="_blank" rel="noopener">' . esc_html($subfile_display) . '</a>';
                    } else {
                        $output .= esc_html($subfile_display);
                    }
                    $output .= ' <a href="' . esc_url($subfile_url) . '" download="' . esc_attr($subfile) . '" class="docdisplay-subdoc-download" title="Download">&#x2B07;</a>';
                    $output .= '</span>';
                }

                $output .= '</div>';
                $output .= '</td>';
                $output .= '</tr>';
            }
        }

        $output .= '</tbody>';
        $output .= '</table>';

        // Add pagination if enabled
        $output .= $this->render_pagination($total_files, $per_page, $current_page);

        return $output;
    }

    /**
     * Render the virtual "current" folder showing latest documents across all subfolders
     */
    private function render_current_folder($dir_path, $base_path, $shortcode_path, $options = array()) {
        $output = '';
        $show_title = isset($options['show_title']) ? $options['show_title'] : false;
        $show_empty = isset($options['show_empty']) ? $options['show_empty'] : false;
        $include_ext = isset($options['include_ext']) ? $options['include_ext'] : '';
        $exclude_ext = isset($options['exclude_ext']) ? $options['exclude_ext'] : array();
        $exclude_pattern = isset($options['exclude_pattern']) ? $options['exclude_pattern'] : '';
        $hide_extensions = isset($options['hide_extensions']) ? $options['hide_extensions'] : array();
        $limit = isset($options['limit']) ? $options['limit'] : 10;

        // Breadcrumbs showing "Current" as current location
        if ($show_title) {
            $output .= $this->render_breadcrumbs($shortcode_path, 'current');
        }

        // Get files from immediate subdirectories only (one level deep)
        $all_files = $this->get_all_files_recursive($dir_path, $include_ext, $exclude_ext, $exclude_pattern, 1);

        if (empty($all_files)) {
            if ($show_empty) {
                $output .= '<p class="docdisplay-empty">No documents found.</p>';
            }
            return $output;
        }

        // Current folder always sorts by date descending (most recent first)
        usort($all_files, function($a, $b) {
            return $b['mtime'] - $a['mtime'];
        });

        // Apply limit
        $all_files = array_slice($all_files, 0, $limit);

        // Render table
        $output .= '<table class="docdisplay-table">';
        $output .= '<thead>';
        $output .= '<tr>';
        $output .= '<th>File Name</th>';
        $output .= '<th>Document Date</th>';
        $output .= '<th>Download</th>';
        $output .= '</tr>';
        $output .= '</thead>';
        $output .= '<tbody>';

        foreach ($all_files as $file_info) {
            $file_path = $file_info['path'];
            $file_name = $file_info['name'];
            $file_dir = dirname($file_path);
            $file_url = $this->get_file_url($file_path, $base_path);
            $file_date = date('j M Y', $file_info['mtime']);
            $is_viewable = $this->is_viewable($file_name);

            // Display name (hide extension if in hide_extensions list)
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $display_name = (!empty($hide_extensions) && in_array($file_ext, $hide_extensions))
                ? pathinfo($file_name, PATHINFO_FILENAME)
                : $file_name;

            // Generate anchor ID for direct linking
            $anchor_id = $this->get_document_anchor_id($file_name);

            $output .= '<tr id="' . esc_attr($anchor_id) . '" class="docdisplay-file-row">';

            // File Name column
            $output .= '<td>';
            if ($is_viewable) {
                $viewer_url = $this->get_viewer_url($file_url, $file_name);
                $output .= '<a href="' . esc_url($viewer_url) . '" target="_blank" rel="noopener">' . esc_html($display_name) . '</a>';
            } else {
                $output .= esc_html($display_name);
            }
            $output .= '</td>';

            // Published column
            $output .= '<td>' . esc_html($file_date) . '</td>';

            // Download column
            $output .= '<td>';
            $output .= '<a href="' . esc_url($file_url) . '" download="' . esc_attr($file_name) . '" class="docdisplay-download">Download</a>';
            $output .= '</td>';

            $output .= '</tr>';

            // Check for matching annex subfolder (named {filename}_annexes)
            $subfolder_files = $this->get_subfolder_files($file_dir, $file_name, $exclude_ext, $exclude_pattern);
            if (!empty($subfolder_files)) {
                $subfolder_path = $file_dir . '/' . $file_name . '_annexes';

                $output .= '<tr class="docdisplay-subdocs-row docdisplay-annexes-for-' . esc_attr($anchor_id) . '">';
                $output .= '<td colspan="3">';
                $output .= '<div class="docdisplay-subdocs">';

                foreach ($subfolder_files as $subfile) {
                    $subfile_path = $subfolder_path . '/' . $subfile;
                    $subfile_url = $this->get_file_url($subfile_path, $base_path);
                    $is_subfile_viewable = $this->is_viewable($subfile);

                    // Display name (hide extension if in hide_extensions list)
                    $subfile_ext = strtolower(pathinfo($subfile, PATHINFO_EXTENSION));
                    $subfile_display = (!empty($hide_extensions) && in_array($subfile_ext, $hide_extensions))
                        ? pathinfo($subfile, PATHINFO_FILENAME)
                        : $subfile;

                    $output .= '<span class="docdisplay-subdoc-item">';
                    if ($is_subfile_viewable) {
                        $subfile_viewer_url = $this->get_viewer_url($subfile_url, $subfile);
                        $output .= '<a href="' . esc_url($subfile_viewer_url) . '" target="_blank" rel="noopener">' . esc_html($subfile_display) . '</a>';
                    } else {
                        $output .= esc_html($subfile_display);
                    }
                    $output .= ' <a href="' . esc_url($subfile_url) . '" download="' . esc_attr($subfile) . '" class="docdisplay-subdoc-download" title="Download">&#x2B07;</a>';
                    $output .= '</span>';
                }

                $output .= '</div>';
                $output .= '</td>';
                $output .= '</tr>';
            }
        }

        $output .= '</tbody>';
        $output .= '</table>';

        return $output;
    }

    /**
     * Render the subfolder list (displayed before the file table)
     */
    private function render_subfolders($dir_path, $url_subpath, $options = array()) {
        $folders = $this->get_subfolders($dir_path);

        $sort_order = isset($options['directory_sort']) ? $options['directory_sort'] : 'desc';
        $hide_empty_dirs = isset($options['hide_empty_dirs']) ? $options['hide_empty_dirs'] : true;
        $include_ext = isset($options['include_ext']) ? $options['include_ext'] : '';
        $exclude_ext = isset($options['exclude_ext']) ? $options['exclude_ext'] : array();
        $exclude_pattern = isset($options['exclude_pattern']) ? $options['exclude_pattern'] : '';

        // Filter out empty directories if hide_empty_dirs is enabled
        if ($hide_empty_dirs && !empty($folders)) {
            $folders = array_filter($folders, function($folder) use ($dir_path, $include_ext, $exclude_ext, $exclude_pattern) {
                return $this->directory_has_content($dir_path . '/' . $folder, $include_ext, $exclude_ext, $exclude_pattern);
            });
        }

        // Determine if we should show the "Current" button (only at root level and if enabled)
        $show_current_option = isset($options['show_current']) ? $options['show_current'] : false;
        $show_current_button = $show_current_option && empty($url_subpath);

        // If no folders and no current button to show, return empty
        if (empty($folders) && !$show_current_button) {
            return '';
        }

        // Apply sort order (folders are already sorted asc by get_subfolders)
        if (!empty($folders) && $sort_order === 'desc') {
            $folders = array_reverse($folders);
        }

        // Get current page URL for folder links
        $current_url = strtok($_SERVER['REQUEST_URI'], '?');

        $output = '<div class="docdisplay-folders">';
        $output .= '<ul class="docdisplay-folder-list">';

        // Add "Current" button first (only at root level)
        if ($show_current_button) {
            $current_folder_url = $current_url . '?docdisplay_path=current';
            $output .= '<li>';
            $output .= '<a href="' . esc_url($current_folder_url) . '" class="docdisplay-folder-link docdisplay-current-link">';
            $output .= '&#x1F4C4; Current';
            $output .= '</a>';
            $output .= '</li>';
        }

        foreach ($folders as $folder) {
            $folder_subpath = !empty($url_subpath) ? $url_subpath . '/' . $folder : $folder;
            $folder_url = $current_url . '?docdisplay_path=' . urlencode($folder_subpath);

            $output .= '<li>';
            $output .= '<a href="' . esc_url($folder_url) . '" class="docdisplay-folder-link">';
            $output .= '&#x1F4C1; ' . esc_html($folder);
            $output .= '</a>';
            $output .= '</li>';
        }

        $output .= '</ul>';
        $output .= '</div>';

        return $output;
    }

    /**
     * Render pagination navigation
     * @param int $total_items Total number of items
     * @param int $per_page Items per page (0 = no pagination)
     * @param int $current_page Current page number (1-indexed)
     * @return string Pagination HTML
     */
    private function render_pagination($total_items, $per_page, $current_page) {
        // No pagination if per_page is 0 or total items fit on one page
        if ($per_page <= 0 || $total_items <= $per_page) {
            return '';
        }

        $total_pages = ceil($total_items / $per_page);

        // Ensure current page is within bounds
        if ($current_page < 1) {
            $current_page = 1;
        }
        if ($current_page > $total_pages) {
            $current_page = $total_pages;
        }

        // Build current URL preserving other query params but updating/adding docdisplay_page
        $current_url = strtok($_SERVER['REQUEST_URI'], '?');
        $query_params = array();
        if (!empty($_SERVER['QUERY_STRING'])) {
            parse_str($_SERVER['QUERY_STRING'], $query_params);
        }

        $output = '<nav class="docdisplay-pagination">';

        // Previous link
        if ($current_page > 1) {
            $query_params['docdisplay_page'] = $current_page - 1;
            $prev_url = $current_url . '?' . http_build_query($query_params);
            $output .= '<a href="' . esc_url($prev_url) . '" class="docdisplay-page-link docdisplay-page-prev">&laquo; Previous</a>';
        } else {
            $output .= '<span class="docdisplay-page-link docdisplay-page-prev docdisplay-page-disabled">&laquo; Previous</span>';
        }

        // Page info
        $output .= '<span class="docdisplay-page-info">Page ' . $current_page . ' of ' . $total_pages . '</span>';

        // Next link
        if ($current_page < $total_pages) {
            $query_params['docdisplay_page'] = $current_page + 1;
            $next_url = $current_url . '?' . http_build_query($query_params);
            $output .= '<a href="' . esc_url($next_url) . '" class="docdisplay-page-link docdisplay-page-next">Next &raquo;</a>';
        } else {
            $output .= '<span class="docdisplay-page-link docdisplay-page-next docdisplay-page-disabled">Next &raquo;</span>';
        }

        $output .= '</nav>';

        return $output;
    }

    /**
     * Render the file table
     */
    private function render_file_table($dir_path, $base_path, $options = array()) {
        $include_ext = isset($options['include_ext']) ? $options['include_ext'] : '';
        $exclude_ext = isset($options['exclude_ext']) ? $options['exclude_ext'] : array();
        $exclude_pattern = isset($options['exclude_pattern']) ? $options['exclude_pattern'] : '';
        $hide_extensions = isset($options['hide_extensions']) ? $options['hide_extensions'] : array();
        $show_empty = isset($options['show_empty']) ? $options['show_empty'] : false;
        $sort_field = isset($options['sort_field']) ? $options['sort_field'] : 'name';
        $sort_direction = isset($options['sort_direction']) ? $options['sort_direction'] : 'desc';
        $per_page = isset($options['per_page']) ? $options['per_page'] : 0;
        $current_page = isset($options['current_page']) ? $options['current_page'] : 1;

        $file_names = $this->get_files($dir_path, $include_ext, $exclude_ext, $exclude_pattern);

        if (empty($file_names)) {
            return $show_empty ? '<p class="docdisplay-empty">No documents found in this directory.</p>' : '';
        }

        // Build file info array with mtime for sorting
        $files = array();
        foreach ($file_names as $name) {
            $file_path = $dir_path . '/' . $name;
            $mtime = filemtime($file_path);
            $files[] = array(
                'name' => $name,
                'mtime' => $mtime !== false ? $mtime : 0
            );
        }

        // Sort by specified field
        usort($files, function($a, $b) use ($sort_field, $sort_direction) {
            if ($sort_field === 'date') {
                $cmp = $a['mtime'] - $b['mtime'];
            } else {
                $cmp = strnatcasecmp($a['name'], $b['name']);
            }
            return $sort_direction === 'asc' ? $cmp : -$cmp;
        });

        // Pagination: get total count before slicing
        $total_files = count($files);

        // Apply pagination if per_page is set
        if ($per_page > 0) {
            $offset = ($current_page - 1) * $per_page;
            $files = array_slice($files, $offset, $per_page);
        }

        $show_description = $this->has_any_meta_files($dir_path, $file_names);
        $colspan = $show_description ? 4 : 3;

        $output = '<table class="docdisplay-table">';
        $output .= '<thead>';
        $output .= '<tr>';
        $output .= '<th>File Name</th>';
        $output .= '<th>Document Date</th>';
        $output .= '<th>Download</th>';
        if ($show_description) {
            $output .= '<th>Description</th>';
        }
        $output .= '</tr>';
        $output .= '</thead>';
        $output .= '<tbody>';

        // Render file rows
        foreach ($files as $file_info) {
            $file = $file_info['name'];
            $file_path = $dir_path . '/' . $file;
            $file_url = $this->get_file_url($file_path, $base_path);
            $file_date = date('j M Y', $file_info['mtime']);
            $is_viewable = $this->is_viewable($file);

            // Display name (hide extension if file's extension is in hide_extensions list)
            $file_ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
            $display_name = (!empty($hide_extensions) && in_array($file_ext, $hide_extensions))
                ? pathinfo($file, PATHINFO_FILENAME)
                : $file;

            // Generate anchor ID for direct linking
            $anchor_id = $this->get_document_anchor_id($file);

            $output .= '<tr id="' . esc_attr($anchor_id) . '" class="docdisplay-file-row">';

            // File Name column
            $output .= '<td>';
            if ($is_viewable) {
                $viewer_url = $this->get_viewer_url($file_url, $file);
                $output .= '<a href="' . esc_url($viewer_url) . '" target="_blank" rel="noopener">' . esc_html($display_name) . '</a>';
            } else {
                $output .= esc_html($display_name);
            }
            $output .= '</td>';

            // Published column
            $output .= '<td>' . esc_html($file_date) . '</td>';

            // Download column
            $output .= '<td>';
            $output .= '<a href="' . esc_url($file_url) . '" download="' . esc_attr($file) . '" class="docdisplay-download">Download</a>';
            $output .= '</td>';

            // Description column (only if any meta files exist)
            if ($show_description) {
                $description = $this->get_meta_description($dir_path, $file);
                $output .= '<td>' . esc_html($description) . '</td>';
            }

            $output .= '</tr>';

            // Check for matching annex subfolder (named {filename}_annexes)
            $subfolder_files = $this->get_subfolder_files($dir_path, $file, $exclude_ext, $exclude_pattern);
            if (!empty($subfolder_files)) {
                $subfolder_path = $dir_path . '/' . $file . '_annexes';

                $output .= '<tr class="docdisplay-subdocs-row docdisplay-annexes-for-' . esc_attr($anchor_id) . '">';
                $output .= '<td colspan="' . $colspan . '">';
                $output .= '<div class="docdisplay-subdocs">';

                foreach ($subfolder_files as $subfile) {
                    $subfile_path = $subfolder_path . '/' . $subfile;
                    $subfile_url = $this->get_file_url($subfile_path, $base_path);
                    $is_subfile_viewable = $this->is_viewable($subfile);

                    // Display name (hide extension if in hide_extensions list)
                    $subfile_ext = strtolower(pathinfo($subfile, PATHINFO_EXTENSION));
                    $subfile_display = (!empty($hide_extensions) && in_array($subfile_ext, $hide_extensions))
                        ? pathinfo($subfile, PATHINFO_FILENAME)
                        : $subfile;

                    $output .= '<span class="docdisplay-subdoc-item">';
                    if ($is_subfile_viewable) {
                        $subfile_viewer_url = $this->get_viewer_url($subfile_url, $subfile);
                        $output .= '<a href="' . esc_url($subfile_viewer_url) . '" target="_blank" rel="noopener">' . esc_html($subfile_display) . '</a>';
                    } else {
                        $output .= esc_html($subfile_display);
                    }
                    $output .= ' <a href="' . esc_url($subfile_url) . '" download="' . esc_attr($subfile) . '" class="docdisplay-subdoc-download" title="Download">&#x2B07;</a>';
                    $output .= '</span>';
                }

                $output .= '</div>';
                $output .= '</td>';
                $output .= '</tr>';
            }
        }

        $output .= '</tbody>';
        $output .= '</table>';

        // Add pagination if enabled
        $output .= $this->render_pagination($total_files, $per_page, $current_page);

        return $output;
    }
}

// Initialize the plugin
DocDisplay::get_instance();
