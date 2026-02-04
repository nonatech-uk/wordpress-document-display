<?php
/**
 * DocDisplay Search Handler for WordPress REST API
 *
 * Integrates with WordPress block editor link picker to allow searching
 * for documents managed by the docdisplay plugin.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Search handler for docdisplay documents
 */
class DocDisplay_Search_Handler extends WP_REST_Search_Handler {

    /**
     * Search type identifier - displays as label in link picker
     */
    public $type = 'Document';

    /**
     * Constructor
     */
    public function __construct() {
        $this->subtypes = array('document');
    }

    /**
     * Searches documents for a given search request
     *
     * @param WP_REST_Request $request Full REST request
     * @return array Array containing an 'ids' entry with found document identifiers
     *               and a 'total' entry with the total count
     */
    public function search_items(WP_REST_Request $request) {
        $search_term = $request->get_param('search');
        $page = (int) $request->get_param('page');
        $per_page = (int) $request->get_param('per_page');

        if ($page < 1) {
            $page = 1;
        }
        if ($per_page < 1) {
            $per_page = 10;
        }

        $base_path = get_option('docdisplay_base_path', '');
        if (empty($base_path) || !is_dir($base_path)) {
            return array(
                'ids' => array(),
                'total' => 0
            );
        }

        // Find all matching documents
        $all_matches = $this->find_matching_documents($base_path, $search_term);

        // Calculate pagination
        $total = count($all_matches);
        $offset = ($page - 1) * $per_page;
        $paginated = array_slice($all_matches, $offset, $per_page);

        // Return document identifiers (relative paths)
        $ids = array_map(function($doc) {
            return $doc['id'];
        }, $paginated);

        return array(
            'ids' => $ids,
            'total' => $total
        );
    }

    /**
     * Prepares a document for the search response
     *
     * @param string $id Document identifier (relative path from base)
     * @param array  $fields Fields to include in response
     * @return array Associative array with document data
     */
    public function prepare_item($id, array $fields) {
        $base_path = get_option('docdisplay_base_path', '');
        $full_path = rtrim($base_path, '/') . '/' . ltrim($id, '/');

        if (!file_exists($full_path)) {
            return array();
        }

        $filename = basename($full_path);
        $dir_path = dirname($full_path);
        $relative_dir = str_replace($base_path, '', $dir_path);
        $relative_dir = trim($relative_dir, '/');

        // Count annexes
        $annex_count = $this->count_annexes($dir_path, $filename);

        // Get display title (filename without extension)
        $title = pathinfo($filename, PATHINFO_FILENAME);
        // Clean up title - replace dots/underscores with spaces
        $title = preg_replace('/[._]+/', ' ', $title);

        // Build breadcrumb path for display
        $breadcrumb = $this->build_breadcrumb($relative_dir);

        // Extract year/folder prefix from path (last segment)
        $path_parts = array_filter(explode('/', $relative_dir));
        $folder_prefix = !empty($path_parts) ? end($path_parts) : '';

        // Build shortened breadcrumb (remove last segment since it's the prefix, and first segment if it's generic)
        $breadcrumb_parts = array_filter(explode(' > ', $breadcrumb));
        if (!empty($breadcrumb_parts)) {
            array_pop($breadcrumb_parts); // Remove last (year/folder - now prefix)
            // Remove first part if it's "Meeting Documents" (too generic)
            if (!empty($breadcrumb_parts) && $breadcrumb_parts[0] === 'Meeting Documents') {
                array_shift($breadcrumb_parts);
            }
        }
        $short_breadcrumb = implode(' > ', $breadcrumb_parts);

        // Format title: "2024: 12 24 APC Agenda — Full Council > Agendas"
        $display_title = '';
        if (!empty($folder_prefix)) {
            $display_title .= $folder_prefix . ': ';
        }
        $display_title .= $title;
        if ($annex_count > 0) {
            $display_title .= ' (' . $annex_count . ' ' . ($annex_count === 1 ? 'annex' : 'annexes') . ')';
        }
        if (!empty($short_breadcrumb)) {
            $display_title .= ' — ' . $short_breadcrumb;
        }

        // Find the WordPress page that displays this document
        $page_info = $this->find_display_page($relative_dir);

        // Build the URL with anchor
        $anchor_id = $this->get_document_anchor_id($filename);
        $url = '';

        if ($page_info) {
            $url = get_permalink($page_info['page_id']);

            // Add subpath parameter if needed
            $subpath = $this->get_subpath($relative_dir, $page_info['shortcode_path']);
            if (!empty($subpath)) {
                $url = add_query_arg('docdisplay_path', $subpath, $url);
            }

            // Add anchor
            $url .= '#' . $anchor_id;
        }

        $result = array(
            'id' => $id,
            'title' => $display_title,
            'url' => $url,
            'type' => $this->type,
            'subtype' => 'document',
            '_links' => array(
                'self' => array(
                    'embeddable' => true,
                    'href' => rest_url('wp/v2/search/' . urlencode($id)),
                ),
            ),
        );

        // Add custom metadata for display
        if (in_array('_docdisplay', $fields, true) || empty($fields)) {
            $result['_docdisplay'] = array(
                'annex_count' => $annex_count,
                'breadcrumb' => $breadcrumb,
                'anchor_id' => $anchor_id,
                'page_found' => !empty($page_info),
            );
        }

        return $result;
    }

