<?php
/**
 * Plugin Name: fishdan Jsonmaker
 * Plugin URI: https://www.fishdan.com/jsonmaker
 * Description: Manage a hierarchical collection of titled links that can be edited from a shortcode and fetched as JSON.
 * Version: 0.2.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * License: MIT
 * License URI: https://opensource.org/licenses/MIT
 * Domain Path: /languages
 * Text Domain: fishdan-jsonmaker
 * Author: Daniel Fishman
 * Author URI: https://www.fishdan.com
 */

if (! defined('ABSPATH')) {
	exit;
}

if (! defined('JSONMAKER_VERSION')) {
	define('JSONMAKER_VERSION', '0.2.0');
}

if (! function_exists('jm_fs')) {
	/**
	 * Provide a helper for accessing the Freemius SDK instance.
	 */
	function jm_fs() {
		global $jm_fs;

		if (isset($jm_fs)) {
			return $jm_fs;
		}

		$sdk_path = __DIR__ . '/vendor/freemius/start.php';

		if (! file_exists($sdk_path)) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('fishdan Jsonmaker: Freemius SDK loader not found.');
			}
			$jm_fs = false;

			return $jm_fs;
		}

		require_once $sdk_path;

		if (! function_exists('fs_dynamic_init')) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('fishdan Jsonmaker: Freemius SDK failed to initialize.');
			}
			$jm_fs = false;

			return $jm_fs;
		}

		$jm_fs = fs_dynamic_init([
			'id' => '21365',
			'slug' => 'fishdan-jsonmaker',
			'type' => 'plugin',
			'public_key' => 'pk_404c69d00480e719a56ebde3bbe2f',
			'is_premium' => false,
			'has_addons' => false,
			'has_paid_plans' => false,
			'menu' => [
				'first-path' => 'plugins.php',
				'account' => false,
				'support' => false,
			],
		]);

		return $jm_fs;
	}

	function jsonmaker_freemius_icon_path() {
		return plugin_dir_path(__FILE__) . 'vendor/freemius/assets/img/plugin-icon.png';
	}

	// Initialize the Freemius SDK.
	$jm_fs_instance = jm_fs();

	if ($jm_fs_instance) {
		$jm_fs_instance->add_filter('plugin_icon', 'jsonmaker_freemius_icon_path');
		// Signal that the SDK finished loading.
		do_action('jm_fs_loaded');
	}
}

final class Jsonmaker_Plugin {
	private const OPTION_NAME = 'jsonmaker_tree';
	private const CAPABILITY = 'jsonmaker_manage';

	private static ?Jsonmaker_Plugin $instance = null;
	private bool $printed_assets = false;

