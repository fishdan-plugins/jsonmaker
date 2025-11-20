<?php
/**
 * Plugin Name: fishdan Jsonmaker
 * Plugin URI: https://www.fishdan.com/jsonmaker
 * Description: Manage a hierarchical collection of titled links that can be edited from a shortcode and fetched as JSON.
 * Version: 0.2.4
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
	define('JSONMAKER_VERSION', '0.2.4');
}

if (! function_exists('jm_fs')) {
	/**
	 * Provide a helper for accessing the Freemius SDK instance.
	 */
	function jm_fs() {
		global $jm_fs;

		if (! isset($jm_fs)) {
			require_once dirname(__FILE__) . '/vendor/freemius/start.php';

			$jm_fs = fs_dynamic_init([
				'id' => '21365',
				'slug' => 'json-maker',
				'type' => 'plugin',
				'public_key' => 'pk_404c69d00480e719a56ebde3bbe2f',
				'is_premium' => true,
				'premium_suffix' => 'Basic',
				'has_premium_version' => true,
				'has_addons' => false,
				'has_paid_plans' => true,
				'wp_org_gatekeeper' => 'OA7#BoRiBNqdf52FvzEf!!074aRLPs8fspif$7K1#4u4Csys1fQlCecVcUTOs2mcpeVHi#C2j9d09fOTvbC0HloPT7fFee5WdS3G',
				'menu' => [
					'first-path' => 'plugins.php',
					'support' => false,
				],
			]);
		}

		return $jm_fs;
	}

	// Init Freemius.
	jm_fs();
	// Signal that SDK was initiated.
	do_action('jm_fs_loaded');
}

function jsonmaker_freemius_icon_path() {
	return plugin_dir_path(__FILE__) . 'vendor/freemius/assets/img/plugin-icon.png';
}

$jm_fs_instance = jm_fs();
if ($jm_fs_instance) {
	$jm_fs_instance->add_filter('plugin_icon', 'jsonmaker_freemius_icon_path');
}

final class Jsonmaker_Plugin {
	private const OPTION_NAME = 'jsonmaker_tree';
	private const USER_META_KEY = 'jsonmaker_tree';
	private const CAPABILITY = 'jsonmaker_manage';
	private const ROLE_NAME = 'json';
	private const FREE_PLAN_ID = 35655;
	private const PAID_PLAN_ID = 36439;

	private static ?Jsonmaker_Plugin $instance = null;
	private bool $printed_assets = false;
	/** @var array<string, bool> */
	private array $enforced_nonce_actions = [];

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
		add_shortcode('jsonmaker_checkout', [$this, 'render_checkout_shortcode']);
		add_action('send_headers', [$this, 'maybe_add_cors_headers']);
		add_filter('redirect_canonical', [$this, 'maybe_disable_canonical_redirect'], 10, 2);
		add_action('load-admin_page_fishdan-jsonmaker', [$this, 'ensure_admin_connect_title'], 5);
		add_action('load-admin_page_fishdan-jsonmaker-network', [$this, 'ensure_admin_connect_title'], 5);
		add_action('admin_menu', [$this, 'register_admin_menu']);
		add_action('network_admin_menu', [$this, 'register_network_admin_menu']);