    /**
     * Prepares links for the search response
     *
     * @param int $id Item ID
     * @return array Links for the given item
     */
    public function prepare_item_links($id) {
        return array();
    }

    /**
     * Find documents matching the search term
     *
     * @param string $base_path Base documents directory
     * @param string $search_term Search term
     * @return array Array of matching documents
     */
    private function find_matching_documents($base_path, $search_term) {
        $matches = array();
        $search_lower = strtolower($search_term);

        $this->scan_directory_for_documents($base_path, $base_path, $search_lower, $matches);

        // Sort by filename for consistent ordering
        usort($matches, function($a, $b) {
            return strnatcasecmp($a['filename'], $b['filename']);
        });

        return $matches;
    }

    /**
     * Recursively scan directory for matching documents
     *
     * @param string $dir_path Current directory
     * @param string $base_path Base directory for relative paths
     * @param string $search_lower Lowercase search term
     * @param array &$matches Reference to matches array
     */
    private function scan_directory_for_documents($dir_path, $base_path, $search_lower, &$matches) {
        $items = @scandir($dir_path);
        if ($items === false) {
            return;
        }

        // Get list of annex folder names to skip
        $annex_folders = array();
        foreach ($items as $item) {
            if ($item[0] === '.') continue;
            $full_path = $dir_path . '/' . $item;
            if (!is_dir($full_path)) {
                $annex_folders[] = strtolower($item . '_annexes');
            }
        }

        foreach ($items as $item) {
            if ($item[0] === '.') {
                continue;
            }

            $full_path = $dir_path . '/' . $item;

            if (is_dir($full_path)) {
                // Skip annex folders
                if (in_array(strtolower($item), $annex_folders)) {
                    continue;
                }
                // Recurse into subdirectories
                $this->scan_directory_for_documents($full_path, $base_path, $search_lower, $matches);
            } else {
                // Skip meta files and Commentary.md
                if (strpos($item, 'meta_') === 0 || strtolower($item) === 'commentary.md') {
                    continue;
                }

                // Check if filename matches search term
                $filename_no_ext = strtolower(pathinfo($item, PATHINFO_FILENAME));

                // Normalize: remove all punctuation and collapse spaces for comparison
                $filename_normalized = preg_replace('/[^a-z0-9]+/', ' ', $filename_no_ext);
                $filename_normalized = trim(preg_replace('/\s+/', ' ', $filename_normalized));

                $search_normalized = preg_replace('/[^a-z0-9]+/', ' ', $search_lower);
                $search_normalized = trim(preg_replace('/\s+/', ' ', $search_normalized));

                // Split into words and check if all search words appear in filename
                $search_words = explode(' ', $search_normalized);
                $search_words = array_filter($search_words);

                $all_words_match = true;
                foreach ($search_words as $word) {
                    if (strpos($filename_normalized, $word) === false) {
                        $all_words_match = false;
                        break;
                    }
                }

                if ($all_words_match) {
                    $relative_path = str_replace($base_path, '', $full_path);
                    $relative_path = ltrim($relative_path, '/');

                    $matches[] = array(
                        'id' => $relative_path,
                        'filename' => $item,
                        'path' => $full_path
                    );
                }
            }
        }
    }