	public static function instance(): Jsonmaker_Plugin {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {
		add_action('init', [$this, 'register_rewrite']);
		add_action('init', [$this, 'maybe_handle_submission']);
		add_filter('query_vars', [$this, 'register_query_var']);
		add_action('template_redirect', [$this, 'maybe_output_json']);
		add_shortcode('jsonmaker', [$this, 'render_shortcode']);
		add_action('send_headers', [$this, 'maybe_add_cors_headers']);
		add_filter('redirect_canonical', [$this, 'maybe_disable_canonical_redirect'], 10, 2);
		add_action('load-admin_page_fishdan-jsonmaker', [$this, 'ensure_admin_connect_title'], 5);
		add_action('load-admin_page_fishdan-jsonmaker-network', [$this, 'ensure_admin_connect_title'], 5);

		$fs = jm_fs();
		if (is_object($fs) && method_exists($fs, 'add_filter')) {
			$fs->add_filter('plugin_icon', [$this, 'filter_freemius_plugin_icon']);
		}
	}

	public static function activate(): void {
		self::ensure_capability();
		self::ensure_initial_tree();
		self::instance()->register_rewrite();
		flush_rewrite_rules();
	}

	public static function deactivate(): void {
		flush_rewrite_rules();
	}

	public static function uninstall(): void {
		delete_option(self::OPTION_NAME);

		if (! function_exists('wp_roles') || ! class_exists('WP_Roles')) {
			return;
		}

		$roles = wp_roles();
		if (! $roles instanceof WP_Roles) {
			return;
		}

		foreach ($roles->role_objects as $role) {
			if ($role->has_cap(self::CAPABILITY)) {
				$role->remove_cap(self::CAPABILITY);
			}
		}
	}

	private static function ensure_initial_tree(): void {
		if (get_option(self::OPTION_NAME) !== false) {
			return;
		}

		$initial = [
			'title' => 'Fishdan',
			'slug' => 'fishdan',
			'value' => 'https://www.fishdan.com',
			'children' => [],
		];

		add_option(self::OPTION_NAME, $initial);
	}

	private static function ensure_capability(): void {
		$role = get_role('administrator');

		if ($role === null) {
			return;
		}

		if (! $role->has_cap(self::CAPABILITY)) {
			$role->add_cap(self::CAPABILITY);
		}
	}

	public function register_rewrite(): void {
		add_rewrite_rule('^json/([^/]+)\.json$', 'index.php?jsonmaker_node=$matches[1]', 'top');
	}

	public function register_query_var(array $vars): array {
		$vars[] = 'jsonmaker_node';

		return $vars;
	}

	public function maybe_handle_submission(): void {
		$action_raw = filter_input(INPUT_POST, 'jsonmaker_action', FILTER_UNSAFE_RAW);
		$action = is_string($action_raw) ? sanitize_key(wp_unslash($action_raw)) : '';

		if ($action === '') {
			return;
		}

		if (! current_user_can(self::CAPABILITY)) {
			return;
		}

		if ($action === 'add_node') {
			$this->handle_add_submission();
		} elseif ($action === 'delete_node') {
			$this->handle_delete_submission();
		} elseif ($action === 'edit_node') {
			$this->handle_edit_submission();
		} elseif ($action === 'import_json') {
			$this->handle_import_submission();
		}
	}

	private function handle_add_submission(): void {
		$parent_raw = filter_input(INPUT_POST, 'jsonmaker_parent', FILTER_UNSAFE_RAW);
		$title_raw = filter_input(INPUT_POST, 'jsonmaker_title', FILTER_UNSAFE_RAW);

		if (! is_string($parent_raw) || ! is_string($title_raw)) {
			return;
		}

		check_admin_referer('jsonmaker_add_node', 'jsonmaker_nonce');

		$parent_slug = sanitize_key(wp_unslash($parent_raw));
		$title = sanitize_text_field(wp_unslash($title_raw));
		$title = trim($title);
		$value = '';

		$value_raw = filter_input(INPUT_POST, 'jsonmaker_value', FILTER_UNSAFE_RAW);
		if (is_string($value_raw)) {
			$value = sanitize_text_field(wp_unslash($value_raw));
		}

		if ($title === '' || $parent_slug === '') {
			$this->redirect_with_message('missing_fields');
		}

		$tree = $this->get_tree();

		if ($this->title_exists($tree, $title)) {
			$this->redirect_with_message('title_exists');
		}

		$slug = $this->create_unique_slug($title, $tree);
		$new_node = [
			'title' => $title,
			'slug' => $slug,
			'children' => [],
		];

		if ($value !== '') {
			$new_node['value'] = $value;
		}

		$updated = $this->add_child_node($tree, $parent_slug, $new_node);

		if ($updated) {
			update_option(self::OPTION_NAME, $tree);
			$this->redirect_with_message('node_added', true);
		}

		$this->redirect_with_message('parent_not_found');
	}

	private function handle_delete_submission(): void {
		$target_raw = filter_input(INPUT_POST, 'jsonmaker_target', FILTER_UNSAFE_RAW);

		if (! is_string($target_raw)) {
			return;
		}

		check_admin_referer('jsonmaker_delete_node', 'jsonmaker_delete_nonce');

		$target_slug = sanitize_key(wp_unslash($target_raw));

		if ($target_slug === '') {
			$this->redirect_with_message('missing_fields');
		}

		$tree = $this->get_tree();

		if (($tree['slug'] ?? '') === $target_slug) {
			$this->redirect_with_message('cannot_delete_root');
		}

		$target_node = $this->find_node($tree, $target_slug);

		if ($target_node === null) {
			$this->redirect_with_message('node_not_found');
		}

		if (! empty($target_node['children'])) {
			$this->redirect_with_message('has_children');
		}

		$deleted = $this->remove_node($tree, $target_slug);

		if ($deleted) {
			update_option(self::OPTION_NAME, $tree);
			$this->redirect_with_message('node_deleted', true);
		}

		$this->redirect_with_message('node_not_found');
	}

	private function handle_edit_submission(): void {
		$target_raw = filter_input(INPUT_POST, 'jsonmaker_target', FILTER_UNSAFE_RAW);
		$title_raw = filter_input(INPUT_POST, 'jsonmaker_title', FILTER_UNSAFE_RAW);

		if (! is_string($target_raw) || ! is_string($title_raw)) {
			return;
		}

		check_admin_referer('jsonmaker_edit_node', 'jsonmaker_edit_nonce');

		$target_slug = sanitize_key(wp_unslash($target_raw));
		$new_title = sanitize_text_field(wp_unslash($title_raw));
		$new_title = trim($new_title);

		if ($target_slug === '' || $new_title === '') {
			$this->redirect_with_message('missing_fields');
		}

		$tree = $this->get_tree();

		if ($this->title_exists($tree, $new_title, $target_slug)) {
			$this->redirect_with_message('title_exists');
		}

		$new_slug = $this->create_unique_slug($new_title, $tree, $target_slug);

		$updated = $this->update_node_title($tree, $target_slug, $new_title, $new_slug);

		if ($updated) {
			update_option(self::OPTION_NAME, $tree);
			$this->redirect_with_message('title_updated', true);
		}

		$this->redirect_with_message('node_not_found');
	}

	private function handle_import_submission(): void {
		$json_raw = filter_input(INPUT_POST, 'jsonmaker_payload', FILTER_UNSAFE_RAW);

		if (! is_string($json_raw)) {
			return;
		}

		check_admin_referer('jsonmaker_import', 'jsonmaker_import_nonce');

		$mode_raw = filter_input(INPUT_POST, 'jsonmaker_import_mode', FILTER_UNSAFE_RAW);
		$mode = is_string($mode_raw) ? sanitize_key(wp_unslash($mode_raw)) : 'replace';
		if ($mode !== 'append') {
			$mode = 'replace';
		}

		$target_slug = '';
		if ($mode === 'append') {
			$target_raw = filter_input(INPUT_POST, 'jsonmaker_import_target', FILTER_UNSAFE_RAW);

			if (! is_string($target_raw)) {
				$this->redirect_with_message('import_target_missing');
			}

			$target_slug = sanitize_key(wp_unslash($target_raw));

			if ($target_slug === '') {
				$this->redirect_with_message('import_target_missing');
			}
		}

		$payload = trim((string) wp_unslash($json_raw));

		if ($payload === '') {
			$this->redirect_with_message('missing_fields');
		}

		$decoded = json_decode($payload, true);

		if (! is_array($decoded)) {
			$this->redirect_with_message('import_invalid_json');
		}

		$error_code = '';
		if ($mode === 'replace') {
			$tree = $this->normalize_import_tree($decoded, $error_code);

			if ($tree === null || $error_code !== '') {
				$this->redirect_with_message($error_code !== '' ? $error_code : 'import_invalid_structure');
			}

			update_option(self::OPTION_NAME, $tree);
			$this->redirect_with_message('import_success', true);
		}

		$current_tree = $this->get_tree();
		$node = $this->normalize_import_tree($decoded, $error_code, $current_tree);

		if ($node === null || $error_code !== '') {
			$this->redirect_with_message($error_code !== '' ? $error_code : 'import_invalid_structure');
		}

		if (! $this->add_child_node($current_tree, $target_slug, $node)) {
			$this->redirect_with_message('import_target_not_found');
		}

		update_option(self::OPTION_NAME, $current_tree);
		$this->redirect_with_message('import_success', true);
	}

	private function normalize_import_tree(array $root, string &$error_code, ?array $existing_tree = null): ?array {
		$used_titles = [];
		$used_slugs = [];

		if ($existing_tree !== null) {
			$this->collect_import_keys($existing_tree, $used_titles, $used_slugs);
		}

		return $this->normalize_import_node($root, $used_titles, $used_slugs, $error_code);
	}

	private function collect_import_keys(array $node, array &$titles, array &$slugs): void {
		$title = isset($node['title']) ? (string) $node['title'] : '';
		$title_key = $this->normalize_title_key($title);

		if ($title_key !== '') {
			$titles[$title_key] = true;
		}

		$slug = isset($node['slug']) ? (string) $node['slug'] : '';
		if ($slug !== '') {
			$slugs[$slug] = true;
		}

		if (empty($node['children']) || ! is_array($node['children'])) {
			return;
		}

		foreach ($node['children'] as $child) {
			if (! is_array($child)) {
				continue;
			}

			$this->collect_import_keys($child, $titles, $slugs);
		}
	}

	private function normalize_import_node(array $input, array &$used_titles, array &$used_slugs, string &$error_code): ?array {
		$allowed_keys = ['title', 'value', 'children'];

		foreach ($input as $key => $_value) {
			if (! in_array($key, $allowed_keys, true)) {
				$error_code = 'import_invalid_structure';

				return null;
			}
		}

		if (! array_key_exists('title', $input) || ! is_string($input['title'])) {
			$error_code = 'import_invalid_structure';

			return null;
		}

		$title = sanitize_text_field($input['title']);
		$title = trim($title);

		if ($title === '') {
			$error_code = 'import_invalid_structure';

			return null;
		}

		$title_key = $this->normalize_title_key($title);

		if ($title_key !== '' && isset($used_titles[$title_key])) {
			$error_code = 'import_duplicate_title';

			return null;
		}

		if ($title_key !== '') {
			$used_titles[$title_key] = true;
		}

		$slug_base = sanitize_title($title);

		if ($slug_base === '') {
			$slug_base = 'node';
		}

		$slug = $slug_base;
		$index = 2;

		while ($slug === '' || isset($used_slugs[$slug])) {
			$slug = $slug_base . '-' . $index;
			$index++;
		}

		$used_slugs[$slug] = true;

		$node = [
			'title' => $title,
			'slug' => $slug,
			'children' => [],
		];

		if (array_key_exists('value', $input)) {
			if ($input['value'] !== null && ! is_string($input['value'])) {
				$error_code = 'import_invalid_structure';

				return null;
			}

			if (is_string($input['value'])) {
				$value = sanitize_text_field($input['value']);
				$value = trim($value);

				if ($value !== '') {
					$node['value'] = $value;
				}
			}
		}

		if (array_key_exists('children', $input)) {
			if (! is_array($input['children'])) {
				$error_code = 'import_invalid_structure';

				return null;
			}

			foreach ($input['children'] as $child_input) {
				if (! is_array($child_input)) {
					$error_code = 'import_invalid_structure';

					return null;
				}

				$child = $this->normalize_import_node($child_input, $used_titles, $used_slugs, $error_code);

				if ($child === null) {
					return null;
				}

				$node['children'][] = $child;
			}
		}

		return $node;
	}

	private function normalize_title_key(string $title): string {
		if ($title === '') {
			return '';
		}

		if (function_exists('mb_strtolower')) {
			return mb_strtolower($title, 'UTF-8');
		}

		return strtolower($title);
	}

	private function normalize_request_slug(string $value): string {
		$decoded = rawurldecode($value);
		$decoded = trim($decoded);

		if ($decoded === '') {
			return '';
		}

		$normalized = sanitize_title($decoded);

		if ($normalized !== '') {
			return $normalized;
		}

		$fallback = sanitize_key($decoded);

		return is_string($fallback) ? $fallback : '';
	}

	public function maybe_output_json(): void {
		$requested = get_query_var('jsonmaker_node');

		if ($requested === '') {
			return;
		}

		$this->send_cors_headers();

		$method_raw = filter_input(INPUT_SERVER, 'REQUEST_METHOD', FILTER_UNSAFE_RAW);
		$method = is_string($method_raw) ? strtoupper($method_raw) : 'GET';

		if ($method === 'OPTIONS') {
			status_header(200);
			exit;
		}

		$normalized_slug = $this->normalize_request_slug($requested);
		if ($normalized_slug === '') {
			status_header(404);
			wp_send_json(['error' => 'Node not found'], 404);
		}

		$node = $this->find_node($this->get_tree(), $normalized_slug);

		if ($node === null) {
			status_header(404);
			wp_send_json(['error' => 'Node not found'], 404);
		}

		wp_send_json($this->prepare_public_node($node));
	}

	public function render_shortcode(): string {
		$tree = $this->get_tree();
		$this->maybe_print_assets();

		$can_manage = current_user_can(self::CAPABILITY);
		$current_url = $can_manage ? $this->get_current_url() : '';

		$notice_code = '';
		$notice_status = 'error';

		$notice_raw = filter_input(INPUT_GET, 'jsonmaker_msg', FILTER_UNSAFE_RAW);
		if (is_string($notice_raw)) {
			$notice_code = sanitize_key(wp_unslash($notice_raw));
		}

		$status_raw = filter_input(INPUT_GET, 'jsonmaker_status', FILTER_UNSAFE_RAW);
		if (is_string($status_raw)) {
			$notice_status_candidate = sanitize_key(wp_unslash($status_raw));
			if ($notice_status_candidate === 'success') {
				$notice_status = 'success';
			}
		}

		ob_start();
		if ($notice_code !== '' && $can_manage) {
			$message_text = $this->get_notice_text($notice_code);

			if ($message_text !== '') {
				$class = $notice_status === 'success' ? 'jsonmaker-notice--success' : 'jsonmaker-notice--error';
				echo '<div class="jsonmaker-notice ' . esc_attr($class) . '">' . esc_html($message_text) . '</div>';
			}
		}
		$sections = [];
		if ($can_manage) {
			ob_start();
			$this->render_import_form($current_url, $tree);
			$import_content = (string) ob_get_clean();
			$import_section = $this->render_collapsible_section(
				'import',
				__('Bulk Import JSON', 'fishdan-jsonmaker'),
				$import_content,
				false
			);
			if ($import_section !== '') {
				$sections[] = $import_section;
			}

			ob_start();
			echo '<pre class="jsonmaker-json">';
			echo esc_html(wp_json_encode($this->prepare_public_node($tree), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			echo '</pre>';
			$json_content = (string) ob_get_clean();
			$json_section = $this->render_collapsible_section(
				'json',
				__('Current JSON', 'fishdan-jsonmaker'),
				$json_content,
				false
			);
			if ($json_section !== '') {
				$sections[] = $json_section;
			}
		}

		ob_start();
		echo '<div class="jsonmaker-tree">';
		$this->render_node($tree);
		echo '</div>';
		$tree_content = (string) ob_get_clean();
		$tree_section = $this->render_collapsible_section(
			'tree',
			$can_manage ? __('Editing Tree', 'fishdan-jsonmaker') : __('Link Tree', 'fishdan-jsonmaker'),
			$tree_content,
			true
		);
		if ($tree_section !== '') {
			$sections[] = $tree_section;
		}

		foreach ($sections as $section_html) {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_collapsible_section() returns escaped markup.
			echo $section_html;
		}

		return (string) ob_get_clean();
	}

	private function render_import_form(string $redirect, array $tree): void {
		if ($redirect === '') {
			return;
		}

		$textarea_id = 'jsonmaker-import-payload';
		$target_id = 'jsonmaker-import-target';
		$options = [];
		$this->build_import_target_options($tree, $options);

		echo '<div class="jsonmaker-import">';
		echo '<form method="post" action="' . esc_url($redirect) . '">';
		wp_nonce_field('jsonmaker_import', 'jsonmaker_import_nonce');
		echo '<input type="hidden" name="jsonmaker_action" value="import_json" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($redirect) . '" />';
		$schema_url = plugins_url('jsonmaker.schema.json', __FILE__);
		$schema_link = '<a href="' . esc_url($schema_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('fishdan Jsonmaker schema', 'fishdan-jsonmaker') . '</a>';
		// translators: %s is a link to the fishdan Jsonmaker schema documentation.
		$description_text = sprintf(
			__('Paste JSON that matches the %s to replace the tree or append a branch.', 'fishdan-jsonmaker'),
			$schema_link
		);

		echo '<p class="jsonmaker-import__intro">' . wp_kses(
			$description_text,
			[
				'a' => [
					'href' => [],
					'target' => [],
					'rel' => [],
				],
			]
		) . '</p>';
		echo '<div class="jsonmaker-import__mode">';
		echo '<span class="jsonmaker-import__label">' . esc_html__('Mode', 'fishdan-jsonmaker') . '</span>';
		echo '<label><input type="radio" name="jsonmaker_import_mode" value="append" checked /> ' . esc_html__('Append under an existing node', 'fishdan-jsonmaker') . '</label>';
		echo '<label><input type="radio" name="jsonmaker_import_mode" value="replace" /> ' . esc_html__('Replace entire tree', 'fishdan-jsonmaker') . '</label>';
		echo '</div>';
		if (! empty($options)) {
			echo '<div class="jsonmaker-import__target">';
			echo '<label for="' . esc_attr($target_id) . '" class="jsonmaker-import__label">' . esc_html__('Append target', 'fishdan-jsonmaker') . '</label>';
			echo '<select id="' . esc_attr($target_id) . '" name="jsonmaker_import_target" data-jsonmaker-import-target required>';
			echo '<option value="" disabled selected>' . esc_html__('Select a node...', 'fishdan-jsonmaker') . '</option>';
			foreach ($options as $option) {
				echo '<option value="' . esc_attr($option['slug']) . '">' . esc_html($option['label']) . '</option>';
			}
			echo '</select>';
			echo '<p class="jsonmaker-import__hint">' . esc_html__('Used when Mode is set to Append.', 'fishdan-jsonmaker') . '</p>';
			echo '</div>';
		}
		echo '<label for="' . esc_attr($textarea_id) . '" class="jsonmaker-import__label">' . esc_html__('JSON payload', 'fishdan-jsonmaker') . '</label>';
		echo '<textarea id="' . esc_attr($textarea_id) . '" name="jsonmaker_payload" rows="10" required></textarea>';
		echo '<div class="jsonmaker-import__actions">';
		echo '<button type="submit">' . esc_html__('Import JSON', 'fishdan-jsonmaker') . '</button>';
		echo '</div>';
		echo '</form>';
		echo '</div>';
	}

	private function render_collapsible_section(string $id, string $title, string $content, bool $default_open = true): string {
		$content = trim($content);

		if ($content === '') {
			return '';
		}

		$section_key = sanitize_key($id);
		if ($section_key === '') {
			$section_key = 'section';
		}

		$toggle_id = 'jsonmaker-toggle-' . $section_key;
		$content_id = 'jsonmaker-section-' . $section_key;
		$default_attr = $default_open ? 'open' : 'closed';
		$aria_expanded = $default_open ? 'true' : 'false';

		$output = '<div class="jsonmaker-section" data-jsonmaker-section="' . esc_attr($section_key) . '" data-jsonmaker-section-default="' . esc_attr($default_attr) . '">';
		$output .= '<div class="jsonmaker-section__header">';
		$indicator_symbol = $default_open ? '−' : '+';
		$output .= '<button type="button" id="' . esc_attr($toggle_id) . '" class="jsonmaker-section__toggle" data-jsonmaker-section-toggle="' . esc_attr($section_key) . '" aria-controls="' . esc_attr($content_id) . '" aria-expanded="' . esc_attr($aria_expanded) . '">';
		$output .= '<span class="jsonmaker-section__indicator" data-jsonmaker-section-indicator aria-hidden="true">' . esc_html($indicator_symbol) . '</span>';
		$output .= '<span class="jsonmaker-section__title"><strong>' . esc_html($title) . '</strong></span>';
		$output .= '</button>';
		$output .= '</div>';
		$output .= '<div id="' . esc_attr($content_id) . '" class="jsonmaker-section__content">';
		$output .= $content;
		$output .= '</div>';
		$output .= '</div>';

		return $output;
	}

	private function build_import_target_options(array $node, array &$options, int $depth = 0): void {
		$title = isset($node['title']) ? (string) $node['title'] : '';
		$slug = isset($node['slug']) ? (string) $node['slug'] : '';

		if ($slug !== '') {
			$label = $title !== '' ? $title : $slug;

			if ($depth > 0) {
				$label = str_repeat('-- ', $depth) . $label;
			}

			$options[] = [
				'slug' => $slug,
				'label' => sprintf('%s (%s)', $label, $slug),
			];
		}

		if (empty($node['children']) || ! is_array($node['children'])) {
			return;
		}

		foreach ($node['children'] as $child) {
			if (! is_array($child)) {
				continue;
			}

			$this->build_import_target_options($child, $options, $depth + 1);
		}
	}

	private function render_node(array $node): void {
		$title_raw = isset($node['title']) ? (string) $node['title'] : '';
		$value_raw = $node['value'] ?? $node['url'] ?? '';
		if (! is_scalar($value_raw)) {
			$value_raw = '';
		}
		$node_has_value = false;
		if (array_key_exists('value', $node) && $node['value'] !== '') {
			$node_has_value = true;
		} elseif (array_key_exists('url', $node) && $node['url'] !== '') {
			$node_has_value = true;
		}

		$has_children = ! empty($node['children']);
		$slug = isset($node['slug']) ? (string) $node['slug'] : '';
		$can_manage = current_user_can(self::CAPABILITY);
		$current_url = $can_manage ? $this->get_current_url() : '';

		echo '<div class="jsonmaker-node">';

		echo '<div class="jsonmaker-node__title">';
		printf('<span class="jsonmaker-node__label">%s</span>', esc_html($title_raw));

		if ($value_raw !== '') {
			$value_raw = (string) $value_raw;
			if ((bool) filter_var($value_raw, FILTER_VALIDATE_URL)) {
				printf(
					' <span class="jsonmaker-node__value">-&gt; <a href="%1$s">%2$s</a></span>',
					esc_url($value_raw),
					esc_html($value_raw)
				);
			} else {
				printf(
					' <span class="jsonmaker-node__value">-&gt; %s</span>',
					esc_html($value_raw)
				);
			}
		}

		if ($can_manage && $slug !== '') {
			printf(
				' <button type="button" class="jsonmaker-add-button" data-jsonmaker-target="%1$s"%3$s>%2$s</button>',
				esc_attr('jsonmaker-form-' . $slug),
				esc_html__('Add Node', 'fishdan-jsonmaker'),
				$node_has_value ? ' data-jsonmaker-has-value="1"' : ''
			);
			printf(
				' <button type="button" class="jsonmaker-edit-button" data-jsonmaker-target="%1$s">%2$s</button>',
				esc_attr('jsonmaker-edit-form-' . $slug),
				esc_html__('Edit', 'fishdan-jsonmaker')
			);
			$this->render_delete_form($slug, $has_children, $current_url);
		}
		echo '</div>';

		if ($has_children) {
			echo '<div class="jsonmaker-node__children">';
			foreach ($node['children'] as $child) {
				$this->render_node($child);
			}

			echo '</div>';
		}

		if ($can_manage) {
			$this->render_add_form($slug, $current_url);
			$this->render_edit_form($slug, $title_raw, $current_url);
		}

		echo '</div>';
	}

	private function render_add_form(string $parent_slug, ?string $redirect = null): void {
		if ($parent_slug === '') {
			return;
		}

		$form_id = 'jsonmaker-form-' . $parent_slug;

		$current_url = $redirect ?? $this->get_current_url();

		echo '<div id="' . esc_attr($form_id) . '" class="jsonmaker-add-form" hidden>';
		echo '<form method="post" action="' . esc_url($current_url) . '">';
		wp_nonce_field('jsonmaker_add_node', 'jsonmaker_nonce');
		echo '<input type="hidden" name="jsonmaker_action" value="add_node" />';
		echo '<input type="hidden" name="jsonmaker_parent" value="' . esc_attr($parent_slug) . '" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($current_url) . '" />';
		echo '<label>';
		echo esc_html__('Title', 'fishdan-jsonmaker') . '<br />';
		echo '<input type="text" name="jsonmaker_title" required />';
		echo '</label><br />';
		echo '<label>';
		echo esc_html__('Value (leave blank to create a container)', 'fishdan-jsonmaker') . '<br />';
		echo '<input type="text" name="jsonmaker_value" />';
		echo '</label><br />';
		echo '<button type="submit">' . esc_html__('Add Child', 'fishdan-jsonmaker') . '</button>';
		echo '</form>';
		echo '</div>';
	}

	private function render_edit_form(string $target_slug, string $current_title, ?string $redirect = null): void {
		if ($target_slug === '') {
			return;
		}

		$form_id = 'jsonmaker-edit-form-' . $target_slug;
		$current_url = $redirect ?? $this->get_current_url();

		echo '<div id="' . esc_attr($form_id) . '" class="jsonmaker-edit-form" hidden>';
		echo '<form method="post" action="' . esc_url($current_url) . '">';
		wp_nonce_field('jsonmaker_edit_node', 'jsonmaker_edit_nonce');
		echo '<input type="hidden" name="jsonmaker_action" value="edit_node" />';
		echo '<input type="hidden" name="jsonmaker_target" value="' . esc_attr($target_slug) . '" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($current_url) . '" />';
		echo '<label>';
		echo esc_html__('Title', 'fishdan-jsonmaker') . '<br />';
		echo '<input type="text" name="jsonmaker_title" value="' . esc_attr($current_title) . '" required />';
		echo '</label><br />';
		echo '<button type="submit">' . esc_html__('Save Title', 'fishdan-jsonmaker') . '</button>';
		echo '</form>';
		echo '</div>';
	}

	private function render_delete_form(string $target_slug, bool $has_children, string $redirect): void {
		if ($target_slug === '' || $redirect === '') {
			return;
		}

		echo '<form method="post" action="' . esc_url($redirect) . '" class="jsonmaker-delete-form">';
		wp_nonce_field('jsonmaker_delete_node', 'jsonmaker_delete_nonce', false);
		echo '<input type="hidden" name="jsonmaker_action" value="delete_node" />';
		echo '<input type="hidden" name="jsonmaker_target" value="' . esc_attr($target_slug) . '" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($redirect) . '" />';
		echo '<button type="submit" class="jsonmaker-delete-button"';
		if ($has_children) {
			echo ' data-jsonmaker-has-children="1" data-jsonmaker-message="' . esc_attr__('Remove child nodes before deleting this node.', 'fishdan-jsonmaker') . '"';
		}
		echo '>';
		echo esc_html__('Delete Node', 'fishdan-jsonmaker');
		echo '</button>';
		echo '</form>';
		$api_url = home_url('/json/' . rawurlencode($target_slug) . '.json');
		echo ' <a class="jsonmaker-view-button" href="' . esc_url($api_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View Node', 'fishdan-jsonmaker') . '</a>';
	}

	private function get_tree(): array {
		$tree = get_option(self::OPTION_NAME);

		if (! is_array($tree)) {
			self::ensure_initial_tree();
			$tree = get_option(self::OPTION_NAME);
		}

		return is_array($tree) ? $tree : [];
	}

	private function find_node(array $node, string $slug): ?array {
		if (($node['slug'] ?? '') === $slug) {
			return $node;
		}

		if (empty($node['children'])) {
			return null;
		}

		foreach ($node['children'] as $child) {
			$match = $this->find_node($child, $slug);

			if ($match !== null) {
				return $match;
			}
		}

		return null;
	}

	private function add_child_node(array &$node, string $parent_slug, array $child): bool {
		if (($node['slug'] ?? '') === $parent_slug) {
			if (array_key_exists('value', $node)) {
				unset($node['value']);
			}
			if (array_key_exists('url', $node)) {
				unset($node['url']);
			}
			if (! isset($node['children']) || ! is_array($node['children'])) {
				$node['children'] = [];
			}

			$node['children'][] = $child;

			return true;
		}

		if (empty($node['children'])) {
			return false;
		}

		foreach ($node['children'] as &$existing_child) {
			if ($this->add_child_node($existing_child, $parent_slug, $child)) {
				return true;
			}
		}

		return false;
	}

	private function remove_node(array &$node, string $slug): bool {
		if (empty($node['children'])) {
			return false;
		}

		foreach ($node['children'] as $index => &$child) {
			if (($child['slug'] ?? '') === $slug) {
				array_splice($node['children'], $index, 1);

				return true;
			}

			if ($this->remove_node($child, $slug)) {
				return true;
			}
		}

		return false;
	}

	private function update_node_title(array &$node, string $target_slug, string $new_title, string $new_slug): bool {
		if (($node['slug'] ?? '') === $target_slug) {
			$node['title'] = $new_title;
			$node['slug'] = $new_slug;

			return true;
		}

		if (empty($node['children'])) {
			return false;
		}

		foreach ($node['children'] as &$child) {
			if ($this->update_node_title($child, $target_slug, $new_title, $new_slug)) {
				return true;
			}
		}

		return false;
	}

	private function create_unique_slug(string $title, array $tree, ?string $exclude_slug = null): string {
		$base = sanitize_title($title);

		if ($base === '') {
			$base = 'node';
		}

		$slug = $base;
		$index = 2;

		while ($slug === '' || $this->slug_exists($tree, $slug, $exclude_slug)) {
			$slug = $base . '-' . $index;
			$index++;
		}

		return $slug;
	}

	private function slug_exists(array $node, string $slug, ?string $exclude_slug = null): bool {
		$current_slug = $node['slug'] ?? '';

		if ($current_slug === $slug && $current_slug !== $exclude_slug) {
			return true;
		}

		if (empty($node['children'])) {
			return false;
		}

		foreach ($node['children'] as $child) {
			if ($this->slug_exists($child, $slug, $exclude_slug)) {
				return true;
			}
		}

		return false;
	}

	private function title_exists(array $node, string $title, ?string $exclude_slug = null): bool {
		$node_title = isset($node['title']) ? (string) $node['title'] : '';
		$node_slug = $node['slug'] ?? '';

		if ($node_title !== '' && ($exclude_slug === null || $node_slug !== $exclude_slug)) {
			if (strcasecmp($node_title, $title) === 0) {
				return true;
			}
		}

		if (empty($node['children'])) {
			return false;
		}

		foreach ($node['children'] as $child) {
			if ($this->title_exists($child, $title, $exclude_slug)) {
				return true;
			}
		}

		return false;
	}

	private function prepare_public_node(array $node): array {
		$output = [
			'title' => $node['title'] ?? '',
		];

		$value = $node['value'] ?? $node['url'] ?? '';

		if ($value !== '') {
			$output['value'] = $value;
		}

		if (! empty($node['children'])) {
			$output['children'] = [];

			foreach ($node['children'] as $child) {
				$output['children'][] = $this->prepare_public_node($child);
			}
		}

		return $output;
	}

	private function redirect_with_message(string $code, bool $success = false): void {
		$redirect = '';

		$redirect_raw = filter_input(INPUT_POST, 'jsonmaker_redirect', FILTER_UNSAFE_RAW);
		if (is_string($redirect_raw)) {
			$redirect = esc_url_raw(wp_unslash($redirect_raw));
		}

		if ($redirect === '') {
			$redirect = wp_get_referer() ?: home_url();
		}

		if ($code !== '') {
			$redirect = add_query_arg('jsonmaker_msg', $code, $redirect);
		}

		$status = $success ? 'success' : 'error';
		$redirect = add_query_arg('jsonmaker_status', $status, $redirect);

		wp_safe_redirect($redirect);
		exit;
	}

	private function get_notice_text(string $code): string {
		switch ($code) {
			case 'node_added':
				return __('Node added.', 'fishdan-jsonmaker');
			case 'node_deleted':
				return __('Node deleted.', 'fishdan-jsonmaker');
			case 'title_updated':
				return __('Title updated.', 'fishdan-jsonmaker');
			case 'parent_not_found':
				return __('Unable to find the parent node.', 'fishdan-jsonmaker');
			case 'missing_fields':
				return __('Please provide all required fields.', 'fishdan-jsonmaker');
			case 'cannot_delete_root':
				return __('Cannot delete the root node.', 'fishdan-jsonmaker');
			case 'node_not_found':
				return __('The requested node could not be found.', 'fishdan-jsonmaker');
			case 'has_children':
				return __('Remove child nodes before deleting this node.', 'fishdan-jsonmaker');
			case 'title_exists':
				return __('A node with that title already exists. Choose a different title.', 'fishdan-jsonmaker');
			case 'import_success':
				return __('Tree imported.', 'fishdan-jsonmaker');
			case 'import_invalid_json':
				return __('Unable to parse JSON. Check the syntax and try again.', 'fishdan-jsonmaker');
			case 'import_invalid_structure':
				return __('The JSON does not match the expected schema.', 'fishdan-jsonmaker');
			case 'import_duplicate_title':
				return __('Each node title must be unique. Resolve duplicates and try again.', 'fishdan-jsonmaker');
			case 'import_target_missing':
				return __('Choose a node to append the imported data to.', 'fishdan-jsonmaker');
			case 'import_target_not_found':
				return __('Unable to find the selected append target.', 'fishdan-jsonmaker');
			default:
				return '';
		}
	}

	private function maybe_print_assets(): void {
		if ($this->printed_assets) {
			return;
		}

		$this->printed_assets = true;

		if (! wp_style_is('jsonmaker-inline', 'registered')) {
			wp_register_style('jsonmaker-inline', false, [], JSONMAKER_VERSION);
		}
		wp_enqueue_style('jsonmaker-inline');
		$style_lines = [
			'.jsonmaker-json {font-family: Menlo, Consolas, monospace; background:#f5f5f5; padding:1rem; border:1px solid #ddd; overflow:auto;}',
			'.jsonmaker-tree {font-family: Arial, sans-serif;}',
			'.jsonmaker-node {border-left:2px solid #ddd; margin-left:1rem; padding-left:1rem; margin-top:0.5rem;}',
			'.jsonmaker-node__title {display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;}',
			'.jsonmaker-node__label {font-weight:600;}',
			'.jsonmaker-node__value {font-family:Menlo, Consolas, monospace;}',
			'.jsonmaker-node__title a {text-decoration:none;}',
			'.jsonmaker-add-button,',
			'.jsonmaker-edit-button,',
			'.jsonmaker-delete-button,',
			'.jsonmaker-view-button {font-size:0.8rem; padding:0.1rem 0.4rem; cursor:pointer; display:inline-block; text-decoration:none; background:#f8f9fa; border:1px solid #cbd5e0; border-radius:3px; color:#1a202c;}',
			'.jsonmaker-delete-form {display:inline; margin:0;}',
			'.jsonmaker-delete-button {margin-left:0.25rem;}',
			'.jsonmaker-view-button {margin-left:0.25rem;}',
			'.jsonmaker-section {border:1px solid #d9d9d9; border-radius:6px; background:#fff; margin-bottom:1rem; box-shadow:0 1px 2px rgba(16,24,40,0.04);}',
			'.jsonmaker-section__header {background:#f3f4f6; padding:0.6rem 0.9rem; border-bottom:1px solid #d9d9d9;}',
			'.jsonmaker-section__toggle {display:flex; align-items:center; justify-content:flex-start; gap:0.5rem; width:100%; background:none; border:0; padding:0; font-size:1rem; cursor:pointer; color:#1a202c;}',
			'.jsonmaker-section__toggle:focus {outline:2px solid #3182ce; outline-offset:2px;}',
			'.jsonmaker-section__title {display:flex; align-items:center; gap:0.5rem;}',
			'.jsonmaker-section__indicator {font-size:1.25rem; line-height:1; margin-right:0.25rem; width:1.25rem; text-align:center; transition:color 0.2s ease-in-out;}',
			'.jsonmaker-section__content {padding:0.9rem;}',
			'.jsonmaker-import {margin-bottom:1rem;}',
			'.jsonmaker-import form {background:#f9f9f9; border:1px solid #ccc; padding:0.75rem;}',
			'.jsonmaker-import__intro {margin-top:0; margin-bottom:0.75rem;}',
			'.jsonmaker-import__label {display:block; font-weight:600; margin-bottom:0.25rem;}',
			'.jsonmaker-import__mode {display:flex; flex-direction:column; gap:0.25rem; margin-bottom:0.5rem;}',
			'.jsonmaker-import__mode label {font-weight:400;}',
			'.jsonmaker-import__target {margin-bottom:0.75rem;}',
			'.jsonmaker-import select {width:100%; max-width:20rem;}',
			'.jsonmaker-import__hint {margin:0.25rem 0 0; font-size:0.85rem; color:#555;}',
			'.jsonmaker-import textarea {width:100%; font-family: Menlo, Consolas, monospace; min-height:10rem;}',
			'.jsonmaker-import__actions {margin-top:0.5rem;}',
			'.jsonmaker-add-form,',
			'.jsonmaker-edit-form {margin-top:0.5rem;}',
			'.jsonmaker-add-form form,',
			'.jsonmaker-edit-form form {background:#f9f9f9; border:1px solid #ccc; padding:0.5rem;}',
			'.jsonmaker-notice {margin-bottom:1rem; padding:0.75rem 1rem; border-radius:4px; border:1px solid transparent; font-weight:600;}',
			'.jsonmaker-notice--success {background:#f0fff4; border-color:#38a169; color:#276749;}',
			'.jsonmaker-notice--error {background:#fff5f5; border-color:#e53e3e; color:#9b2c2c;}',
		];
		wp_add_inline_style('jsonmaker-inline', implode("\n", $style_lines));

		if (! wp_script_is('jsonmaker-inline', 'registered')) {
			wp_register_script('jsonmaker-inline', false, [], JSONMAKER_VERSION, true);
		}
		wp_enqueue_script('jsonmaker-inline');
		$confirm_replace = esc_js(__('Confirm you want to erase the entire tree and replace it?', 'fishdan-jsonmaker'));
		$child_value_warning = esc_js(__('Adding a child to this node will delete its current value, so it becomes a folder and not a link in the bookmark. Continue?', 'fishdan-jsonmaker'));
		$script_lines = [
			"const jsonmakerSectionCookiePrefix = 'jsonmaker_section_';",
			"function jsonmakerSetSectionState(id, isOpen) {",
			"\tconst maxAge = 60 * 60 * 24 * 365;",
			"\tdocument.cookie = jsonmakerSectionCookiePrefix + id + '=' + (isOpen ? 'open' : 'closed') + '; path=/; max-age=' + maxAge;",
			"}",
			"function jsonmakerGetSectionState(id) {",
			"\tconst pattern = new RegExp('(?:^|; )' + jsonmakerSectionCookiePrefix + id + '=([^;]*)');",
			"\tconst match = document.cookie.match(pattern);",
			"\treturn match ? match[1] : null;",
			"}",
			"function jsonmakerApplySectionState(section) {",
			"\tconst id = section.dataset.jsonmakerSection;",
			"\tif (!id) {",
			"\t\treturn;",
			"\t}",
			"\tconst defaultOpen = section.dataset.jsonmakerSectionDefault === 'open';",
			"\tconst stored = jsonmakerGetSectionState(id);",
			"\tconst isOpen = stored === null ? defaultOpen : stored === 'open';",
			"\tconst content = section.querySelector('.jsonmaker-section__content');",
			"\tconst toggle = section.querySelector('[data-jsonmaker-section-toggle]');",
			"\tconst indicator = section.querySelector('[data-jsonmaker-section-indicator]');",
			"\tif (!content || !toggle) {",
			"\t\treturn;",
			"\t}",
			"\tif (isOpen) {",
			"\t\tcontent.removeAttribute('hidden');",
			"\t\ttoggle.setAttribute('aria-expanded', 'true');",
			"\t\tsection.classList.remove('jsonmaker-section--collapsed');",
			"\t\tif (indicator) {",
			"\t\t\tindicator.textContent = '−';",
			"\t\t}",
			"\t} else {",
			"\t\tcontent.setAttribute('hidden', '');",
			"\t\ttoggle.setAttribute('aria-expanded', 'false');",
			"\t\tsection.classList.add('jsonmaker-section--collapsed');",
			"\t\tif (indicator) {",
			"\t\t\tindicator.textContent = '+';",
			"\t\t}",
			"\t}",
			"}",
			"document.addEventListener('DOMContentLoaded', function () {",
			"\tdocument.querySelectorAll('[data-jsonmaker-section]').forEach(function (section) {",
			"\t\tjsonmakerApplySectionState(section);",
			"\t});",
			"});",
			"document.addEventListener('click', function (event) {",
			"\tconst toggle = event.target.closest('[data-jsonmaker-section-toggle]');",
			"\tif (!toggle) {",
			"\t\treturn;",
			"\t}",
			"\tevent.preventDefault();",
			"\tconst id = toggle.dataset.jsonmakerSectionToggle;",
			"\tif (!id) {",
			"\t\treturn;",
			"\t}",
			"\tconst section = document.querySelector('[data-jsonmaker-section=\"' + id + '\"]');",
			"\tif (!section) {",
			"\t\treturn;",
			"\t}",
			"\tconst content = section.querySelector('.jsonmaker-section__content');",
			"\tconst indicator = section.querySelector('[data-jsonmaker-section-indicator]');",
			"\tif (!content) {",
			"\t\treturn;",
			"\t}",
			"\tconst isOpen = !content.hasAttribute('hidden');",
			"\tif (isOpen) {",
			"\t\tcontent.setAttribute('hidden', '');",
			"\t\ttoggle.setAttribute('aria-expanded', 'false');",
			"\t\tsection.classList.add('jsonmaker-section--collapsed');",
			"\t\tjsonmakerSetSectionState(id, false);",
			"\t\tif (indicator) {",
			"\t\t\tindicator.textContent = '+';",
			"\t\t}",
			"\t} else {",
			"\t\tcontent.removeAttribute('hidden');",
			"\t\ttoggle.setAttribute('aria-expanded', 'true');",
			"\t\tsection.classList.remove('jsonmaker-section--collapsed');",
			"\t\tjsonmakerSetSectionState(id, true);",
			"\t\tif (indicator) {",
			"\t\t\tindicator.textContent = '−';",
			"\t\t}",
			"\t}",
			"});",
			"const jsonmakerConfirmReplace = '" . $confirm_replace . "';",
			"const jsonmakerAddChildValueWarning = '" . $child_value_warning . "';",
			"document.addEventListener('click', function (event) {",
			"\tconst addButton = event.target.closest('.jsonmaker-add-button');",
			"\tif (addButton) {",
			"\t\tevent.preventDefault();",
			"\t\tconst targetId = addButton.dataset.jsonmakerTarget;",
			"\t\tif (!targetId) {",
			"\t\t\treturn;",
			"\t\t}",
			"\t\tif (addButton.dataset.jsonmakerHasValue === '1') {",
			"\t\t\tconst confirmed = window.confirm(jsonmakerAddChildValueWarning);",
			"\t\t\tif (!confirmed) {",
			"\t\t\t\treturn;",
			"\t\t\t}",
			"\t\t}",
			"\t\tconst form = document.getElementById(targetId);",
			"\t\tif (!form) {",
			"\t\t\treturn;",
			"\t\t}",
			"\t\tconst isHidden = form.hasAttribute('hidden');",
			"\t\tif (isHidden) {",
			"\t\t\tform.removeAttribute('hidden');",
			"\t\t\tconst focusable = form.querySelector('input[name=\"jsonmaker_title\"]');",
			"\t\t\tif (focusable) {",
			"\t\t\t\tfocusable.focus();",
			"\t\t\t}",
			"\t\t} else {",
			"\t\t\tform.setAttribute('hidden', '');",
			"\t\t}",
			"\t\treturn;",
			"\t}",
			"\tconst editButton = event.target.closest('.jsonmaker-edit-button');",
			"\tif (editButton) {",
			"\t\tevent.preventDefault();",
			"\t\tconst targetId = editButton.dataset.jsonmakerTarget;",
			"\t\tif (!targetId) {",
			"\t\t\treturn;",
			"\t\t}",
			"\t\tconst form = document.getElementById(targetId);",
			"\t\tif (!form) {",
			"\t\t\treturn;",
			"\t\t}",
			"\t\tconst isHidden = form.hasAttribute('hidden');",
			"\t\tif (isHidden) {",
			"\t\t\tform.removeAttribute('hidden');",
			"\t\t\tconst focusable = form.querySelector('input[name=\"jsonmaker_title\"]');",
			"\t\t\tif (focusable) {",
			"\t\t\t\tfocusable.focus();",
			"\t\t\t}",
			"\t\t} else {",
			"\t\t\tform.setAttribute('hidden', '');",
			"\t\t}",
			"\t\treturn;",
			"\t}",
			"\tconst deleteButton = event.target.closest('.jsonmaker-delete-button');",
			"\tif (!deleteButton) {",
			"\t\treturn;",
			"\t}",
			"\tif (deleteButton.dataset.jsonmakerHasChildren === '1') {",
			"\t\tevent.preventDefault();",
			"\t\tconst message = deleteButton.dataset.jsonmakerMessage;",
			"\t\tif (message) {",
			"\t\t\twindow.alert(message);",
			"\t\t}",
			"\t\treturn;",
			"\t}",
			"});",
			"document.addEventListener('change', function (event) {",
			"\tif (!event.target) {",
			"\t\treturn;",
			"\t}",
			"\tif (event.target.name === 'jsonmaker_import_mode') {",
			"\t\tconst targetSelect = document.querySelector('[data-jsonmaker-import-target]');",
			"\t\tconst appendRadio = document.querySelector('input[name=\"jsonmaker_import_mode\"][value=\"append\"]');",
			"\t\tif (!targetSelect) {",
			"\t\t\treturn;",
			"\t\t}",
			"\t\tif (event.target.value === 'append') {",
			"\t\t\ttargetSelect.setAttribute('required', '');",
			"\t\t\treturn;",
			"\t\t}",
			"\t\tif (event.target.value === 'replace') {",
			"\t\t\ttargetSelect.removeAttribute('required');",
			"\t\t\tif (!window.confirm(jsonmakerConfirmReplace)) {",
			"\t\t\t\tif (appendRadio) {",
			"\t\t\t\t\tappendRadio.checked = true;",
			"\t\t\t\t\tappendRadio.dispatchEvent(new Event('change', { bubbles: true }));",
			"\t\t\t\t}",
			"\t\t\t\treturn;",
			"\t\t\t}",
			"\t\t}",
			"\t}",
			"});"
		];
		wp_add_inline_script('jsonmaker-inline', implode("\n", $script_lines));
	}

	private function get_current_url(): string {
		$request_uri_raw = filter_input(INPUT_SERVER, 'REQUEST_URI', FILTER_UNSAFE_RAW);
		$request_uri = is_string($request_uri_raw) ? wp_unslash($request_uri_raw) : '';
		$request_uri = esc_url_raw($request_uri);

		return home_url($request_uri);
	}

	public function maybe_add_cors_headers(): void {
		$requested = get_query_var('jsonmaker_node');
		if ($requested === '') {
			$raw = filter_input(INPUT_GET, 'jsonmaker_node', FILTER_UNSAFE_RAW);
			if (! is_string($raw) || $raw === '') {
				return;
			}
		}

		$this->send_cors_headers();
	}

	public function maybe_disable_canonical_redirect($redirect_url, $requested_url) {
		$requested = get_query_var('jsonmaker_node');
		if ($requested !== '') {
			return false;
		}

		$raw = filter_input(INPUT_GET, 'jsonmaker_node', FILTER_UNSAFE_RAW);
		if (is_string($raw) && $raw !== '') {
			return false;
		}

		if (strpos($requested_url, '/json/') !== false) {
			return false;
		}

		return $redirect_url;
	}

	private function send_cors_headers(): void {
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: GET, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type');
	}

	public function ensure_admin_connect_title(): void {
		global $title;

		if (! is_string($title) || $title === '') {
			$title = __('fishdan Jsonmaker', 'fishdan-jsonmaker');
		}
	}

	public function filter_freemius_plugin_icon($current) {
		if (is_string($current) && $current !== '') {
			return $current;
		}

		$fallback = plugin_dir_path(__FILE__) . 'vendor/freemius/assets/img/plugin-icon.png';

		return $fallback;
	}
}

register_activation_hook(__FILE__, ['Jsonmaker_Plugin', 'activate']);
register_deactivation_hook(__FILE__, ['Jsonmaker_Plugin', 'deactivate']);
register_uninstall_hook(__FILE__, ['Jsonmaker_Plugin', 'uninstall']);
Jsonmaker_Plugin::instance();