		$fs = jm_fs();
		if (is_object($fs) && method_exists($fs, 'add_filter')) {
			$fs->add_filter('plugin_icon', [$this, 'filter_freemius_plugin_icon']);
		}
	}

	public static function activate(): void {
		self::ensure_role();
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

		if (function_exists('remove_role')) {
			remove_role(self::ROLE_NAME);
		}

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

		$initial = self::get_default_tree_template_static();

		add_option(self::OPTION_NAME, $initial);
	}

	private static function ensure_role(): void {
		if (! function_exists('add_role')) {
			return;
		}

		$role = get_role(self::ROLE_NAME);

		if ($role === null) {
			$role = add_role(
				self::ROLE_NAME,
				__('JSON User', 'fishdan-jsonmaker'),
				[
					'read' => true,
				]
			);
		}

		if ($role instanceof WP_Role && ! $role->has_cap(self::CAPABILITY)) {
			$role->add_cap(self::CAPABILITY);
		}
	}

	private static function get_default_tree_template_static(): array {
		return [
			'title' => 'Popular',
			'slug' => 'popular',
			'children' => [
				[
					'title' => 'Google',
					'slug' => 'google',
					'value' => 'https://www.google.com',
					'children' => [],
				],
				[
					'title' => 'YouTube',
					'slug' => 'youtube',
					'value' => 'https://www.youtube.com',
					'children' => [],
				],
				[
					'title' => 'Facebook',
					'slug' => 'facebook',
					'value' => 'https://www.facebook.com',
					'children' => [],
				],
				[
					'title' => 'Amazon',
					'slug' => 'amazon',
					'value' => 'https://www.amazon.com',
					'children' => [],
				],
				[
					'title' => 'Reddit',
					'slug' => 'reddit',
					'value' => 'https://www.reddit.com',
					'children' => [],
				],
				[
					'title' => 'Yahoo',
					'slug' => 'yahoo',
					'value' => 'https://www.yahoo.com',
					'children' => [],
				],
			],
		];
	}

	private static function ensure_capability(): void {
		if (! function_exists('get_role')) {
			return;
		}

		$role_names = ['administrator', self::ROLE_NAME];

		foreach ($role_names as $role_name) {
			$role = get_role($role_name);

			if ($role === null) {
				continue;
			}

			if (! $role->has_cap(self::CAPABILITY)) {
				$role->add_cap(self::CAPABILITY);
			}
		}
	}

	public function register_rewrite(): void {
		add_rewrite_rule('^json/([^/]+)/([^/]+)\.json$', 'index.php?jsonmaker_user=$matches[1]&jsonmaker_node=$matches[2]', 'top');
	}

	public function register_admin_menu(): void {
		$this->add_plugin_admin_page();
	}

	public function register_network_admin_menu(): void {
		if (! is_multisite()) {
			return;
		}

		$this->add_plugin_admin_page();
	}

	private function add_plugin_admin_page(): void {
		$page_title = __('fishdan Jsonmaker', 'fishdan-jsonmaker');
		$menu_title = __('Jsonmaker', 'fishdan-jsonmaker');
		$capability = self::CAPABILITY;
		$menu_slug = 'fishdan-jsonmaker';
		$icon = 'dashicons-networking';
		$position = 58;

		add_menu_page(
			$page_title,
			$menu_title,
			$capability,
			$menu_slug,
			[$this, 'render_admin_page'],
			$icon,
			$position
		);
	}

	public function register_query_var(array $vars): array {
		$vars[] = 'jsonmaker_node';
		$vars[] = 'jsonmaker_user';

		return $vars;
	}

	public function maybe_handle_submission(): void {
		$action_raw = $this->get_post_string('jsonmaker_action');
		$action = is_string($action_raw) ? sanitize_key($action_raw) : '';

		if ($action === '') {
			return;
		}

		$this->enforce_action_nonce($action);

		if ($action === 'register_json_user') {
			$this->handle_register_submission();

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
		$parent_raw = $this->get_post_string('jsonmaker_parent');
		$title_raw = $this->get_post_string('jsonmaker_title');

		if ($parent_raw === null || $title_raw === null) {
			return;
		}

		if (! $this->is_nonce_action_enforced('add_node')) {
			check_admin_referer('jsonmaker_add_node', 'jsonmaker_nonce');
			$this->mark_nonce_action_enforced('add_node');
		}

		$parent_slug = sanitize_key($parent_raw);
		$title = sanitize_text_field($title_raw);
		$title = trim($title);
		$value = '';

		$value_raw = $this->get_post_string('jsonmaker_value');
		if ($value_raw !== null) {
			$value = sanitize_text_field($value_raw);
		}

		if ($title === '' || $parent_slug === '') {
			$this->redirect_with_message('missing_fields');
		}

		$user_id = get_current_user_id();

		if ($user_id === 0) {
			return;
		}

		$tree = $this->get_tree($user_id);

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
			$this->maybe_ensure_about_links($tree);
			$this->save_tree($tree, $user_id);
			$this->redirect_with_message('node_added', true);
		}

		$this->redirect_with_message('parent_not_found');
	}

	private function handle_delete_submission(): void {
		$target_raw = $this->get_post_string('jsonmaker_target');

		if ($target_raw === null) {
			return;
		}

		if (! $this->is_nonce_action_enforced('delete_node')) {
			check_admin_referer('jsonmaker_delete_node', 'jsonmaker_delete_nonce');
			$this->mark_nonce_action_enforced('delete_node');
		}

		$target_slug = sanitize_key($target_raw);

		if ($target_slug === '') {
			$this->redirect_with_message('missing_fields');
		}

		$user_id = get_current_user_id();

		if ($user_id === 0) {
			return;
		}

		$tree = $this->get_tree($user_id);

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
			$this->maybe_ensure_about_links($tree);
			$this->save_tree($tree, $user_id);
			$this->redirect_with_message('node_deleted', true);
		}

		$this->redirect_with_message('node_not_found');
	}

	private function handle_edit_submission(): void {
		$target_raw = $this->get_post_string('jsonmaker_target');
		$title_raw = $this->get_post_string('jsonmaker_title');

		if ($target_raw === null || $title_raw === null) {
			return;
		}

		if (! $this->is_nonce_action_enforced('edit_node')) {
			check_admin_referer('jsonmaker_edit_node', 'jsonmaker_edit_nonce');
			$this->mark_nonce_action_enforced('edit_node');
		}

		$target_slug = sanitize_key($target_raw);
		$new_title = sanitize_text_field($title_raw);
		$new_title = trim($new_title);

		if ($target_slug === '' || $new_title === '') {
			$this->redirect_with_message('missing_fields');
		}

		$user_id = get_current_user_id();

		if ($user_id === 0) {
			return;
		}

		$tree = $this->get_tree($user_id);

		if ($this->title_exists($tree, $new_title, $target_slug)) {
			$this->redirect_with_message('title_exists');
		}

		$new_slug = $this->create_unique_slug($new_title, $tree, $target_slug);

		$updated = $this->update_node_title($tree, $target_slug, $new_title, $new_slug);

		if ($updated) {
			$this->save_tree($tree, $user_id);
			$this->redirect_with_message('title_updated', true);
		}

		$this->redirect_with_message('node_not_found');
	}

	private function handle_import_submission(): void {
		$json_raw = $this->get_post_string('jsonmaker_payload');

		if ($json_raw === null) {
			return;
		}

		if (! $this->is_nonce_action_enforced('import_json')) {
			check_admin_referer('jsonmaker_import', 'jsonmaker_import_nonce');
			$this->mark_nonce_action_enforced('import_json');
		}

		$mode_raw = $this->get_post_string('jsonmaker_import_mode');
		$mode = $mode_raw !== null ? sanitize_key($mode_raw) : 'replace';
		if ($mode !== 'append') {
			$mode = 'replace';
		}

		$target_slug = '';
		if ($mode === 'append') {
			$target_raw = $this->get_post_string('jsonmaker_import_target');

			if ($target_raw === null) {
				$this->redirect_with_message('import_target_missing');
			}

			$target_slug = sanitize_key($target_raw);

			if ($target_slug === '') {
				$this->redirect_with_message('import_target_missing');
			}
		}

		$payload = trim($json_raw);

		if ($payload === '') {
			$this->redirect_with_message('missing_fields');
		}

		$user_id = get_current_user_id();

		if ($user_id === 0) {
			return;
		}

		$decoded = json_decode($payload, true);

		if (! is_array($decoded)) {
			$this->redirect_with_message('import_invalid_json');
		}

		$decoded = $this->maybe_unwrap_user_payload($decoded);

		$error_code = '';
		if ($mode === 'replace') {
			$tree = $this->normalize_import_tree($decoded, $error_code);

			if ($tree === null || $error_code !== '') {
				$this->redirect_with_message($error_code !== '' ? $error_code : 'import_invalid_structure');
			}

			$this->save_tree($tree, $user_id);
			$this->redirect_with_message('import_success', true);
		}

		$current_tree = $this->get_tree($user_id);
		$node = $this->normalize_import_tree($decoded, $error_code, $current_tree);

		if ($node === null || $error_code !== '') {
			$this->redirect_with_message($error_code !== '' ? $error_code : 'import_invalid_structure');
		}

		if (! $this->add_child_node($current_tree, $target_slug, $node)) {
			$this->redirect_with_message('import_target_not_found');
		}

		$this->save_tree($current_tree, $user_id);
		$this->redirect_with_message('import_success', true);
	}

	private function handle_register_submission(): void {
		if (is_user_logged_in()) {
			return;
		}

		if (! $this->is_nonce_action_enforced('register_json_user')) {
			check_admin_referer('jsonmaker_register_user', 'jsonmaker_register_nonce');
			$this->mark_nonce_action_enforced('register_json_user');
		}

		$username_raw = $this->get_post_string('jsonmaker_username');
		$email_raw = $this->get_post_string('jsonmaker_email');
		$password_raw = $this->get_post_string('jsonmaker_password');

		if ($username_raw === null || $email_raw === null || $password_raw === null) {
			$this->redirect_with_message('register_missing_fields');
		}

		$username = sanitize_user($username_raw, true);
		$email = sanitize_email($email_raw);
		$password = (string) $password_raw;

		if ($username === '' || $email === '' || trim($password) === '') {
			$this->redirect_with_message('register_missing_fields');
		}

		if (! is_email($email)) {
			$this->redirect_with_message('register_invalid_email');
		}

		if (username_exists($username)) {
			$this->redirect_with_message('register_username_exists');
		}

		if (email_exists($email)) {
			$this->redirect_with_message('register_email_exists');
		}

		self::ensure_role();

		$user_id = wp_create_user($username, $password, $email);

		if (is_wp_error($user_id)) {
			$this->redirect_with_message('register_failed');
		}

		wp_update_user([
			'ID' => $user_id,
			'role' => self::ROLE_NAME,
		]);

		$user = get_user_by('id', $user_id);

		if ($user instanceof WP_User) {
			wp_set_current_user($user_id, $user->user_login);
			wp_set_auth_cookie($user_id);
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Invoke core wp_login hook after manual auth.
			do_action('wp_login', $user->user_login, $user);
		}

		$this->redirect_with_message('register_success', true);
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

	private function maybe_unwrap_user_payload(array $payload): array {
		$keys = array_keys($payload);

		if (count($keys) !== 1) {
			return $payload;
		}

		$first_key = $keys[0];

		if (! is_string($first_key)) {
			return $payload;
		}

		$first_value = $payload[$first_key];

		if (! is_array($first_value)) {
			return $payload;
		}

		if (! array_key_exists('title', $first_value) && ! array_key_exists('children', $first_value)) {
			return $payload;
		}

		return $first_value;
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

	private function resolve_user_from_path(string $value): ?WP_User {
		$decoded = rawurldecode($value);
		$decoded = trim($decoded);

		if ($decoded === '') {
			return null;
		}

		$user = get_user_by('login', $decoded);

		if ($user instanceof WP_User) {
			return $user;
		}

		$sanitized = sanitize_title($decoded);

		if ($sanitized === '') {
			return null;
		}

		$user = get_user_by('slug', $sanitized);

		return $user instanceof WP_User ? $user : null;
	}

	public function maybe_output_json(): void {
		$requested_node = get_query_var('jsonmaker_node');
		$requested_user = get_query_var('jsonmaker_user');

		if ($requested_node === '' || $requested_user === '') {
			return;
		}

		$this->send_cors_headers();

		$method_raw = $this->get_server_string('REQUEST_METHOD');
		$method = is_string($method_raw) ? strtoupper($method_raw) : 'GET';

		if ($method === 'OPTIONS') {
			status_header(200);
			exit;
		}

		$normalized_slug = $this->normalize_request_slug($requested_node);
		if ($normalized_slug === '') {
			status_header(404);
			wp_send_json(['error' => 'Node not found'], 404);
		}

		$user = $this->resolve_user_from_path($requested_user);

		if (! $user instanceof WP_User) {
			status_header(404);
			wp_send_json(['error' => 'User not found'], 404);
		}

		$tree = $this->get_tree((int) $user->ID);
		$node = $this->find_node($tree, $normalized_slug);

		if ($node === null) {
			status_header(404);
			wp_send_json(['error' => 'Node not found'], 404);
		}

		wp_send_json($this->prepare_public_node($node));
	}

	public function render_shortcode(): string {
		$this->maybe_print_assets();

		$notice_code = '';
		$notice_status = 'error';

		$notice_raw = $this->get_query_string('jsonmaker_msg');
		if ($notice_raw !== null) {
			$notice_code = sanitize_key($notice_raw);
		}

		$status_raw = $this->get_query_string('jsonmaker_status');
		if ($status_raw !== null) {
			$notice_status_candidate = sanitize_key($status_raw);
			if ($notice_status_candidate === 'success') {
				$notice_status = 'success';
			}
		}

		if (! is_user_logged_in()) {
			return $this->render_login_register_prompt($notice_code, $notice_status);
		}

		$tree = $this->get_tree();
		$can_manage = current_user_can(self::CAPABILITY);
		$current_url = $this->get_current_url();

		ob_start();
		echo '<div class="jsonmaker-wrapper container-fluid px-0">';
		if ($notice_code !== '') {
			$message_text = $this->get_notice_text($notice_code);

			if ($message_text !== '') {
				$class = $notice_status === 'success' ? 'jsonmaker-notice--success' : 'jsonmaker-notice--error';
				echo '<div class="jsonmaker-notice ' . esc_attr($class) . '">' . esc_html($message_text) . '</div>';
			}
		}
		$sections = [];
		$user = wp_get_current_user();
		$username_key = '';
		if ($user instanceof WP_User && $user->user_login !== '') {
			$username_key = (string) $user->user_login;
		} else {
			$username_key = 'user-' . get_current_user_id();
		}

		$instructions = $this->render_usage_instructions(true, $username_key);
		if ($instructions !== '') {
			$instructions_section = $this->render_collapsible_section(
				'instructions',
				__('Getting Started', 'fishdan-jsonmaker'),
				$instructions,
				true
			);
			if ($instructions_section !== '') {
				$sections[] = $instructions_section;
			}
		}

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
			$json_payload = [
				$username_key => $this->prepare_public_node($tree),
			];
			echo '<pre class="jsonmaker-json">';
			echo esc_html(wp_json_encode($json_payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
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
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sanitize_section_markup() applies wp_kses.
			echo $this->sanitize_section_markup($section_html);
		}

		echo '</div>';

		return (string) ob_get_clean();
	}

	public function render_checkout_shortcode(array $atts): string {
		$defaults = [
			'plan_id' => '',
			'licenses' => '1',
			'label' => __('Get Jsonmaker Basic', 'fishdan-jsonmaker'),
			'mode' => 'button',
			'height' => '780',
			'class' => '',
			'button_class' => 'btn btn-primary btn-lg',
		];
		$atts = shortcode_atts($defaults, $atts, 'jsonmaker_checkout');

		$plan_id = is_numeric($atts['plan_id']) ? (int) $atts['plan_id'] : 0;
		if ($plan_id <= 0) {
			$configured_plan_id = $this->get_configured_plan_id();
			if ($configured_plan_id > 0) {
				$plan_id = $configured_plan_id;
			}
		}
		$licenses = is_numeric($atts['licenses']) ? max(1, (int) $atts['licenses']) : 1;
		$label = trim($atts['label']) !== '' ? $atts['label'] : $defaults['label'];
		$mode = strtolower((string) $atts['mode']) === 'iframe' ? 'iframe' : 'button';
		$class_attr = trim($atts['class']);
		$button_class = trim($atts['button_class']) !== '' ? $atts['button_class'] : $defaults['button_class'];

		$checkout_url = $this->build_freemius_checkout_url($plan_id, $licenses);

		if ($checkout_url === '') {
			return '';
		}

		if ($mode === 'iframe') {
			$height = $this->sanitize_css_dimension($atts['height'], '780px');
			$container_classes = 'jsonmaker-checkout-embed';
			if ($class_attr !== '') {
				$container_classes .= ' ' . $class_attr;
			}

			return sprintf(
				'<div class="%1$s"><iframe src="%2$s" title="%3$s" loading="lazy" style="width:100%%;min-height:%4$s;border:0;" allowtransparency="true"></iframe><p class="jsonmaker-checkout-embed__fallback"><a href="%2$s" target="_blank" rel="noopener noreferrer">%5$s</a></p></div>',
				esc_attr($container_classes),
				esc_url($checkout_url),
				esc_attr__('Jsonmaker checkout', 'fishdan-jsonmaker'),
				esc_attr($height),
				esc_html__('Open checkout in a new tab', 'fishdan-jsonmaker')
			);
		}

		$button_classes = 'jsonmaker-checkout-button ' . $button_class;
		if ($class_attr !== '') {
			$button_classes .= ' ' . $class_attr;
		}

		return sprintf(
			'<a class="%1$s" href="%2$s" target="_blank" rel="noopener noreferrer">%3$s</a>',
			esc_attr(trim($button_classes)),
			esc_url($checkout_url),
			esc_html($label)
		);
	}

	public function render_admin_page(): void {
		if (! current_user_can(self::CAPABILITY)) {
			wp_die(esc_html__('You do not have permission to access this page.', 'fishdan-jsonmaker'));
		}

		global $title;
		$title = __('Jsonmaker Dashboard', 'fishdan-jsonmaker');

		echo '<div class="wrap jsonmaker-admin">';
		echo '<h1>' . esc_html__('fishdan Jsonmaker', 'fishdan-jsonmaker') . '</h1>';
		echo '<div class="card border-0 shadow-sm mb-4"><div class="card-body">';
		echo '<p class="mb-2">' . esc_html__('The Jsonmaker editor now lives on pages or posts where you place the [jsonmaker] shortcode. This admin view will soon power new checkout and account features.', 'fishdan-jsonmaker') . '</p>';
		echo '<p class="mb-0">' . esc_html__('Visit your published page to manage the tree. Use the form below to enter a license key emailed after purchase.', 'fishdan-jsonmaker') . '</p>';
		echo '</div></div>';
		$this->render_license_entry_panel();
		echo '</div>';
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
		echo '<form method="post" action="' . esc_url($redirect) . '" class="row g-3">';
		wp_nonce_field('jsonmaker_import', 'jsonmaker_import_nonce');
		echo '<input type="hidden" name="jsonmaker_action" value="import_json" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($redirect) . '" />';
		$schema_url = plugins_url('jsonmaker.schema.json', __FILE__);
		$schema_link = '<a href="' . esc_url($schema_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('fishdan Jsonmaker schema', 'fishdan-jsonmaker') . '</a>';
		$description_text = sprintf(
			/* translators: %s is a link to the fishdan Jsonmaker schema documentation. */
			__('Paste JSON that matches the %s to replace the tree or append a branch.', 'fishdan-jsonmaker'),
			$schema_link
		);

		echo '<div class="col-12">';
		echo '<p class="jsonmaker-import__intro text-muted small mb-2">' . wp_kses(
			$description_text,
			[
				'a' => [
					'href' => [],
					'target' => [],
					'rel' => [],
				],
			]
		) . '</p>';
		echo '</div>';
		echo '<div class="col-12">';
		echo '<span class="form-label d-block">' . esc_html__('Mode', 'fishdan-jsonmaker') . '</span>';
		echo '<div class="form-check">';
		echo '<input class="form-check-input" type="radio" name="jsonmaker_import_mode" value="append" id="jsonmaker-import-mode-append" checked />';
		echo '<label class="form-check-label" for="jsonmaker-import-mode-append">' . esc_html__('Append under an existing node', 'fishdan-jsonmaker') . '</label>';
		echo '</div>';
		echo '<div class="form-check">';
		echo '<input class="form-check-input" type="radio" name="jsonmaker_import_mode" value="replace" id="jsonmaker-import-mode-replace" />';
		echo '<label class="form-check-label" for="jsonmaker-import-mode-replace">' . esc_html__('Replace entire tree', 'fishdan-jsonmaker') . '</label>';
		echo '</div>';
		echo '</div>';
		if (! empty($options)) {
			echo '<div class="col-12 col-md-6">';
			echo '<label for="' . esc_attr($target_id) . '" class="form-label">' . esc_html__('Append target', 'fishdan-jsonmaker') . '</label>';
			echo '<select id="' . esc_attr($target_id) . '" class="form-select" name="jsonmaker_import_target" data-jsonmaker-import-target required>';
			echo '<option value="" disabled selected>' . esc_html__('Select a node...', 'fishdan-jsonmaker') . '</option>';
			foreach ($options as $option) {
				echo '<option value="' . esc_attr($option['slug']) . '">' . esc_html($option['label']) . '</option>';
			}
			echo '</select>';
			echo '<div class="jsonmaker-import__hint form-text">' . esc_html__('Used when Mode is set to Append.', 'fishdan-jsonmaker') . '</div>';
			echo '</div>';
		}
		echo '<div class="col-12">';
		echo '<label for="' . esc_attr($textarea_id) . '" class="form-label">' . esc_html__('JSON payload', 'fishdan-jsonmaker') . '</label>';
		echo '<textarea id="' . esc_attr($textarea_id) . '" class="form-control" name="jsonmaker_payload" rows="10" required></textarea>';
		echo '</div>';
		echo '<div class="col-12 d-flex gap-2">';
		echo '<button type="submit" class="btn btn-primary">' . esc_html__('Import JSON', 'fishdan-jsonmaker') . '</button>';
		echo '</div>';
		echo '</form>';
		echo '</div>';
	}

	private function render_login_register_prompt(string $notice_code, string $notice_status): string {
		$current_url = $this->get_current_url();
		$login_url = wp_login_url($current_url);

		ob_start();
		echo '<div class="jsonmaker-wrapper container-fluid px-0">';
		echo '<div class="jsonmaker-auth card shadow-sm border-0">';
		echo '<div class="card-body p-4">';
		if ($notice_code !== '') {
			$message_text = $this->get_notice_text($notice_code);
			if ($message_text !== '') {
				$class = $notice_status === 'success' ? 'jsonmaker-notice--success' : 'jsonmaker-notice--error';
				echo '<div class="jsonmaker-notice ' . esc_attr($class) . '">' . esc_html($message_text) . '</div>';
			}
		}

		echo '<div class="jsonmaker-auth__cta d-flex flex-column flex-sm-row align-items-start align-items-sm-center gap-3 mb-4">';
		echo '<p class="mb-0">' . esc_html__('Already have an account? Jump back into your JSON tree using the login button. Need one? Register below.', 'fishdan-jsonmaker') . '</p>';
		echo '<a class="btn btn-outline-primary btn-lg" href="' . esc_url($login_url) . '">' . esc_html__('Log in to your account', 'fishdan-jsonmaker') . '</a>';
		echo '</div>';

		$instructions = $this->render_usage_instructions(false);
		if ($instructions !== '') {
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- render_usage_instructions() returns escaped markup.
			echo $instructions;
		}

		echo '<form method="post" action="' . esc_url($current_url) . '" class="jsonmaker-register-form row g-3 mt-3">';
		wp_nonce_field('jsonmaker_register_user', 'jsonmaker_register_nonce');
		echo '<input type="hidden" name="jsonmaker_action" value="register_json_user" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($current_url) . '" />';
		echo '<div class="col-12">';
		echo '<label class="form-label" for="jsonmaker-register-username">' . esc_html__('Username', 'fishdan-jsonmaker') . '</label>';
		echo '<input type="text" id="jsonmaker-register-username" class="form-control" name="jsonmaker_username" autocomplete="username" required />';
		echo '</div>';
		echo '<div class="col-12">';
		echo '<label class="form-label" for="jsonmaker-register-email">' . esc_html__('Email address', 'fishdan-jsonmaker') . '</label>';
		echo '<input type="email" id="jsonmaker-register-email" class="form-control" name="jsonmaker_email" autocomplete="email" required />';
		echo '</div>';
		echo '<div class="col-12">';
		echo '<label class="form-label" for="jsonmaker-register-password">' . esc_html__('Password', 'fishdan-jsonmaker') . '</label>';
		echo '<input type="password" id="jsonmaker-register-password" class="form-control" name="jsonmaker_password" autocomplete="new-password" required />';
		echo '</div>';
		echo '<div class="col-12 d-flex flex-column align-items-start gap-2">';
		echo '<p class="text-muted small mb-0">' . esc_html__('New accounts use the JSON role so you can curate your own data immediately.', 'fishdan-jsonmaker') . '</p>';
		echo '<button type="submit" class="btn btn-primary">' . esc_html__('Create account', 'fishdan-jsonmaker') . '</button>';
		echo '</div>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '</div>';

		return (string) ob_get_clean();
	}

	private function render_usage_instructions(bool $logged_in, string $username_key = ''): string {
		$extension_url = 'https://chromewebstore.google.com/detail/hdailbkmbdcililnbemepacdkfdkbhco?utm_source=item-share-cb';
		$extension_link = '<a href="' . esc_url($extension_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Subscribed Toolbar Chrome extension', 'fishdan-jsonmaker') . '</a>';
		$example_url = '';

		if ($logged_in && $username_key !== '') {
			$example_url = home_url('/json/' . rawurlencode($username_key) . '/popular.json');
		}

		$example_link = $example_url !== '' ? '<a href="' . esc_url($example_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($example_url) . '</a>' : '';

		ob_start();
		echo '<div class="jsonmaker-instructions">';

		if ($logged_in) {
			echo '<p class="mb-3">' . esc_html__('Build a living library of links that instantly publishes as JSON feeds tied to your account.', 'fishdan-jsonmaker') . '</p>';
			echo '<ul class="list-group list-group-flush mb-3">';
			echo '<li class="list-group-item px-0 bg-transparent">' . esc_html__('Use the Add, Edit, and Delete controls to organise links into folders and subfolders right on the page.', 'fishdan-jsonmaker') . '</li>';
			if ($example_url !== '') {
				echo '<li class="list-group-item px-0 bg-transparent">' . wp_kses_post(sprintf(
					/* translators: %s is an example JSON URL. */
					__('Every branch becomes a personalized endpoint such as %s — notice your username baked into the path.', 'fishdan-jsonmaker'),
					'<code>' . $example_link . '</code>'
				)) . '</li>';
			} else {
				echo '<li class="list-group-item px-0 bg-transparent">' . esc_html__('Every branch publishes to /json/<your-username>/<node>.json so the URLs are unique to you.', 'fishdan-jsonmaker') . '</li>';
			}
			echo '<li class="list-group-item px-0 bg-transparent">' . esc_html__('Share the JSON from any level with teammates, embed it in dashboards, or automate against it — every node is portable.', 'fishdan-jsonmaker') . '</li>';
			echo '<li class="list-group-item px-0 bg-transparent">' . wp_kses_post(sprintf(
				/* translators: %s is a link to the Chrome extension. */
				__('Add the feed to the %s to surface your curated links inside <b>ANY</b> browser toolbar.', 'fishdan-jsonmaker'),
				$extension_link
			)) . '</li>';
			echo '</ul>';
			echo '<p class="small text-muted mb-0">' . esc_html__('Keep iterating on the tree whenever inspiration strikes — updates are reflected everywhere the JSON is consumed.', 'fishdan-jsonmaker') . '</p>';
		} else {
			echo '<p class="mb-3">' . esc_html__('Register to start building a shareable JSON library of the links you rely on most.', 'fishdan-jsonmaker') . '</p>';
			echo '<ul class="list-group list-group-flush mb-3">';
			echo '<li class="list-group-item px-0 bg-transparent">' . esc_html__('Your menu begins with a curated “Popular” folder that you can expand and reshape instantly.', 'fishdan-jsonmaker') . '</li>';
			echo '<li class="list-group-item px-0 bg-transparent">' . esc_html__('Every JSON URL is personalised with your username, so you control exactly what you share and with whom.', 'fishdan-jsonmaker') . '</li>';
			echo '<li class="list-group-item px-0 bg-transparent">' . esc_html__('Send any branch to friends or teammates — they receive the live JSON feed of that portion of your library.', 'fishdan-jsonmaker') . '</li>';
			echo '<li class="list-group-item px-0 bg-transparent">' . wp_kses_post(sprintf(
				/* translators: %s is a link to the Chrome extension. */
				__('Hook it into the %s to keep those links pinned to your toolbar with zero extra effort.', 'fishdan-jsonmaker'),
				$extension_link
			)) . '</li>';
			echo '</ul>';
		}

			echo '</div>';

			return (string) ob_get_clean();
		}

	private function render_license_entry_panel(): void {
		$nonce = wp_create_nonce('jsonmaker_activate_license');
		$current_url = $this->get_current_url();

		$license_details = $this->get_license_status_details();
		$buy_url = $this->build_freemius_checkout_url(self::PAID_PLAN_ID, 1);

		echo '<div class="card border-0 shadow-sm jsonmaker-license-card">';
		echo '<div class="card-body">';
		echo '<div class="jsonmaker-license-card__header d-flex flex-wrap align-items-center justify-content-between mb-3">';
		echo '<div>';
		if ($license_details['has_license']) {
			echo '<div class="text-success fw-semibold">' . esc_html($license_details['summary']) . '</div>';
			if ($license_details['expires_text'] !== '') {
				echo '<div class="text-muted small">' . esc_html($license_details['expires_text']) . '</div>';
			}
		} else {
			echo '<div class="text-muted">' . esc_html($license_details['summary']) . '</div>';
		}
		echo '</div>';
		if ($buy_url !== '') {
			echo '<a class="button button-secondary jsonmaker-buy-button" href="' . esc_url($buy_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Buy Jsonmaker Basic', 'fishdan-jsonmaker') . '</a>';
		}
		echo '</div>';
		echo '<h2 class="h5 mb-3">' . esc_html__('Enter License Key', 'fishdan-jsonmaker') . '</h2>';
		echo '<p class="text-muted">' . esc_html__('Paste the license code emailed to you after purchase to enable premium features.', 'fishdan-jsonmaker') . '</p>';
		echo '<form id="jsonmaker-license-form">';
		echo '<div class="mb-3">';
		echo '<label class="form-label" for="jsonmaker-license-key">' . esc_html__('License Key', 'fishdan-jsonmaker') . '</label>';
		echo '<input type="text" id="jsonmaker-license-key" class="regular-text" name="license_key" required />';
		echo '</div>';
		echo '<input type="hidden" name="action" value="jsonmaker_activate_license" />';
		echo '<input type="hidden" name="jsonmaker_license_nonce" value="' . esc_attr($nonce) . '" />';
		echo '<button type="submit" class="button button-primary">' . esc_html__('Activate License', 'fishdan-jsonmaker') . '</button>';
		echo '<span id="jsonmaker-license-status" class="ms-2"></span>';
		echo '</form>';
		echo '</div>';
		echo '</div>';
		echo '<script type="text/javascript">';
		echo 'document.addEventListener("DOMContentLoaded",function(){';
		echo 'const form=document.getElementById("jsonmaker-license-form");';
		echo 'if(!form){return;}';
		echo 'const status=document.getElementById("jsonmaker-license-status");';
		echo 'form.addEventListener("submit",function(e){e.preventDefault();if(status){status.textContent="' . esc_js(__('Activating…', 'fishdan-jsonmaker')) . '";status.className="text-muted";}const data=new FormData(form);fetch(ajaxurl,{method:"POST",credentials:"same-origin",body:data}).then(res=>res.json()).then(resp=>{if(!status){return;}if(resp.success){status.textContent="' . esc_js(__('License activated!', 'fishdan-jsonmaker')) . '";status.className="text-success";form.reset();}else{status.textContent=(resp.data&&resp.data.message)?resp.data.message:"' . esc_js(__('Unable to activate license.', 'fishdan-jsonmaker')) . '";status.className="text-error";}}).catch(()=>{if(status){status.textContent="' . esc_js(__('Request failed. Please try again.', 'fishdan-jsonmaker')) . '";status.className="text-error";}});});';
		echo '});';
		echo '</script>';
	}

	/**
	 * @return array{has_license: bool, summary: string, expires_text: string}
	 */
	private function get_license_status_details(): array {
		$details = [
			'has_license' => false,
			'summary' => __('No active license detected. Enter your key below to unlock premium features.', 'fishdan-jsonmaker'),
			'expires_text' => '',
		];

		$fs = jm_fs();

		if (! is_object($fs) || ! method_exists($fs, '_get_license')) {
			return $details;
		}

		$license = $fs->_get_license();

		if (! $license instanceof FS_Plugin_License) {
			return $details;
		}

		$plan_label = $this->describe_plan_name((int) ($license->plan_id ?? 0));
		$details['has_license'] = true;
		$details['summary'] = sprintf(
			/* translators: %s is the active plan label. */
			__('Active license: %s', 'fishdan-jsonmaker'),
			$plan_label
		);

		if (method_exists($license, 'is_lifetime') && $license->is_lifetime()) {
			$details['expires_text'] = __('Lifetime license', 'fishdan-jsonmaker');
		} else {
			$details['expires_text'] = $this->format_license_expiration($license->expiration ?? '');
		}

		return $details;
	}

	private function describe_plan_name(int $plan_id): string {
		if ($plan_id === self::PAID_PLAN_ID) {
			return __('Jsonmaker Basic', 'fishdan-jsonmaker');
		}

		if ($plan_id === self::FREE_PLAN_ID) {
			return __('Free Plan', 'fishdan-jsonmaker');
		}

		if ($plan_id > 0) {
			return sprintf(
				/* translators: %d is the plan ID. */
				__('Plan #%d', 'fishdan-jsonmaker'),
				$plan_id
			);
		}

		return __('Unknown Plan', 'fishdan-jsonmaker');
	}

	private function format_license_expiration(?string $expiration): string {
		if ($expiration === null || $expiration === '') {
			return '';
		}

		$timestamp = strtotime($expiration);

		if ($timestamp === false) {
			return $expiration;
		}

		$date = date_i18n(get_option('date_format'), $timestamp);

		return sprintf(
			/* translators: %s is a formatted date. */
			__('Renews on %s', 'fishdan-jsonmaker'),
			$date
		);
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

		$output = '<div class="jsonmaker-section card shadow-sm border-0" data-jsonmaker-section="' . esc_attr($section_key) . '" data-jsonmaker-section-default="' . esc_attr($default_attr) . '">';
		$output .= '<div class="jsonmaker-section__header card-header bg-light">';
		$indicator_symbol = $default_open ? '−' : '+';
		$output .= '<button type="button" id="' . esc_attr($toggle_id) . '" class="jsonmaker-section__toggle btn btn-link text-decoration-none w-100 d-flex align-items-center gap-3" data-jsonmaker-section-toggle="' . esc_attr($section_key) . '" aria-controls="' . esc_attr($content_id) . '" aria-expanded="' . esc_attr($aria_expanded) . '">';
		$output .= '<span class="jsonmaker-section__indicator badge rounded-pill bg-primary-subtle text-primary-emphasis" data-jsonmaker-section-indicator aria-hidden="true">' . esc_html($indicator_symbol) . '</span>';
		$output .= '<span class="jsonmaker-section__title fw-semibold">' . esc_html($title) . '</span>';
		$output .= '<span class="flex-grow-1"></span>';
		$output .= '</button>';
		$output .= '</div>';
		$output .= '<div id="' . esc_attr($content_id) . '" class="jsonmaker-section__content card-body">';
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
		$node_classes = ['jsonmaker-node'];
		if ($has_children) {
			$node_classes[] = 'jsonmaker-node--has-children';
		}
		$node_attrs = '';
		if ($has_children && $slug !== '') {
			$node_attrs = ' data-jsonmaker-node="' . esc_attr($slug) . '"';
		}

		echo '<div class="' . esc_attr(implode(' ', $node_classes)) . '"' . $node_attrs . '>';

		echo '<div class="jsonmaker-node__title">';
		if ($has_children) {
			echo $this->render_node_toggle_button($slug);
		} else {
			echo '<span class="jsonmaker-node-toggle-placeholder"></span>';
		}
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

		$actions_html = '';
		ob_start();
		if ($can_manage && $slug !== '') {
			printf(
				'<button type="button" class="btn btn-sm btn-outline-primary jsonmaker-add-button" data-jsonmaker-target="%1$s"%3$s>%2$s</button>',
				esc_attr('jsonmaker-form-' . $slug),
				esc_html__('Add Node', 'fishdan-jsonmaker'),
				$node_has_value ? ' data-jsonmaker-has-value="1"' : ''
			);
			printf(
				' <button type="button" class="btn btn-sm btn-outline-secondary jsonmaker-edit-button" data-jsonmaker-target="%1$s">%2$s</button>',
				esc_attr('jsonmaker-edit-form-' . $slug),
				esc_html__('Edit', 'fishdan-jsonmaker')
			);
			$this->render_delete_form($slug, $has_children, $current_url);
		}
		$actions_html = (string) ob_get_clean();

		$view_button_html = $this->render_view_node_button($slug);

		echo '<span class="jsonmaker-node__actions d-inline-flex flex-wrap align-items-center gap-2">';
		echo $actions_html;
		echo $view_button_html;
		echo '</span>';

		echo '</div>';

		if ($has_children) {
			$children_attrs = 'class="jsonmaker-node__children"';
			if ($slug !== '') {
				$children_attrs .= ' data-jsonmaker-node-children hidden';
			}
			echo '<div ' . $children_attrs . '>';
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

	private function render_view_node_button(string $slug): string {
		if ($slug === '') {
			return '';
		}

		$current_user = wp_get_current_user();
		$username = $current_user instanceof WP_User ? (string) $current_user->user_login : '';
		$path = $username !== '' ? '/json/' . rawurlencode($username) . '/' . rawurlencode($slug) . '.json' : '/json/' . rawurlencode($slug) . '.json';
		$api_url = home_url($path);

		return ' <a class="btn btn-sm btn-outline-info jsonmaker-view-button" href="' . esc_url($api_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('View Node', 'fishdan-jsonmaker') . '</a>';
	}

	private function render_node_toggle_button(string $slug): string {
		if ($slug === '') {
			return '';
		}

		$open_label = '+';
		$close_label = '−';

		return sprintf(
			' <button type="button" class="jsonmaker-node-toggle" data-jsonmaker-node-toggle data-jsonmaker-label-open="%1$s" data-jsonmaker-label-close="%2$s" aria-expanded="false" aria-label="%4$s">%3$s</button>',
			esc_attr($open_label),
			esc_attr($close_label),
			esc_html($open_label),
			esc_attr__('Toggle folder', 'fishdan-jsonmaker')
		);
	}

	private function render_add_form(string $parent_slug, ?string $redirect = null): void {
		if ($parent_slug === '') {
			return;
		}

		$form_id = 'jsonmaker-form-' . $parent_slug;
		$title_field_id = 'jsonmaker-title-' . $parent_slug;
		$value_field_id = 'jsonmaker-value-' . $parent_slug;

		$current_url = $redirect ?? $this->get_current_url();

		echo '<div id="' . esc_attr($form_id) . '" class="jsonmaker-add-form mt-3" hidden>';
		echo '<form method="post" action="' . esc_url($current_url) . '" class="card border-0 shadow-sm">';
		echo '<div class="card-body row g-3">';
		wp_nonce_field('jsonmaker_add_node', 'jsonmaker_nonce');
		echo '<input type="hidden" name="jsonmaker_action" value="add_node" />';
		echo '<input type="hidden" name="jsonmaker_parent" value="' . esc_attr($parent_slug) . '" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($current_url) . '" />';
		echo '<div class="col-12">';
		echo '<label class="form-label" for="' . esc_attr($title_field_id) . '">' . esc_html__('Title', 'fishdan-jsonmaker') . '</label>';
		echo '<input type="text" id="' . esc_attr($title_field_id) . '" class="form-control form-control-sm" name="jsonmaker_title" required />';
		echo '</div>';
		echo '<div class="col-12">';
		echo '<label class="form-label" for="' . esc_attr($value_field_id) . '">' . esc_html__('Value (leave blank to create a container)', 'fishdan-jsonmaker') . '</label>';
		echo '<input type="text" id="' . esc_attr($value_field_id) . '" class="form-control form-control-sm" name="jsonmaker_value" />';
		echo '</div>';
		echo '<div class="col-12">';
		echo '<button type="submit" class="btn btn-primary btn-sm">' . esc_html__('Add Child', 'fishdan-jsonmaker') . '</button>';
		echo '</div>';
		echo '</div>';
		echo '</form>';
		echo '</div>';
	}

	private function render_edit_form(string $target_slug, string $current_title, ?string $redirect = null): void {
		if ($target_slug === '') {
			return;
		}

		$form_id = 'jsonmaker-edit-form-' . $target_slug;
		$title_field_id = 'jsonmaker-edit-title-' . $target_slug;
		$current_url = $redirect ?? $this->get_current_url();

		echo '<div id="' . esc_attr($form_id) . '" class="jsonmaker-edit-form mt-3" hidden>';
		echo '<form method="post" action="' . esc_url($current_url) . '" class="card border-0 shadow-sm">';
		echo '<div class="card-body row g-3">';
		wp_nonce_field('jsonmaker_edit_node', 'jsonmaker_edit_nonce');
		echo '<input type="hidden" name="jsonmaker_action" value="edit_node" />';
		echo '<input type="hidden" name="jsonmaker_target" value="' . esc_attr($target_slug) . '" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($current_url) . '" />';
		echo '<div class="col-12">';
		echo '<label class="form-label" for="' . esc_attr($title_field_id) . '">' . esc_html__('Title', 'fishdan-jsonmaker') . '</label>';
		echo '<input type="text" id="' . esc_attr($title_field_id) . '" class="form-control form-control-sm" name="jsonmaker_title" value="' . esc_attr($current_title) . '" required />';
		echo '</div>';
		echo '<div class="col-12">';
		echo '<button type="submit" class="btn btn-primary btn-sm">' . esc_html__('Save Title', 'fishdan-jsonmaker') . '</button>';
		echo '</div>';
		echo '</div>';
		echo '</form>';
		echo '</div>';
	}

	private function render_delete_form(string $target_slug, bool $has_children, string $redirect): void {
		if ($target_slug === '' || $redirect === '') {
			return;
		}

		echo ' <form method="post" action="' . esc_url($redirect) . '" class="jsonmaker-delete-form d-inline-flex align-items-center">';
		wp_nonce_field('jsonmaker_delete_node', 'jsonmaker_delete_nonce', false);
		echo '<input type="hidden" name="jsonmaker_action" value="delete_node" />';
		echo '<input type="hidden" name="jsonmaker_target" value="' . esc_attr($target_slug) . '" />';
		echo '<input type="hidden" name="jsonmaker_redirect" value="' . esc_attr($redirect) . '" />';
		echo '<button type="submit" class="btn btn-sm btn-outline-danger jsonmaker-delete-button ms-1"';
		if ($has_children) {
			echo ' data-jsonmaker-has-children="1" data-jsonmaker-message="' . esc_attr__('Remove child nodes before deleting this node.', 'fishdan-jsonmaker') . '"';
		}
		echo '>';
		echo esc_html__('Delete Node', 'fishdan-jsonmaker');
		echo '</button>';
		echo '</form>';
	}

	private function get_tree(?int $user_id = null): array {
		if ($user_id === null) {
			$user_id = get_current_user_id();
		}

		if ($user_id > 0) {
			$tree = get_user_meta($user_id, self::USER_META_KEY, true);

			if (! is_array($tree) || empty($tree)) {
				$tree = $this->create_initial_tree_for_user($user_id);
				$this->save_tree($tree, $user_id);
			}

			return is_array($tree) ? $tree : [];
		}

		self::ensure_initial_tree();
		$tree = get_option(self::OPTION_NAME);

		return is_array($tree) ? $tree : [];
	}

	private function save_tree(array $tree, ?int $user_id = null): void {
		if ($user_id === null) {
			$user_id = get_current_user_id();
		}

		if ($user_id > 0) {
			update_user_meta($user_id, self::USER_META_KEY, $tree);

			return;
		}

		update_option(self::OPTION_NAME, $tree);
	}

	private function create_initial_tree_for_user(int $user_id): array {
		return self::get_default_tree_template_static();
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

	private function maybe_ensure_about_links(array &$tree): void {
		if (! $this->is_free_account()) {
			return;
		}

		if (! isset($tree['children']) || ! is_array($tree['children'])) {
			$tree['children'] = [];
		}

		$about_index = null;
		$about_key = $this->normalize_title_key('about');

		foreach ($tree['children'] as $index => $child) {
			$child_slug = $child['slug'] ?? '';
			$child_key = $this->normalize_title_key($child['title'] ?? '');

			if ($child_slug === 'about' || ($child_key !== '' && $child_key === $about_key)) {
				$about_index = $index;
				break;
			}
		}

		if ($about_index === null) {
			$tree['children'][] = [
				'title' => 'about',
				'slug' => 'about',
				'children' => [],
			];
			$about_index = count($tree['children']) - 1;
		}

		$tree['children'][$about_index]['title'] = 'about';
		$tree['children'][$about_index]['slug'] = 'about';

		unset($tree['children'][$about_index]['value'], $tree['children'][$about_index]['url']);

		if (! isset($tree['children'][$about_index]['children']) || ! is_array($tree['children'][$about_index]['children'])) {
			$tree['children'][$about_index]['children'] = [];
		}

		$about_children = &$tree['children'][$about_index]['children'];
		$host_url = esc_url_raw('https://wordpress.org/plugins/fishdan-jsonmaker/');
		$this->ensure_about_link_child(
			$about_children,
			'host-your-own-toolbar',
			'Host Your Own Toolbar',
			$host_url
		);

		$source_url = $this->get_toolbar_source_url_from_request();
		if ($source_url !== '') {
			$this->ensure_about_link_child(
				$about_children,
				'edit-your-toolbar-source',
				'Edit your toolbar source',
				$source_url
			);
		}
	}

	private function ensure_about_link_child(array &$children, string $slug, string $title, string $value): void {
		$title_key = $this->normalize_title_key($title);
		$clean_value = esc_url_raw($value);

		if ($clean_value === '') {
			return;
		}

		foreach ($children as &$child) {
			$child_slug = $child['slug'] ?? '';
			$child_key = $this->normalize_title_key($child['title'] ?? '');

			if ($child_slug === $slug || ($child_key !== '' && $child_key === $title_key)) {
				$child['title'] = $title;
				$child['slug'] = $slug;
				$child['value'] = $clean_value;
				unset($child['url']);
				$child['children'] = [];

				return;
			}
		}

		$children[] = [
			'title' => $title,
			'slug' => $slug,
			'value' => $clean_value,
			'children' => [],
		];
	}

	private function is_free_account(): bool {
		$fs = jm_fs();

		if (! is_object($fs)) {
			return true;
		}

		if (method_exists($fs, 'is_free_plan')) {
			return (bool) $fs->is_free_plan();
		}

		return true;
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

		$redirect_raw = $this->get_post_string('jsonmaker_redirect');
		if ($redirect_raw !== null) {
			$redirect = esc_url_raw($redirect_raw);
		}

		if ($redirect === '') {
			$redirect = wp_get_referer() ?: '';
		}

		if ($redirect === '') {
			$current_url = $this->get_current_url();
			$redirect = $current_url !== '' ? $current_url : home_url();
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
			case 'register_missing_fields':
				return __('Please complete all registration fields.', 'fishdan-jsonmaker');
			case 'register_invalid_email':
				return __('Enter a valid email address.', 'fishdan-jsonmaker');
			case 'register_username_exists':
				return __('That username is already taken. Choose a different one.', 'fishdan-jsonmaker');
			case 'register_email_exists':
				return __('An account with that email already exists.', 'fishdan-jsonmaker');
			case 'register_failed':
				return __('We could not create your account. Try again later.', 'fishdan-jsonmaker');
			case 'register_success':
				return __('Account created. You are now signed in.', 'fishdan-jsonmaker');
			default:
				return '';
		}
	}

	private function maybe_print_assets(): void {
		if ($this->printed_assets) {
			return;
		}

		$this->printed_assets = true;

		$bootstrap_css = plugins_url('assets/vendor/bootstrap/css/bootstrap.min.css', __FILE__);
		$bootstrap_js = plugins_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js', __FILE__);

		wp_enqueue_style(
			'jsonmaker-bootstrap-css',
			$bootstrap_css,
			[],
			'5.3.3'
		);
		wp_enqueue_script(
			'jsonmaker-bootstrap-js',
			$bootstrap_js,
			[],
			'5.3.3',
			true
		);

		if (! wp_style_is('jsonmaker-inline', 'registered')) {
			wp_register_style('jsonmaker-inline', false, [], JSONMAKER_VERSION);
		}
		wp_enqueue_style('jsonmaker-inline');
		$style_lines = [
			'.jsonmaker-wrapper {font-family: var(--bs-body-font-family);}',
			'.jsonmaker-json {font-family: var(--bs-font-monospace); background: var(--bs-light); padding: 1rem; border: 1px solid var(--bs-border-color); border-radius: 0.5rem; overflow:auto;}',
			'.jsonmaker-node {border-left: 3px solid rgba(13,110,253,.35); margin-left: 1rem; padding-left: 1rem; margin-top: 0.75rem;}',
			'.jsonmaker-node__title {display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap;}',
			'.jsonmaker-node__label {font-weight: 600;}',
			'.jsonmaker-node__value {font-family: var(--bs-font-monospace);}',
			'.jsonmaker-node__title a {text-decoration: none;}',
			'.jsonmaker-node__children {margin-top: 0.5rem;}',
			'.jsonmaker-node__children[hidden] {display: none !important;}',
			'.jsonmaker-tree .btn {min-width: 6.5rem;}',
			'.jsonmaker-delete-form {display: inline-flex; margin: 0;}',
			'.jsonmaker-node__actions {margin-left: auto;}',
			'.jsonmaker-node-toggle {border: 1px solid var(--bs-border-color); background: var(--bs-light); color: inherit; width: 1.5rem; height: 1.5rem; border-radius: 999px; font-weight: 600; line-height: 1; display: inline-flex; align-items: center; justify-content: center; padding: 0; cursor: pointer;}',
			'.jsonmaker-node-toggle:focus {outline: 2px solid var(--bs-primary); outline-offset: 2px;}',
			'.jsonmaker-node-toggle-placeholder {display: inline-block; width: 1.5rem;}',
			'.jsonmaker-section {margin-bottom: 1rem;}',
			'.jsonmaker-section__header {padding: 0;}',
			'.jsonmaker-section__toggle {display: flex; align-items: center; justify-content: space-between; width: 100%; background: none; border: 0; padding: 0.75rem 1rem; font-size: 1rem; cursor: pointer; color: inherit;}',
			'.jsonmaker-section__indicator {font-size: 1.25rem; line-height: 1; width: 1.5rem; height: 1.5rem; display: inline-flex; align-items: center; justify-content: center;}',
			'.jsonmaker-section__content {padding: 1rem;}',
			'.jsonmaker-import textarea {font-family: var(--bs-font-monospace); min-height: 10rem;}',
			'.jsonmaker-import__hint {font-size: 0.85rem;}',
			'.jsonmaker-notice {margin-bottom: 1rem; padding: 0.75rem 1rem; border-radius: 0.5rem; border: 1px solid transparent; font-weight: 600;}',
			'.jsonmaker-notice--success {background: #d1e7dd; border-color: #badbcc; color: #0f5132;}',
			'.jsonmaker-notice--error {background: #f8d7da; border-color: #f5c2c7; color: #842029;}',
			'.jsonmaker-instructions ul {padding-left: 1rem;}',
			'.jsonmaker-auth {max-width: 36rem;}',
			'.jsonmaker-checkout-button {display: inline-flex; align-items: center; justify-content: center;}',
			'.jsonmaker-checkout-embed iframe {border-radius: 0.75rem; background-color: #fff;}',
			'.jsonmaker-checkout-embed__fallback {margin-top: 0.5rem; font-size: 0.85rem;}',
			'.jsonmaker-checkout-panel {background: #fff;}',
			'.jsonmaker-checkout-panel .jsonmaker-checkout-embed {margin-top: 1rem;}',
			'.jsonmaker-license-card {background: #fff;}',
			'.jsonmaker-license-card__header {gap: 1rem;}',
			'.jsonmaker-buy-button {margin-left: auto;}',
		];
		wp_add_inline_style('jsonmaker-inline', implode("\n", $style_lines));

		if (! wp_script_is('jsonmaker-inline', 'registered')) {
			wp_register_script('jsonmaker-inline', false, [], JSONMAKER_VERSION, true);
		}
		wp_enqueue_script('jsonmaker-inline');
		$confirm_replace = __('Confirm you want to erase the entire tree and replace it?', 'fishdan-jsonmaker');
		$child_value_warning = __('Adding a child to this node will delete its current value, so it becomes a folder and not a link in the bookmark. Continue?', 'fishdan-jsonmaker');
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
			"const jsonmakerNodeCookiePrefix = 'jsonmaker_node_';",
			"function jsonmakerSetNodeState(slug, isOpen) {",
			"\tif (!slug) {",
			"\t\treturn;",
			"\t}",
			"\tconst maxAge = 60 * 60 * 24 * 365;",
			"\tdocument.cookie = jsonmakerNodeCookiePrefix + slug + '=' + (isOpen ? 'open' : 'closed') + '; path=/; max-age=' + maxAge;",
			"}",
			"function jsonmakerGetNodeState(slug) {",
			"\tif (!slug) {",
			"\t\treturn null;",
			"\t}",
			"\tconst pattern = new RegExp('(?:^|; )' + jsonmakerNodeCookiePrefix + slug + '=([^;]*)');",
			"\tconst match = document.cookie.match(pattern);",
			"\treturn match ? match[1] : null;",
			"}",
			"function jsonmakerSetNodeDomState(node, isOpen) {",
			"\tif (!node) {",
			"\t\treturn;",
			"\t}",
			"\tconst children = node.querySelector('[data-jsonmaker-node-children]');",
			"\tconst toggle = node.querySelector('[data-jsonmaker-node-toggle]');",
			"\tif (!children || !toggle) {",
			"\t\treturn;",
			"\t}",
			"\tconst openLabel = toggle.dataset.jsonmakerLabelOpen || '+';",
			"\tconst closeLabel = toggle.dataset.jsonmakerLabelClose || '−';",
			"\tif (isOpen) {",
			"\t\tchildren.removeAttribute('hidden');",
			"\t\ttoggle.textContent = closeLabel;",
			"\t\ttoggle.dataset.jsonmakerNodeState = 'open';",
			"\t\ttoggle.setAttribute('aria-expanded', 'true');",
			"\t\tnode.classList.add('jsonmaker-node--open');",
			"\t} else {",
			"\t\tchildren.setAttribute('hidden', '');",
			"\t\ttoggle.textContent = openLabel;",
			"\t\ttoggle.dataset.jsonmakerNodeState = 'closed';",
			"\t\ttoggle.setAttribute('aria-expanded', 'false');",
			"\t\tnode.classList.remove('jsonmaker-node--open');",
			"\t}",
			"}",
			"function jsonmakerApplyNodeState(node) {",
			"\tconst slug = node.dataset.jsonmakerNode;",
			"\tif (!slug) {",
			"\t\treturn;",
			"\t}",
			"\tconst stored = jsonmakerGetNodeState(slug);",
			"\tjsonmakerSetNodeDomState(node, stored === 'open');",
			"}",
			"function jsonmakerCloseSiblingForms(currentForm) {",
			"\tif (!currentForm) {",
			"\t\treturn;",
			"\t}",
			"\tconst node = currentForm.closest('[data-jsonmaker-node]');",
			"\tif (!node) {",
			"\t\treturn;",
			"\t}",
			"\tnode.querySelectorAll('.jsonmaker-add-form, .jsonmaker-edit-form').forEach(function (form) {",
			"\t\tif (form !== currentForm) {",
			"\t\t\tform.setAttribute('hidden', '');",
			"\t\t}",
			"\t});",
			"}",
			"document.addEventListener('DOMContentLoaded', function () {",
			"\tdocument.querySelectorAll('[data-jsonmaker-section]').forEach(function (section) {",
			"\t\tjsonmakerApplySectionState(section);",
			"\t});",
			"\tdocument.querySelectorAll('[data-jsonmaker-node]').forEach(function (node) {",
			"\t\tjsonmakerApplyNodeState(node);",
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
			'const jsonmakerConfirmReplace = ' . wp_json_encode($confirm_replace) . ';',
			'const jsonmakerAddChildValueWarning = ' . wp_json_encode($child_value_warning) . ';',
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
			"\t\t\tjsonmakerCloseSiblingForms(form);",
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
			"\t\t\tjsonmakerCloseSiblingForms(form);",
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
			"\tconst nodeToggle = event.target.closest('[data-jsonmaker-node-toggle]');",
			"\tif (nodeToggle) {",
			"\t\tevent.preventDefault();",
			"\t\tconst node = nodeToggle.closest('[data-jsonmaker-node]');",
			"\t\tif (!node) {",
			"\t\t\treturn;",
			"\t\t}",
			"\t\tconst slug = node.dataset.jsonmakerNode || '';",
			"\t\tconst isOpen = nodeToggle.dataset.jsonmakerNodeState === 'open';",
			"\t\tjsonmakerSetNodeDomState(node, !isOpen);",
			"\t\tif (slug) {",
			"\t\t\tjsonmakerSetNodeState(slug, !isOpen);",
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
		$request_uri_raw = $this->get_server_string('REQUEST_URI');
		$request_uri = is_string($request_uri_raw) ? $request_uri_raw : '';
		$request_uri = esc_url_raw($request_uri);

		return home_url($request_uri);
	}

	private function get_toolbar_source_url_from_request(): string {
		$redirect_raw = $this->get_post_string('jsonmaker_redirect');

		if ($redirect_raw !== null) {
			$redirect = remove_query_arg(['jsonmaker_msg', 'jsonmaker_status'], $redirect_raw);
			$redirect = esc_url_raw($redirect);

			if ($redirect !== '') {
				return $redirect;
			}
		}

		$current_url = $this->get_current_url();

		if ($current_url === '') {
			return '';
		}

		$current_url = remove_query_arg(['jsonmaker_msg', 'jsonmaker_status'], $current_url);

		return esc_url_raw($current_url);
	}

	private function build_freemius_checkout_url(int $plan_id, int $licenses): string {
		$fs = jm_fs();

		if (! is_object($fs)) {
			return '';
		}

		if (! class_exists('FS_Checkout_Manager') && file_exists(__DIR__ . '/vendor/freemius/includes/managers/class-fs-checkout-manager.php')) {
			require_once __DIR__ . '/vendor/freemius/includes/managers/class-fs-checkout-manager.php';
		}

		if (! class_exists('FS_Checkout_Manager')) {
			return '';
		}

		$resolved_plan_id = $this->resolve_checkout_plan_id($plan_id, $fs);

		if ($resolved_plan_id <= 0) {
			return '';
		}

		$checkout_manager = FS_Checkout_Manager::instance();
		$plugin_id = $fs->get_id();
		$query_params = $checkout_manager->get_query_params($fs, $plugin_id, $resolved_plan_id, (string) $licenses);
		$query_params['return_url'] = $fs->_get_sync_license_url($plugin_id);
		$base_url = defined('FS_CHECKOUT__ADDRESS') ? FS_CHECKOUT__ADDRESS : 'https://checkout.freemius.com';

		return $checkout_manager->get_full_checkout_url($query_params, $base_url);
	}

	private function resolve_checkout_plan_id(int $requested_plan_id, $fs): int {
		if ($requested_plan_id > 0) {
			return $requested_plan_id;
		}

		if (! is_object($fs)) {
			return 0;
		}

		if (! method_exists($fs, 'is_registered') || ! $fs->is_registered()) {
			return 0;
		}

		$plan_class = __DIR__ . '/vendor/freemius/includes/entities/class-fs-plugin-plan.php';
		if (! class_exists('FS_Plugin_Plan') && file_exists($plan_class)) {
			require_once $plan_class;
		}

		if (! method_exists($fs, '_sync_plans')) {
			return 0;
		}

		$plans = $fs->_sync_plans();

		if (! is_array($plans) || empty($plans)) {
			return 0;
		}

		$fallback_id = 0;

		foreach ($plans as $plan) {
			if (! is_object($plan) || ! isset($plan->id)) {
				continue;
			}

			if ($fallback_id === 0) {
				$fallback_id = (int) $plan->id;
			}

			$is_free = false;
			if ($plan instanceof FS_Plugin_Plan && method_exists($plan, 'is_free')) {
				$is_free = $plan->is_free();
			} elseif (property_exists($plan, 'name')) {
				$is_free = strtolower((string) $plan->name) === 'free';
			}

			if (! $is_free) {
				return (int) $plan->id;
			}
		}

		return $fallback_id;
	}

	private function get_configured_plan_id(): int {
		$plan_id = 0;

		if (defined('JSONMAKER_CHECKOUT_PLAN_ID')) {
			$plan_id = (int) constant('JSONMAKER_CHECKOUT_PLAN_ID');
		} else {
			$stored = get_option('jsonmaker_checkout_plan_id');
			if (is_numeric($stored)) {
				$plan_id = (int) $stored;
			}
		}

		$plan_id = (int) apply_filters('jsonmaker_checkout_plan_id', $plan_id);

		if ($plan_id > 0) {
			return $plan_id;
		}

		return self::PAID_PLAN_ID;
	}

	private function sanitize_css_dimension($value, string $fallback): string {
		$value = is_string($value) ? trim($value) : '';

		if ($value === '') {
			return $fallback;
		}

		if (preg_match('/^\d+(\.\d+)?(px|rem|em|vh|vw|%)?$/', $value)) {
			if (preg_match('/(px|rem|em|vh|vw|%)$/', $value)) {
				return $value;
			}

			return $value . 'px';
		}

		return $fallback;
	}

	public function maybe_add_cors_headers(): void {
		$query_node = get_query_var('jsonmaker_node');
		$query_user = get_query_var('jsonmaker_user');

		if ($query_node !== '' && $query_user !== '') {
			$this->send_cors_headers();

			return;
		}

		$raw_node = $this->get_query_string('jsonmaker_node');
		$raw_user = $this->get_query_string('jsonmaker_user');

		if ($raw_node !== null && $raw_node !== '' && $raw_user !== null && $raw_user !== '') {
			$this->send_cors_headers();
		}
	}

	public function maybe_disable_canonical_redirect($redirect_url, $requested_url) {
		$requested_node = get_query_var('jsonmaker_node');
		$requested_user = get_query_var('jsonmaker_user');

		if ($requested_node !== '' && $requested_user !== '') {
			return false;
		}

		$raw_node = $this->get_query_string('jsonmaker_node');
		$raw_user = $this->get_query_string('jsonmaker_user');
		if ($raw_node !== null && $raw_node !== '' && $raw_user !== null && $raw_user !== '') {
			return false;
		}

		if (strpos($requested_url, '/json/') !== false) {
			return false;
		}

		return $redirect_url;
	}

	private function sanitize_section_markup(string $markup): string {
		if ($markup === '') {
			return '';
		}

		static $allowed_html = null;

		if ($allowed_html === null) {
			$allowed_html = $this->get_section_allowed_html();
		}

		return wp_kses($markup, $allowed_html);
	}

	private function get_section_allowed_html(): array {
		$allowed = wp_kses_allowed_html('post');
		$extended = [
			'div' => [
				'class' => true,
				'id' => true,
				'hidden' => true,
				'data-jsonmaker-section' => true,
				'data-jsonmaker-section-default' => true,
			],
			'span' => [
				'class' => true,
				'aria-hidden' => true,
				'data-jsonmaker-section-indicator' => true,
			],
			'button' => [
				'class' => true,
				'id' => true,
				'type' => true,
				'data-jsonmaker-section-toggle' => true,
				'data-jsonmaker-target' => true,
				'data-jsonmaker-has-value' => true,
				'data-jsonmaker-has-children' => true,
				'data-jsonmaker-message' => true,
				'aria-controls' => true,
				'aria-expanded' => true,
			],
			'form' => [
				'action' => true,
				'method' => true,
				'class' => true,
				'id' => true,
				'hidden' => true,
				'autocomplete' => true,
			],
			'input' => [
				'type' => true,
				'name' => true,
				'value' => true,
				'class' => true,
				'required' => true,
				'id' => true,
				'autocomplete' => true,
				'placeholder' => true,
				'checked' => true,
				'data-jsonmaker-target' => true,
			],
			'textarea' => [
				'name' => true,
				'id' => true,
				'rows' => true,
				'cols' => true,
				'class' => true,
				'required' => true,
			],
			'select' => [
				'name' => true,
				'id' => true,
				'class' => true,
				'required' => true,
				'data-jsonmaker-import-target' => true,
			],
			'option' => [
				'value' => true,
				'selected' => true,
				'disabled' => true,
			],
			'label' => [
				'for' => true,
				'class' => true,
			],
			'pre' => [
				'class' => true,
			],
			'code' => [
				'class' => true,
			],
			'p' => [
				'class' => true,
			],
			'ul' => [
				'class' => true,
			],
			'li' => [
				'class' => true,
			],
			'small' => [
				'class' => true,
			],
			'a' => [
				'href' => true,
				'target' => true,
				'rel' => true,
				'class' => true,
			],
		];

		foreach ($extended as $tag => $attributes) {
			if (! isset($allowed[$tag])) {
				$allowed[$tag] = [];
			}

			$allowed[$tag] = array_merge($allowed[$tag], $attributes);
		}

		return $allowed;
	}

	private function get_post_string(string $key): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce enforced via enforce_action_nonce().
		if (! isset($_POST[$key])) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Raw access deferred for contextual sanitization.
		$value = $_POST[$key];

		if (is_array($value)) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Callers sanitize after helper unslashes.
		return wp_unslash((string) $value);
	}

	private function get_query_string(string $key): ?string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Helper reads query vars and callers sanitize.
		if (! isset($_GET[$key])) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Callers sanitize the unslashed string.
		$value = $_GET[$key];

		if (is_array($value)) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Sanitized at point of use.
		return wp_unslash((string) $value);
	}

	private function get_server_string(string $key): ?string {
		if (! isset($_SERVER[$key])) {
			return null;
		}

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server globals sanitized at point of use.
		$value = $_SERVER[$key];

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Server values sanitized when consumed and not slashed.
		return is_string($value) ? $value : null;
	}

	private function enforce_action_nonce(string $action): void {
		if ($action === '' || $this->is_nonce_action_enforced($action)) {
			return;
		}

		$map = $this->get_action_nonce_map();

		if (! isset($map[$action])) {
			return;
		}

		[$nonce_action, $nonce_field] = $map[$action];
		check_admin_referer($nonce_action, $nonce_field);
		$this->mark_nonce_action_enforced($action);
	}

	private function is_nonce_action_enforced(string $action): bool {
		return isset($this->enforced_nonce_actions[$action]);
	}

	private function mark_nonce_action_enforced(string $action): void {
		$this->enforced_nonce_actions[$action] = true;
	}

	/**
	 * @return array<string, array{0: string, 1: string}>
	 */
	private function get_action_nonce_map(): array {
		return [
			'add_node' => ['jsonmaker_add_node', 'jsonmaker_nonce'],
			'delete_node' => ['jsonmaker_delete_node', 'jsonmaker_delete_nonce'],
			'edit_node' => ['jsonmaker_edit_node', 'jsonmaker_edit_nonce'],
			'import_json' => ['jsonmaker_import', 'jsonmaker_import_nonce'],
			'register_json_user' => ['jsonmaker_register_user', 'jsonmaker_register_nonce'],
		];
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
