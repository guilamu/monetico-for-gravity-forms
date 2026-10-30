<?php
/**
 * Migration from the legacy "Gravity Forms Monetico" plugin (slug gravityforms-monetico).
 *
 * Gravity Forms keys everything an add-on stores by its slug: plugin settings,
 * feeds, the payment_gateway entry meta, processed feeds and payment callbacks.
 * This class moves that data to the new slug, then deactivates the legacy
 * plugin: both plugins handle the same Monetico CGI2 return, so they must never
 * run together.
 *
 * Every step is idempotent, so the migration can run again safely, for example
 * if the legacy plugin was reactivated and used in the meantime.
 */

if (!defined('ABSPATH')) {
	exit;
}

class Monetico_GF_Migration
{
	const LEGACY_SLUG = 'gravityforms-monetico';
	const LEGACY_PLUGIN = 'gravityforms-monetico/gravityforms-monetico.php';
	const NEW_SLUG = 'monetico-for-gravity-forms';

	/// Set once the data of the current site has been migrated (per site on multisite)
	const DONE_OPTION = 'monetico_gf_legacy_migrated';
	const NOTICE_TRANSIENT = 'monetico_gf_legacy_notice';

	const BATCH_SIZE = 500;

	/**
	 * Called on gform_loaded, before the add-on is registered.
	 *
	 * @return bool True when the legacy plugin is loaded in this request: the
	 *              add-on must then stay dormant until the next request.
	 */
	public static function maybe_migrate()
	{
		$legacy_loaded = class_exists('GF_Monetico_Bootstrap');

		if ($legacy_loaded || !get_option(self::DONE_OPTION)) {
			self::migrate();
		}

		return $legacy_loaded;
	}

	/**
	 * Activation hook of the new plugin.
	 */
	public static function activate()
	{
		// Gravity Forms not active yet: the migration will run on the first gform_loaded
		if (!class_exists('GFForms')) {
			return;
		}

		self::migrate();
	}

	public static function migrate()
	{
		self::migrate_options();
		self::migrate_feeds();
		self::migrate_entry_meta();
		self::migrate_callbacks();

		if (class_exists('GFCache')) {
			GFCache::flush();
		}

		update_option(self::DONE_OPTION, MONETICO_GF_VERSION);

		self::deactivate_legacy_plugin();
	}

	private static function migrate_options()
	{
		$legacy_option = 'gravityformsaddon_' . self::LEGACY_SLUG . '_settings';
		$new_option = 'gravityformsaddon_' . self::NEW_SLUG . '_settings';

		// Copied as stored: the Gravity Forms encryption does not depend on the slug
		$legacy_settings = get_option($legacy_option);
		if (false !== $legacy_settings) {
			if (false === get_option($new_option)) {
				update_option($new_option, $legacy_settings);
			}
			delete_option($legacy_option);
		}

		delete_option('gravityformsaddon_' . self::LEGACY_SLUG . '_version');
	}

	private static function migrate_feeds()
	{
		global $wpdb;

		$table = $wpdb->prefix . 'gf_addon_feed';
		if (self::table_exists($table)) {
			$wpdb->update($table, array('addon_slug' => self::NEW_SLUG), array('addon_slug' => self::LEGACY_SLUG));
		}
	}

	private static function migrate_callbacks()
	{
		global $wpdb;

		// Already processed CGI2 returns, used to ignore a return sent twice by Monetico
		$table = $wpdb->prefix . 'gf_addon_payment_callback';
		if (self::table_exists($table)) {
			$wpdb->update($table, array('addon_slug' => self::NEW_SLUG), array('addon_slug' => self::LEGACY_SLUG));
		}
	}

	private static function migrate_entry_meta()
	{
		global $wpdb;

		$table = GFFormsModel::get_entry_meta_table_name();
		if (!self::table_exists($table)) {
			return;
		}

		// Entries paid with the legacy plugin: needed for refunds, payment links and payment editing
		$wpdb->update(
			$table,
			array('meta_value' => self::NEW_SLUG),
			array('meta_key' => 'payment_gateway', 'meta_value' => self::LEGACY_SLUG)
		);

		// processed_feeds is a serialized array keyed by add-on slug
		$like = '%' . $wpdb->esc_like('"' . self::LEGACY_SLUG . '"') . '%';
		$last_id = 0;

		do {
			$rows = $wpdb->get_results($wpdb->prepare(
				"SELECT id, meta_value FROM {$table} WHERE meta_key = 'processed_feeds' AND meta_value LIKE %s AND id > %d ORDER BY id LIMIT %d",
				$like,
				$last_id,
				self::BATCH_SIZE
			));

			foreach ($rows as $row) {
				$last_id = (int) $row->id;
				$processed = maybe_unserialize($row->meta_value);

				if (!is_array($processed) || !isset($processed[self::LEGACY_SLUG])) {
					continue;
				}

				$processed[self::NEW_SLUG] = array_values(array_unique(array_merge(
					isset($processed[self::NEW_SLUG]) ? (array) $processed[self::NEW_SLUG] : array(),
					(array) $processed[self::LEGACY_SLUG]
				)));
				unset($processed[self::LEGACY_SLUG]);

				$wpdb->update($table, array('meta_value' => maybe_serialize($processed)), array('id' => $last_id));
			}
		} while (count($rows) === self::BATCH_SIZE);
	}

	private static function deactivate_legacy_plugin()
	{
		if (!function_exists('deactivate_plugins')) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin = self::get_legacy_basename();
		$network_wide = is_multisite() && is_plugin_active_for_network($plugin);

		if (!$network_wide && !is_plugin_active($plugin)) {
			return;
		}

		deactivate_plugins($plugin, false, $network_wide);
		set_transient(self::NOTICE_TRANSIENT, 1, DAY_IN_SECONDS);
	}

	/**
	 * The legacy plugin may have been installed in a folder with another name
	 * (e.g. extracted from a GitHub zip): when it is loaded, its real path wins.
	 */
	private static function get_legacy_basename()
	{
		if (class_exists('GF_Monetico_Bootstrap')) {
			$reflection = new ReflectionClass('GF_Monetico_Bootstrap');
			return plugin_basename($reflection->getFileName());
		}

		return self::LEGACY_PLUGIN;
	}

	private static function table_exists($table)
	{
		global $wpdb;

		return $table === $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $wpdb->esc_like($table)));
	}

	public static function admin_notice()
	{
		if (!get_transient(self::NOTICE_TRANSIENT) || !current_user_can('activate_plugins')) {
			return;
		}

		delete_transient(self::NOTICE_TRANSIENT);

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html__('Monetico for Gravity Forms: the settings, feeds and payment history of the "Gravity Forms Monetico" plugin have been migrated, and that plugin has been deactivated. You can now delete it.', 'monetico-for-gravity-forms')
		);
	}
}
