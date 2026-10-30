<?php
/*
Plugin Name: Monetico for Gravity Forms
Plugin URI: https://github.com/guilamu/monetico-for-gravity-forms
Description: Unofficial Monetico payment gateway for Gravity Forms. Provided as is, without any warranty: the author accepts no liability for any payment made or received with it. Use at your own risk.
Version: 0.1.2
Requires at least: 5.8
Requires PHP: 7.4
Author: Guilamu
Author URI: https://github.com/guilamu
License: AGPL-3.0-or-later
License URI: https://www.gnu.org/licenses/agpl-3.0.html
Text Domain: monetico-for-gravity-forms
Domain Path: /languages
Update URI: https://github.com/guilamu/monetico-for-gravity-forms/

------------------------------------------------------------------------
DISCLAIMER / AVERTISSEMENT

This plugin is unofficial and is not affiliated with Monetico, Crédit Mutuel,
CIC, Euro-Information or Gravity Forms. It is provided "as is", without any
warranty. The author can in no event be held liable for any payment received,
missed, duplicated, wrongly recorded or refunded through this plugin, nor for
any direct or indirect loss resulting from its use. Use it at your own risk.

Ce plugin n'est pas officiel. Il est fourni « en l'état », sans aucune
garantie. Son auteur ne peut en aucun cas être tenu responsable des paiements
reçus, manqués, dupliqués, mal enregistrés ou remboursés au moyen de ce plugin,
ni d'aucune perte directe ou indirecte liée à son utilisation.
------------------------------------------------------------------------
Copyright 2026 Guilamu

This program is free software: you can redistribute it and/or modify
it under the terms of the GNU Affero General Public License as published
by the Free Software Foundation, either version 3 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
GNU Affero General Public License for more details.

You should have received a copy of the GNU Affero General Public License
along with this program. If not, see <https://www.gnu.org/licenses/>.

Based on "Gravity Forms Monetico", released under GPL-2.0-or-later.
The Monetico payment kit in monetico/ keeps its own license (monetico/License.txt).
*/

if (!defined('ABSPATH')) {
	exit;
}

define( 'MONETICO_GF_VERSION', '0.1.2' );
define( 'MONETICO_GF_FILE', __FILE__ );
define( 'MONETICO_GF_PATH', plugin_dir_path( __FILE__ ) );
define( 'MONETICO_GF_BASENAME', plugin_basename( __FILE__ ) );

/// Mises à jour depuis les releases GitHub (guilamu/monetico-for-gravity-forms)
require_once MONETICO_GF_PATH . 'includes/class-github-updater.php';
add_filter( 'plugin_row_meta', 'monetico_gf_plugin_row_meta', 10, 2 );

/// Ancien plugin « Gravity Forms Monetico » : migration des données puis désactivation
require_once MONETICO_GF_PATH . 'class-monetico-gf-migration.php';
register_activation_hook( __FILE__, array( 'Monetico_GF_Migration', 'activate' ) );
add_action( 'admin_notices', array( 'Monetico_GF_Migration', 'admin_notice' ) );
add_action( 'network_admin_notices', array( 'Monetico_GF_Migration', 'admin_notice' ) );

add_action( 'init', 'monetico_gf_load_textdomain', 1, 0 );
add_action( 'gform_loaded', array( 'Monetico_GF_Bootstrap', 'load' ), 5 );

/// Le .mo livré est chargé par son chemin absolu : un fichier périmé dans wp-content/languages/plugins
/// (ex. Loco Translate) passerait sinon en priorité, sans aucun message.
function monetico_gf_load_textdomain( $force = false ) {

	static $loaded_locale = '';

	$locale = determine_locale();

	if ( ! $force && $loaded_locale === $locale ) {
		return;
	}

	$mofile = MONETICO_GF_PATH . 'languages/monetico-for-gravity-forms-' . $locale . '.mo';

	if ( file_exists( $mofile ) ) {

		unload_textdomain( 'monetico-for-gravity-forms' );

		if ( load_textdomain( 'monetico-for-gravity-forms', $mofile, $locale ) ) {
			$loaded_locale = $locale;
			return;
		}
	}

	load_plugin_textdomain( 'monetico-for-gravity-forms', false, dirname( MONETICO_GF_BASENAME ) . '/languages' );

	$loaded_locale = $locale;
}

function monetico_gf_plugin_row_meta( $links, $file ) {

	if ( MONETICO_GF_BASENAME !== $file ) {
		return $links;
	}

	// "View details" thickbox link — same pattern as WordPress.org-hosted plugins.
	$links[] = sprintf(
		'<a href="%s" class="thickbox open-plugin-details-modal" aria-label="%s" data-title="%s">%s</a>',
		esc_url( self_admin_url(
			'plugin-install.php?tab=plugin-information&plugin=monetico-for-gravity-forms'
			. '&TB_iframe=true&width=772&height=926'
		) ),
		esc_attr__( 'More information about Monetico for Gravity Forms', 'monetico-for-gravity-forms' ),
		esc_attr__( 'Monetico for Gravity Forms', 'monetico-for-gravity-forms' ),
		esc_html__( 'View details', 'monetico-for-gravity-forms' )
	);

	return $links;
}

class Monetico_GF_Bootstrap {

	public static function load() {

		if ( ! method_exists( 'GFForms', 'include_payment_addon_framework' ) ) {
			return;
		}

		/// Tant que l'ancien plugin est chargé (requête en cours), on ne s'enregistre pas :
		/// les deux traiteraient le même retour CGI2. Il est désactivé pour les requêtes suivantes.
		if ( Monetico_GF_Migration::maybe_migrate() ) {
			return;
		}

		if(!defined("MONETICOPAIEMENT_VERSION")) define ("MONETICOPAIEMENT_VERSION", "3.0");
		if(!defined('MONETICOPAIEMENT_PHASE2BACK_RECEIPT')) define ("MONETICOPAIEMENT_PHASE2BACK_RECEIPT","version=2\ncdr=%s");
		if(!defined('MONETICOPAIEMENT_PHASE2BACK_MACOK')) define ("MONETICOPAIEMENT_PHASE2BACK_MACOK","0");
		if(!defined('MONETICOPAIEMENT_PHASE2BACK_MACNOTOK')) define ("MONETICOPAIEMENT_PHASE2BACK_MACNOTOK","1\n");
		if(!defined("MONETICOPAIEMENT_URLPAYMENT")) define ("MONETICOPAIEMENT_URLPAYMENT", "paiement.cgi");

		require_once( MONETICO_GF_PATH . 'class-monetico-gf-addon.php' );

		GFAddOn::register( 'Monetico_GF_AddOn' );

		/// Conversion du pays de facturation en code pays attendu par Monetico pour 3DS2
		add_filter( 'gform_countries', 'monetico_gf_country_codes' );
	}
}

function monetico_gf() {
	return Monetico_GF_AddOn::get_instance();
}

function monetico_gf_country_codes( $countries ) {
	$new_countries = array();
	foreach ( $countries as $country ) {
		$code                   = GF_Fields::get( 'address' )->get_country_code( $country );
		$new_countries[ $code ] = $country;
	}
	return $new_countries;
}
