<?php
/**
 * Plugin Name: Jsonmaker
 * Description: Manage a hierarchical collection of titled links that can be edited from a shortcode and fetched as JSON.
 * Version: 0.1.0
 * Author: Codex
 */

if (! defined('ABSPATH')) {
	exit;
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
	}

	public static function activate(): void {
		self::ensure_capability();
		self::ensure_initial_tree();
		self::instance()->register_rewrite();
		flush_rewrite_rules();
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
		if (! isset($_POST['jsonmaker_action'])) {
			return;
		}

		if (! current_user_can(self::CAPABILITY)) {
			return;
		}

		$action = sanitize_key(wp_unslash($_POST['jsonmaker_action']));

		if ($action === 'add_node') {
			$this->handle_add_submission();
		} elseif ($action === 'delete_node') {
			$this->handle_delete_submission();
		} elseif ($action === 'edit_node') {
			$this->handle_edit_submission();
		}
	}

	private function handle_add_submission(): void {
		if (! isset($_POST['jsonmaker_parent'], $_POST['jsonmaker_title'])) {
			return;
		}

		check_admin_referer('jsonmaker_add_node', 'jsonmaker_nonce');

		$parent_slug = sanitize_key(wp_unslash($_POST['jsonmaker_parent']));
		$title_raw = wp_unslash($_POST['jsonmaker_title']);
		$title = sanitize_text_field($title_raw);
		$title = trim($title);
		$value = '';

		if (isset($_POST['jsonmaker_value'])) {
			$value_raw = wp_unslash($_POST['jsonmaker_value']);
			$value = sanitize_text_field($value_raw);
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
		if (! isset($_POST['jsonmaker_target'])) {
			return;
		}

		check_admin_referer('jsonmaker_delete_node', 'jsonmaker_delete_nonce');

		$target_slug = sanitize_key(wp_unslash($_POST['jsonmaker_target']));

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
		if (! isset($_POST['jsonmaker_target'], $_POST['jsonmaker_title'])) {
			return;
		}

		check_admin_referer('jsonmaker_edit_node', 'jsonmaker_edit_nonce');

		$target_slug = sanitize_key(wp_unslash($_POST['jsonmaker_target']));
		$title_raw = wp_unslash($_POST['jsonmaker_title']);
		$new_title = sanitize_text_field($title_raw);
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

	public function maybe_output_json(): void {
		$requested = get_query_var('jsonmaker_node');

		if ($requested === '') {
			return;
		}

		$node = $this->find_node($this->get_tree(), sanitize_key($requested));

		if ($node === null) {
			status_header(404);
			wp_send_json(['error' => 'Node not found'], 404);
		}

		wp_send_json($this->prepare_public_node($node));
	}

	public function render_shortcode(): string {
		$tree = $this->get_tree();
		$this->maybe_print_assets();

		$notice_code = '';
		$notice_status = 'error';

		if (isset($_GET['jsonmaker_msg'])) {
			$notice_code = sanitize_key(wp_unslash($_GET['jsonmaker_msg']));
		}

		if (isset($_GET['jsonmaker_status'])) {
			$notice_status_candidate = sanitize_key(wp_unslash($_GET['jsonmaker_status']));
			if ($notice_status_candidate === 'success') {
				$notice_status = 'success';
			}
		}

		ob_start();
		if ($notice_code !== '' && current_user_can(self::CAPABILITY)) {
			$message_text = $this->get_notice_text($notice_code);

			if ($message_text !== '') {
				$class = $notice_status === 'success' ? 'jsonmaker-notice--success' : 'jsonmaker-notice--error';
				echo '<div class="jsonmaker-notice ' . esc_attr($class) . '">' . esc_html($message_text) . '</div>';
			}
		}
		if (current_user_can(self::CAPABILITY)) {
			echo '<pre class="jsonmaker-json">';
			echo esc_html(wp_json_encode($this->prepare_public_node($tree), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			echo '</pre>';
		}
		echo '<div class="jsonmaker-tree">';
		$this->render_node($tree);
		echo '</div>';

		return (string) ob_get_clean();
	}

	private function render_node(array $node): void {
		$title = esc_html($node['title'] ?? '');
		$value_raw = $node['value'] ?? $node['url'] ?? '';
		if (! is_scalar($value_raw)) {
			$value_raw = '';
		}
		$value_display = '';
		$is_value_url = false;

		if ($value_raw !== '') {
			$value_raw = (string) $value_raw;
			$is_value_url = (bool) filter_var($value_raw, FILTER_VALIDATE_URL);
			$value_display = $is_value_url ? '<a href="' . esc_url($value_raw) . '">' . esc_html($value_raw) . '</a>' : esc_html($value_raw);
		}

		$has_children = ! empty($node['children']);
		$slug = $node['slug'] ?? '';
		$can_manage = current_user_can(self::CAPABILITY);
		$current_url = $can_manage ? $this->get_current_url() : '';

		echo '<div class="jsonmaker-node">';

		echo '<div class="jsonmaker-node__title">';
		echo '<span class="jsonmaker-node__label">' . $title . '</span>';

		if ($value_display !== '') {
			echo ' <span class="jsonmaker-node__value">-&gt; ' . $value_display . '</span>';
		}

		if ($can_manage && $slug !== '') {
			echo ' <button type="button" class="jsonmaker-add-button" data-jsonmaker-target="jsonmaker-form-' . esc_attr($slug) . '">';
			echo esc_html__('Add Node', 'jsonmaker');
			echo '</button>';
			echo ' <button type="button" class="jsonmaker-edit-button" data-jsonmaker-target="jsonmaker-edit-form-' . esc_attr($slug) . '">';
			echo esc_html__('Edit', 'jsonmaker');
			echo '</button>';
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
			$this->render_edit_form($slug, $node['title'] ?? '', $current_url);
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
		echo esc_html__('Title', 'jsonmaker') . '<br />';
		echo '<input type="text" name="jsonmaker_title" required />';
		echo '</label><br />';
		echo '<label>';
		echo esc_html__('Value (leave blank to create a container)', 'jsonmaker') . '<br />';
		echo '<input type="text" name="jsonmaker_value" />';
		echo '</label><br />';
		echo '<button type="submit">' . esc_html__('Add Child', 'jsonmaker') . '</button>';
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
		echo esc_html__('Title', 'jsonmaker') . '<br />';
		echo '<input type="text" name="jsonmaker_title" value="' . esc_attr($current_title) . '" required />';
		echo '</label><br />';
		echo '<button type="submit">' . esc_html__('Save Title', 'jsonmaker') . '</button>';
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
		$data_attr = $has_children ? ' data-jsonmaker-has-children="1"' : '';
		echo '<button type="submit" class="jsonmaker-delete-button"' . $data_attr . '>';
		echo esc_html__('Delete Node', 'jsonmaker');
		echo '</button>';
		echo '</form>';
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

		if (isset($_POST['jsonmaker_redirect'])) {
			$redirect = esc_url_raw(wp_unslash($_POST['jsonmaker_redirect']));
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
				return __('Node added.', 'jsonmaker');
			case 'node_deleted':
				return __('Node deleted.', 'jsonmaker');
			case 'title_updated':
				return __('Title updated.', 'jsonmaker');
			case 'parent_not_found':
				return __('Unable to find the parent node.', 'jsonmaker');
			case 'missing_fields':
				return __('Please provide all required fields.', 'jsonmaker');
			case 'cannot_delete_root':
				return __('Cannot delete the root node.', 'jsonmaker');
			case 'node_not_found':
				return __('The requested node could not be found.', 'jsonmaker');
			case 'has_children':
				return __('Remove child nodes before deleting this node.', 'jsonmaker');
			case 'title_exists':
				return __('A node with that title already exists. Choose a different title.', 'jsonmaker');
			default:
				return '';
		}
	}

	private function maybe_print_assets(): void {
		if ($this->printed_assets) {
			return;
		}

		$this->printed_assets = true;

		$style = <<<HTML
<style>
.jsonmaker-json {font-family: Menlo, Consolas, monospace; background:#f5f5f5; padding:1rem; border:1px solid #ddd; overflow:auto;}
.jsonmaker-tree {font-family: Arial, sans-serif;}
.jsonmaker-node {border-left:2px solid #ddd; margin-left:1rem; padding-left:1rem; margin-top:0.5rem;}
.jsonmaker-node__title {display:flex; align-items:center; gap:0.5rem; flex-wrap:wrap;}
.jsonmaker-node__label {font-weight:600;}
.jsonmaker-node__value {font-family:Menlo, Consolas, monospace;}
.jsonmaker-node__title a {text-decoration:none;}
.jsonmaker-add-button,
.jsonmaker-edit-button,
.jsonmaker-delete-button {font-size:0.8rem; padding:0.1rem 0.4rem; cursor:pointer;}
.jsonmaker-delete-form {display:inline; margin:0;}
.jsonmaker-delete-button {margin-left:0.25rem;}
.jsonmaker-add-form,
.jsonmaker-edit-form {margin-top:0.5rem;}
.jsonmaker-add-form form,
.jsonmaker-edit-form form {background:#f9f9f9; border:1px solid #ccc; padding:0.5rem;}
.jsonmaker-notice {margin-bottom:1rem; padding:0.75rem 1rem; border-radius:4px; border:1px solid transparent; font-weight:600;}
.jsonmaker-notice--success {background:#f0fff4; border-color:#38a169; color:#276749;}
.jsonmaker-notice--error {background:#fff5f5; border-color:#e53e3e; color:#9b2c2c;}
</style>
HTML;

		$script = <<<HTML
<script>
document.addEventListener('click', function (event) {
	const addButton = event.target.closest('.jsonmaker-add-button');
	if (addButton) {
		event.preventDefault();

		const targetId = addButton.dataset.jsonmakerTarget;
		if (!targetId) {
			return;
		}

		const form = document.getElementById(targetId);
		if (!form) {
			return;
		}

		const isHidden = form.hasAttribute('hidden');
		if (isHidden) {
			form.removeAttribute('hidden');
			const focusable = form.querySelector('input[name="jsonmaker_title"]');
			if (focusable) {
				focusable.focus();
			}
		} else {
			form.setAttribute('hidden', '');
		}

		return;
	}

	const editButton = event.target.closest('.jsonmaker-edit-button');
	if (editButton) {
		event.preventDefault();

		const targetId = editButton.dataset.jsonmakerTarget;
		if (!targetId) {
			return;
		}

		const form = document.getElementById(targetId);
		if (!form) {
			return;
		}

		const isHidden = form.hasAttribute('hidden');
		if (isHidden) {
			form.removeAttribute('hidden');
			const focusable = form.querySelector('input[name="jsonmaker_title"]');
			if (focusable) {
				focusable.focus();
			}
		} else {
			form.setAttribute('hidden', '');
		}

		return;
	}

	const deleteButton = event.target.closest('.jsonmaker-delete-button');
	if (!deleteButton) {
		return;
	}

	if (deleteButton.dataset.jsonmakerHasChildren === '1') {
		event.preventDefault();
		window.alert('Remove child nodes before deleting this node.');
	}
});
</script>
HTML;

		echo $style . $script;
	}

	private function get_current_url(): string {
		$request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash((string) $_SERVER['REQUEST_URI']) : '';

		return home_url($request_uri);
	}
}

register_activation_hook(__FILE__, ['Jsonmaker_Plugin', 'activate']);
Jsonmaker_Plugin::instance();