    /**
     * Count annexes for a document
     *
     * @param string $dir_path Directory containing the document
     * @param string $filename Document filename
     * @return int Number of annexes
     */
    private function count_annexes($dir_path, $filename) {
        $annex_folder = $dir_path . '/' . $filename . '_annexes';

        if (!is_dir($annex_folder)) {
            return 0;
        }

        $items = @scandir($annex_folder);
        if ($items === false) {
            return 0;
        }

        $count = 0;
        foreach ($items as $item) {
            if ($item[0] === '.') continue;
            $full_path = $annex_folder . '/' . $item;
            if (is_file($full_path) && strpos($item, 'meta_') !== 0) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Find which WordPress page displays a given document path
     *
     * @param string $relative_dir Directory path relative to base
     * @return array|null Page info or null if not found
     */
    private function find_display_page($relative_dir) {
        global $wpdb;

        // Find all posts/pages containing [docdisplay shortcode
        $posts = $wpdb->get_results(
            "SELECT ID, post_content FROM {$wpdb->posts}
             WHERE post_status = 'publish'
             AND (post_type = 'post' OR post_type = 'page')
             AND post_content LIKE '%[docdisplay%'"
        );

        if (empty($posts)) {
            return null;
        }

        $best_match = null;
        $best_match_depth = -1;

        foreach ($posts as $post) {
            // Extract shortcode attributes
            $shortcodes = $this->parse_docdisplay_shortcodes($post->post_content);

            foreach ($shortcodes as $shortcode) {
                $path = isset($shortcode['path']) ? trim($shortcode['path'], '/') : '';
                $recursive = isset($shortcode['recursive']) &&
                             filter_var($shortcode['recursive'], FILTER_VALIDATE_BOOLEAN);

                // Check if this shortcode could display our document
                if ($this->path_matches($relative_dir, $path, $recursive)) {
                    // Calculate depth of match (more specific = better)
                    $depth = empty($path) ? 0 : substr_count($path, '/') + 1;

                    if ($depth > $best_match_depth) {
                        $best_match_depth = $depth;
                        $best_match = array(
                            'page_id' => $post->ID,
                            'shortcode_path' => $path,
                            'recursive' => $recursive
                        );
                    }
                }
            }
        }

        return $best_match;
    }

    /**
     * Parse docdisplay shortcodes from content
     *
     * @param string $content Post content
     * @return array Array of shortcode attribute arrays
     */
    private function parse_docdisplay_shortcodes($content) {
        $shortcodes = array();

        // Match [docdisplay ...] shortcodes
        if (preg_match_all('/\[docdisplay([^\]]*)\]/i', $content, $matches)) {
            foreach ($matches[1] as $attrs_string) {
                $attrs = shortcode_parse_atts($attrs_string);
                if (is_array($attrs)) {
                    $shortcodes[] = $attrs;
                }
            }
        }

        return $shortcodes;
    }

    /**
     * Check if a document path matches a shortcode path
     *
     * @param string $doc_path Document directory path
     * @param string $shortcode_path Shortcode path attribute
     * @param bool $recursive Whether shortcode is recursive
     * @return bool True if document would be displayed by shortcode
     */
    private function path_matches($doc_path, $shortcode_path, $recursive) {
        $doc_path = trim($doc_path, '/');
        $shortcode_path = trim($shortcode_path, '/');

        // Empty shortcode path matches everything if recursive
        if (empty($shortcode_path)) {
            return $recursive || empty($doc_path);
        }

        // Exact match
        if ($doc_path === $shortcode_path) {
            return true;
        }

        // Check if doc is within shortcode path (for recursive)
        if ($recursive) {
            return strpos($doc_path, $shortcode_path . '/') === 0 ||
                   $doc_path === $shortcode_path;
        }

        return false;
    }

    /**
     * Get the subpath needed for URL parameter
     *
     * @param string $doc_dir Document directory
     * @param string $shortcode_path Shortcode base path
     * @return string Subpath for URL parameter
     */
    private function get_subpath($doc_dir, $shortcode_path) {
        $doc_dir = trim($doc_dir, '/');
        $shortcode_path = trim($shortcode_path, '/');

        if ($doc_dir === $shortcode_path || empty($doc_dir)) {
            return '';
        }

        if (empty($shortcode_path)) {
            return $doc_dir;
        }

        // Remove shortcode path prefix from doc dir
        $prefix = $shortcode_path . '/';
        if (strpos($doc_dir, $prefix) === 0) {
            return substr($doc_dir, strlen($prefix));
        }

        return '';
    }

    /**
     * Build a breadcrumb string from path
     *
     * @param string $relative_dir Relative directory path
     * @return string Breadcrumb string
     */
    private function build_breadcrumb($relative_dir) {
        if (empty($relative_dir)) {
            return '';
        }

        $parts = explode('/', $relative_dir);
        $parts = array_filter($parts);

        // Clean up each part (remove dashes/underscores, capitalize)
        $parts = array_map(function($part) {
            return ucfirst(str_replace(array('-', '_'), ' ', $part));
        }, $parts);

        return implode(' > ', $parts);
    }

    /**
     * Generate a sanitized anchor ID from a filename
     * Mirrors the method in main DocDisplay class
     *
     * @param string $filename Document filename
     * @return string Anchor ID
     */
    private function get_document_anchor_id($filename) {
        $name = pathinfo($filename, PATHINFO_FILENAME);
        $name = preg_replace('/[\.\s]+/', '-', $name);
        $name = preg_replace('/[^a-zA-Z0-9\-]/', '', $name);
        $name = preg_replace('/-+/', '-', $name);
        $name = trim($name, '-');
        return 'doc-' . $name;
    }
}
