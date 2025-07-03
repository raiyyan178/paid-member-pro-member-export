<?php
/*
Plugin Name: Membership User Export
Description: Adds a Membership User Export tab in the admin panel to view and export member names, membership details, and status.
Version: 1.25
Author: Apex Web Studios
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Class to manage table data and rendering
class Membership_User_Export {
    private $items = [];
    private $pagination = [];

    // Assign Membership IDs to all users
    private function assign_membership_ids() {
        global $wpdb;
        $pmpro_active = function_exists('pmpro_getMembershipLevelForUser') && function_exists('pmpro_getAllLevels');
        if (!$pmpro_active) {
            return;
        }

        // Get all levels
        $levels = pmpro_getAllLevels(false, true);

        foreach ($levels as $level) {
            $level_id = intval($level->id);

            // Query active memberships for this level, sorted by startdate
            $query = "
                SELECT user_id, startdate
                FROM {$wpdb->pmpro_memberships_users}
                WHERE membership_id = %d AND status = 'active'
                ORDER BY startdate ASC
            ";
            $memberships = $wpdb->get_results($wpdb->prepare($query, $level_id));

            if (empty($memberships)) {
                error_log("No active members found for level ID: {$level_id}");
                continue;
            }

            // Assign counters per year for this level
            $year_counters = [];
            foreach ($memberships as $membership) {
                $purchase_year = gmdate('Y', strtotime($membership->startdate));
                if (!isset($year_counters[$purchase_year])) {
                    $year_counters[$purchase_year] = 0;
                }
                $year_counters[$purchase_year]++;
                $membership_id = sprintf('GOGN-%s-%03d', $purchase_year, $year_counters[$purchase_year]);

                // Store or update Membership ID in user meta
                $existing_id = get_user_meta($membership->user_id, 'mue_membership_id', true);
                if ($existing_id !== $membership_id) {
                    update_user_meta($membership->user_id, 'mue_membership_id', $membership_id);
                    error_log("Membership ID Updated: User ID {$membership->user_id}, Level Ascending, Level ID {$level_id}, Start Date {$membership->startdate}, Membership ID {$membership_id}");
                }
            }
        }
    }

    // Prepare table items
    public function prepare_items() {
        global $wpdb;
        $per_page = 20;
        $current_page = isset($_GET['paged']) ? max(1, intval($_GET['paged'])) : 1;
        $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
        $membership_filter = isset($_GET['membership_level']) ? sanitize_text_field($_GET['membership_level']) : 'all';
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'member_name';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'asc';

        // Assign Membership IDs
        $this->assign_membership_ids();

        // Query users
        $args = [
            'number' => $per_page,
            'offset' => ($current_page - 1) * $per_page,
            'search' => $search ? '*' . $search . '*' : '',
            'orderby' => 'display_name',
            'order' => $order,
        ];

        // Apply membership filter if PMPro is active
        $pmpro_active = function_exists('pmpro_getMembershipLevelForUser') && function_exists('pmpro_getAllLevels');
        if ($pmpro_active && $membership_filter && $membership_filter !== 'all') {
            if ($membership_filter === 'active_memberships') {
                $args['meta_query'] = [
                    [
                        'key' => 'pmpro_membership_level',
                        'compare' => 'EXISTS',
                    ],
                ];
            } else {
                $args['meta_query'] = [
                    [
                        'key' => 'pmpro_membership_level',
                        'value' => $membership_filter,
                        'compare' => '=',
                    ],
                ];
            }
        }

        $users = get_users($args);
        $total_items = count_users()['total_users'];

        // Adjust total items for membership filter
        if ($pmpro_active && $membership_filter && $membership_filter !== 'all') {
            $filtered_users = array_filter(get_users(['number' => -1]), function($user) use ($membership_filter, $pmpro_active) {
                if (!$pmpro_active) return false;
                $membership = pmpro_getMembershipLevelForUser($user->ID);
                if ($membership_filter === 'active_memberships') {
                    return $membership !== false;
                }
                return $membership && $membership->id == $membership_filter;
            });
            $total_items = count($filtered_users);
            $users = array_slice($filtered_users, ($current_page - 1) * $per_page, $per_page);
        }

        $data = [];

        foreach ($users as $user) {
            $membership_name = 'None';
            $membership_id = get_user_meta($user->ID, 'mue_membership_id', true) ?: 'N/A';
            $membership_status = 'Inactive';

            if ($pmpro_active) {
                $membership = pmpro_getMembershipLevelForUser($user->ID);
                if ($membership) {
                    $membership_name = $membership->name;
                    $membership_status = $membership->enddate === null || $membership->enddate > current_time('mysql') ? 'Active' : 'Inactive';
                }
            }

            $data[] = [
                'user_id'          => $user->ID,
                'member_name'      => $user->display_name ?: 'Unnamed User (ID: ' . $user->ID . ')',
                'membership_name'  => $membership_name,
                'membership_id'    => $membership_id,
                'membership_status' => $membership_status,
            ];
        }

        // Fallback: Hardcoded test row
        if (empty($data)) {
            $data[] = [
                'user_id'          => 0,
                'member_name'      => 'Test User (No Users Found)',
                'membership_name'  => 'None',
                'membership_id'    => 'N/A',
                'membership_status' => 'Inactive',
            ];
            $total_items = 1;
        }

        // Sorting
        usort($data, function($a, $b) use ($orderby, $order) {
            if ($orderby === 'member_name') {
                return $order === 'asc' ? strcmp($a['member_name'], $b['member_name']) : strcmp($b['member_name'], $a['member_name']);
            } elseif ($orderby === 'membership_name') {
                return $order === 'asc' ? strcmp($a['membership_name'], $b['membership_name']) : strcmp($b['membership_name'], $a['membership_name']);
            }
            return 0;
        });

        $this->items = $data;
        $this->pagination = [
            'total_items' => $total_items,
            'per_page'    => $per_page,
            'current_page' => $current_page,
            'total_pages' => ceil($total_items / $per_page),
        ];
    }

    // Get membership levels for filter
    public function get_membership_levels() {
        if (!function_exists('pmpro_getAllLevels')) {
            return [];
        }
        $levels = pmpro_getAllLevels(false, true);
        $options = [
            ['id' => 'all', 'name' => __('All Levels', 'membership-user-export')],
            ['id' => 'active_memberships', 'name' => __('All Memberships', 'membership-user-export')],
        ];
        foreach ($levels as $level) {
            $options[] = ['id' => $level->id, 'name' => $level->name];
        }
        return $options;
    }

    // Render table
    public function display() {
        $this->prepare_items();
        $items = $this->items;
        $pagination = $this->pagination;
        $orderby = isset($_GET['orderby']) ? sanitize_text_field($_GET['orderby']) : 'member_name';
        $order = isset($_GET['order']) ? sanitize_text_field($_GET['order']) : 'asc';
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>
                        <a href="<?php echo esc_url(add_query_arg(['orderby' => 'member_name', 'order' => $order === 'asc' && $orderby === 'member_name' ? 'desc' : 'asc'])); ?>">
                            <?php _e('Member Name', 'membership-user-export'); ?>
                        </a>
                    </th>
                    <th>
                        <a href="<?php echo esc_url(add_query_arg(['orderby' => 'membership_name', 'order' => $order === 'asc' && $orderby === 'membership_name' ? 'desc' : 'asc'])); ?>">
                            <?php _e('Current Membership', 'membership-user-export'); ?>
                        </a>
                    </th>
                    <th><?php _e('Membership ID', 'membership-user-export'); ?></th>
                    <th><?php _e('Membership Status', 'membership-user-export'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($items)) : ?>
                    <tr><td colspan="4"><?php _e('No items found.', 'membership-user-export'); ?></td></tr>
                <?php else : ?>
                    <?php foreach ($items as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item['member_name']); ?></td>
                            <td><?php echo esc_html($item['membership_name']); ?></td>
                            <td><?php echo esc_html($item['membership_id']); ?></td>
                            <td><?php echo esc_html($item['membership_status']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <div class="tablenav bottom">
            <div class="tablenav-pages">
                <?php
                echo paginate_links([
                    'base' => add_query_arg('paged', '%#%'),
                    'format' => '',
                    'prev_text' => __('«'),
                    'next_text' => __('»'),
                    'total' => $pagination['total_pages'],
                    'current' => $pagination['current_page'],
                ]);
                ?>
                <span class="displaying-num"><?php printf(_n('%s item', '%s items', $pagination['total_items'], 'membership-user-export'), number_format_i18n($pagination['total_items'])); ?></span>
            </div>
        </div>
        <?php
    }

    // Export data to CSV
    public function export_to_csv() {
        $this->prepare_items();
        $items = $this->items;

        if (empty($items)) {
            wp_die(__('No data available to export.', 'membership-user-export'));
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=membership-users-' . date('Y-m-d-H-i-s') . '.csv');

        $output = fopen('php://output', 'w');
        fputcsv($output, [
            __('Member Name', 'membership-user-export'),
            __('Current Membership', 'membership-user-export'),
            __('Membership ID', 'membership-user-export'),
            __('Membership Status', 'membership-user-export'),
        ]);

        foreach ($items as $item) {
            fputcsv($output, [
                wp_strip_all_tags($item['member_name']),
                wp_strip_all_tags($item['membership_name']),
                wp_strip_all_tags($item['membership_id']),
                wp_strip_all_tags($item['membership_status']),
            ]);
        }

        fclose($output);
        exit;
    }
}

// Add admin menu
add_action('admin_menu', 'mue_add_admin_menu');
function mue_add_admin_menu() {
    add_menu_page(
        __('Membership User Export', 'membership-user-export'),
        __('Membership User Export', 'membership-user-export'),
        'manage_options',
        'membership-user-export',
        'mue_render_page',
        'dashicons-download',
        80
    );
}

// Render admin page
function mue_render_page() {
    $table = new Membership_User_Export();
    $table->prepare_items();
    $membership_levels = $table->get_membership_levels();
    $current_membership = isset($_GET['membership_level']) ? sanitize_text_field($_GET['membership_level']) : 'all';
    ?>
    <div class="wrap">
        <h1><?php _e('Membership User Export', 'membership-user-export'); ?></h1>
        <form method="get" class="mue-filter-form">
            <input type="hidden" name="page" value="membership-user-export" />
            <?php if (!empty($membership_levels)) : ?>
                <select name="membership_level">
                    <?php foreach ($membership_levels as $level) : ?>
                        <option value="<?php echo esc_attr($level['id']); ?>" <?php selected($current_membership, $level['id']); ?>>
                            <?php echo esc_html($level['name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>
            <?php
            // Search box
            $search = isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '';
            ?>
            <p class="search-box">
                <label class="screen-reader-text" for="user-search-input"><?php _e('Search Users', 'membership-user-export'); ?>:</label>
                <input type="search" id="user-search-input" name="s" value="<?php echo esc_attr($search); ?>" />
                <input type="submit" id="search-submit" class="button" value="<?php _e('Search Users', 'membership-user-export'); ?>" />
            </p>
            <input type="submit" class="button" value="<?php _e('Filter', 'membership-user-export'); ?>" />
        </form>
        <form method="post">
            <?php
            wp_nonce_field('mue_export_nonce', 'mue_nonce');
            submit_button(__('Export Table to CSV', 'membership-user-export'), 'secondary', 'mue_export', false);
            ?>
        </form>
        <?php $table->display(); ?>
    </div>
    <?php
}

// Handle CSV export
add_action('admin_init', 'mue_handle_export');
function mue_handle_export() {
    if (isset($_POST['mue_export']) && check_admin_referer('mue_export_nonce', 'mue_nonce')) {
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have permission to export data.', 'membership-user-export'));
        }

        $table = new Membership_User_Export();
        $table->export_to_csv();
    }
}

// Enqueue styles (optional)
add_action('admin_enqueue_scripts', 'mue_enqueue_styles');
function mue_enqueue_styles($hook) {
    if ($hook !== 'toplevel_page_membership-user-export') {
        return;
    }
    wp_enqueue_style('mue-styles', plugin_dir_url(__FILE__) . 'mue-styles.css', [], '1.25');
}

// Register text domain for translations
add_action('init', 'mue_load_textdomain');
function mue_load_textdomain() {
    load_plugin_textdomain('membership-user-export', false, dirname(plugin_basename(__FILE__)) . '/languages');
}
?>