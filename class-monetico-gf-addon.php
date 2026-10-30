<?php
/// Interception de la page de retour de banque pour le client (pas CGI2)
add_action('wp', array('Monetico_GF_AddOn', 'maybe_thankyou_page'), 5);

GFForms::include_payment_addon_framework();

class Monetico_GF_AddOn extends GFPaymentAddOn
{

	protected $_version = MONETICO_GF_VERSION;
	protected $_min_gravityforms_version = '2.5';
	protected $_slug = 'monetico-for-gravity-forms';
	protected $_path = 'monetico-for-gravity-forms/monetico-for-gravity-forms.php';
	protected $_full_path = MONETICO_GF_FILE;
	protected $_title = 'Monetico for Gravity Forms';
	protected $_short_title = 'Monetico';
	protected $_supports_callbacks = true;
	private $production_url = 'https://p.monetico-services.com/';
	private $test_url = 'https://p.monetico-services.com/test/';

	// Members plugin integration
	protected $_capabilities = array('gravityforms_monetico', 'gravityforms_monetico_uninstall');

	// Permissions
	protected $_capabilities_settings_page = 'gravityforms_monetico';
	protected $_capabilities_form_settings = 'gravityforms_monetico';
	protected $_capabilities_uninstall = 'gravityforms_monetico_uninstall';

	// Updates come from GitHub, not from the Gravity Forms license server
	protected $_enable_rg_autoupgrade = false;

	private static $_instance = null;

	/// Niveau de tampon de sortie au début du retour CGI2 (sceau validé), null sinon
	private $ack_ob_level = null;

	public static function get_instance()
	{
		if (self::$_instance == null) {
			self::$_instance = new Monetico_GF_AddOn();
		}

		return self::$_instance;
	}

	private function __clone() {} /* do nothing */

	/// Les traductions sont chargées par monetico_gf_load_textdomain() (chemin absolu du .mo livré)
	public function load_text_domain()
	{
		monetico_gf_load_textdomain();
	}

	public function init()
	{
		parent::init();

		// Merge tag for payment link, needed in every context:
		// frontend, admin-ajax (resend notifications) and CGI2 callback (payment failed notification)
		add_filter('gform_replace_merge_tags', array($this, 'replace_monetico_merge_tags'), 10, 7);

		/// Page locale qui poste le formulaire de paiement vers Monetico (?moneticoPay= ou ?moneticoLink=)
		add_action('wp', array($this, 'maybe_render_payment_form'), 1);
	}

	public function init_frontend()
	{
		parent::init_frontend();

		add_filter('gform_disable_post_creation', array($this, 'delay_post'), 10, 3);
		add_filter('gform_disable_notification', array($this, 'delay_notification'), 10, 4);
	}

	//----- SETTINGS PAGES ----------//

	public function plugin_settings_fields()
	{
		/// Avertissement : plugin non officiel, aucune responsabilité de l'auteur sur les paiements
		$description = '
			<div class="alert warning" role="alert" style="text-align: left;">
				<p><strong>' . esc_html__('Unofficial plugin, provided "as is", without any warranty. Use it at your own risk.', 'monetico-for-gravity-forms') . '</strong></p>
				<p>' . esc_html__('This plugin is not affiliated with, endorsed or supported by Monetico, Crédit Mutuel, CIC, Euro-Information or Gravity Forms. Its author can in no event be held liable for any payment received, missed, duplicated, wrongly recorded or refunded through it, nor for any direct or indirect loss resulting from its use.', 'monetico-for-gravity-forms') . '</p>
				<p>' . esc_html__('Before going into production, check every payment against your Monetico dashboard, which remains the only reference. If you need a guaranteed solution, use an official Monetico module or a commercially supported add-on instead.', 'monetico-for-gravity-forms') . '</p>
			</div>
			<p style="text-align: left;">' .
			esc_html__('Gravity Forms requires CGI2 return URL to be enabled on your Monetico account. Follow the following steps to confirm CGI2 return URL is enabled.', 'monetico-for-gravity-forms') .
			'</p>
			<ul>
				<li>' . sprintf(esc_html__('In your Monetico dashboard, enter the following CGI2 return URL in Settings > Return interface, for each site code used: %s', 'monetico-for-gravity-forms'), '<strong>' . esc_url(add_query_arg('moneticoListener', 'monetico_retour_banque', home_url('/'))) . '</strong>') . '</li>' .
			'<li>' . esc_html__('If this menu is not available to you, send this URL by e-mail to Monetico (centrecom@e-i.com) and wait for their confirmation.', 'monetico-for-gravity-forms') . '</li>' .
			'<li>' . esc_html__('Your TPE can only go into production after three successful test payments within seven days, whose CGI2 return was correctly processed by the site.', 'monetico-for-gravity-forms') . '</li>' .
			'</ul>
				<br/>';

		return array(
			array(
				'title'       => '',
				'description' => $description,
				'fields'      => array(
					array(
						'name'    => 'gf_monetico_configured',
						'label'   => esc_html__('CGI2 return URL operational', 'monetico-for-gravity-forms'),
						'type'    => 'checkbox',
						'choices' => array(array('label' => esc_html__('Confirm that you have configured your Monetico account to enable CGI2 return URL', 'monetico-for-gravity-forms'), 'name' => 'gf_monetico_configured'))
					),
					array(
						'type' => 'save',
						'messages' => array(
							'success' => esc_html__('Settings have been updated.', 'monetico-for-gravity-forms')
						),
					),
				),
			),
		);
	}

	public function feed_list_no_item_message()
	{
		$settings = $this->get_plugin_settings();
		if (! rgar($settings, 'gf_monetico_configured')) {
			return sprintf(esc_html__('To get started, please configure your %sMonetico Settings%s!', 'monetico-for-gravity-forms'), '<a href="' . admin_url('admin.php?page=gf_settings&subview=' . $this->_slug) . '">', '</a>');
		} else {
			return parent::feed_list_no_item_message();
		}
	}

	public function feed_settings_fields()
	{
		$default_settings = parent::feed_settings_fields();
		/// Par défaut certains champs sont conditionnés sur le type de transaction (produit, abonnement, donation)
		/// Nous ne gérons que les produits, nous parcourons les champs et si on arrive sur un paramètre de dépendance sur le type produit
		/// on supprime la dépendence pour l'afficher dès le chargement de la page et non après une sélection du type de transaction
		foreach ($default_settings as $key => $value):
			if (isset($value['dependency']['values']) && in_array('product', $value['dependency']['values']))
				unset($default_settings[$key]['dependency']);
		endforeach;
		/// On supprime carrément le sélecteur du type de transaction car nous ne gardons que "produit"
		$default_settings = parent::remove_field('transactionType', $default_settings);
		$fields = array(
			array(
				'name'          => 'mode',
				'label'         => esc_html__('Mode', 'monetico-for-gravity-forms'),
				'type'          => 'radio',
				'choices'       => array(
					array('id' => 'gf_monetico_mode_test', 'label' => esc_html__('Test', 'monetico-for-gravity-forms'), 'value' => 'test'),
					array('id' => 'gf_monetico_mode_production', 'label' => esc_html__('Production', 'monetico-for-gravity-forms'), 'value' => 'production'),
				),
				'horizontal'    => true,
				'default_value' => 'test',
				'tooltip'       => '<h6>' . esc_html__('Mode', 'monetico-for-gravity-forms') . '</h6>' . esc_html__('Select Production to receive live payments. Select Test for testing purposes when using the Monetico development environment.', 'monetico-for-gravity-forms')
			),
			array(
				'name'			=> 'tpe',
				'label'			=> esc_html__('TPE', 'monetico-for-gravity-forms'),
				'type'			=> 'text',
				'class'			=> 'small',
				'autocorrect'	=> 'off',
				'autocapitalize' => 'off',
				'spellcheck'	=> 'false',
				'required' 		=> true,
				'placeholder'	=> '0000001',
				'tooltip'		=> '<h6>' . esc_html__('TPE', 'monetico-for-gravity-forms') . '</h6>' . esc_html__('Number Electronic Payment Terminal linked to your Monetico contract.', 'monetico-for-gravity-forms'),
				'validation_callback'	=> '__return_true',		// force Gravity Forms to accept any character
			),
			array(
				'name'			=> 'cle',
				'label'			=> esc_html__('Key', 'monetico-for-gravity-forms'),
				'type'			=> 'text',
				'class'			=> 'medium',
				'autocorrect'	=> 'off',
				'autocapitalize' => 'off',
				'spellcheck'	=> 'false',
				'required' 		=> true,
				'placeholder'	=> '12345678901234567890123456789012345678P0',
				'tooltip'		=> '<h6>' . esc_html__('Key', 'monetico-for-gravity-forms') . '</h6>' . esc_html__('Secret key to obtain from your Monetico account.', 'monetico-for-gravity-forms'),
				'validation_callback'	=> '__return_true',		// force Gravity Forms to accept any character
			),
			array(
				'name'			=> 'code_societe',
				'label'			=> esc_html__('Company code', 'monetico-for-gravity-forms'),
				'type'			=> 'text',
				'class'			=> 'small',
				'autocorrect'	=> 'off',
				'autocapitalize' => 'off',
				'spellcheck'	=> 'false',
				'required' 		=> true,
				'placeholder'	=> 'abcdefghij',
				'tooltip'		=> '<h6>' . esc_html__('Company code', 'monetico-for-gravity-forms') . '</h6>' . esc_html__('Company code sent by e-mail by Monetico.', 'monetico-for-gravity-forms'),
			),
			array(
				'name'			=> 'currency_code',
				'label'			=> esc_html__('Currency code', 'monetico-for-gravity-forms'),
				'type'			=> 'select',
				'tooltip'		=> '<h6>' . esc_html__('Currency code', 'monetico-for-gravity-forms') . '</h6>' . esc_html__('Currency code (ISO 4217 compliant). EUR -> Euros, USD -> Dollars, etc..', 'monetico-for-gravity-forms'),
				'required' 		=> true,
				'choices'		=> self::getCurrencies(__('Use default currency', 'monetico-for-gravity-forms')),
			),
			array(
				'name'			=> 'merchant_country',
				'label'			=> esc_html__('Language', 'monetico-for-gravity-forms'),
				'type'			=> 'text',
				'class'			=> 'small',
				'autocorrect'	=> 'off',
				'autocapitalize' => 'off',
				'spellcheck'	=> 'false',
				'required' 		=> true,
				'placeholder'	=> esc_html__('FR', 'monetico-for-gravity-forms'),
				'tooltip'		=> '<h6>' . esc_html__('Language', 'monetico-for-gravity-forms') . '</h6>' . esc_html__('Language of the company: FR -> French, EN -> English.', 'monetico-for-gravity-forms'),
				'validation_callback'	=> '__return_true',		// force Gravity Forms to accept any character
			),
			array(
				'name'     => 'erreur_message',
				'label'    => __('Error message', 'monetico-for-gravity-forms'),
				'type'     => 'textarea',
				'class'    => 'medium',
				'default_value' => "Echec du paiement par carte bancaire.",
				'required' => true,
				'tooltip'  => '<h6>' . __('Error message', 'monetico-for-gravity-forms') . '</h6>' . __('Error message to be displayed to the customer when payment fails.', 'monetico-for-gravity-forms')
			),
			array(
				'name'     => 'annulation_message',
				'label'    => __('Cancellation message', 'monetico-for-gravity-forms'),
				'type'     => 'textarea',
				'default_value' => "Le paiement ne peut pas être finalisé sur le site.\nSi vous pensez que le paiement a été correctement effectué, veuillez nous contacter pour vérifier la prise en charge de votre paiement.",
				'class'    => 'medium',
				'required' => true,
				'tooltip'  => '<h6>' . __('Cancellation message', 'monetico-for-gravity-forms') . '</h6>' . __('Cancellation message to be displayed when the customer returns to the site without the bank being able to call the CGI2 return URL.', 'monetico-for-gravity-forms')
			),
			array(
				'name'			=> 'threeds_min_amount',
				'label'			=> esc_html__('3D Secure minimum amount', 'monetico-for-gravity-forms'),
				'type'			=> 'text',
				'class'			=> 'small',
				'placeholder'	=> '30',
				'tooltip'		=> '<h6>' . esc_html__('3D Secure minimum amount', 'monetico-for-gravity-forms') . '</h6>' . esc_html__('Below this amount, no 3D Secure challenge is requested from the customer (the issuing bank may still require one). Without authentication, the merchant loses the liability shift in case of dispute. Leave empty to let Monetico decide for every payment.', 'monetico-for-gravity-forms'),
				/// Les deux limites restent visibles sous le champ, pas seulement dans l'infobulle
				'description'	=> esc_html__('Below this amount, the customer is not asked for 3D Secure authentication. Leave empty to let Monetico decide for every payment.', 'monetico-for-gravity-forms')
					. '<br><strong>' . esc_html__('Before using it, keep in mind:', 'monetico-for-gravity-forms') . '</strong>'
					. '<br>• ' . esc_html__('this is only a request sent to the bank: the customer\'s bank may still require authentication;', 'monetico-for-gravity-forms')
					. '<br>• ' . esc_html__('without authentication, if the payment is disputed, the merchant bears the risk instead of the customer\'s bank.', 'monetico-for-gravity-forms'),
				'validation_callback'	=> '__return_true',		// valeur non numérique ignorée dans redirect_url()
			)
		);
		/// Ajout des champs personnalisés dans la liste des champs par défaut
		$default_settings = parent::add_field_after('feedName', $fields, $default_settings);
		//--------------------------------------------------------------------------------------

		$fields = array();

		if ($this->get_setting('delayNotification') || ! $this->is_gravityforms_supported('1.9.12')) {
			$fields[] = array(
				'name'    => 'notifications',
				'label'   => esc_html__('Notifications', 'monetico-for-gravity-forms'),
				'type'    => 'notifications',
				'tooltip' => '<h6>' . esc_html__('Notifications', 'monetico-for-gravity-forms') . '</h6>' . esc_html__("Enable this option if you would like to only send out this form's notifications for the 'Form is submitted' event after payment has been received. Leaving this option disabled will send these notifications immediately after the form is submitted. Notifications which are configured for other events will not be affected by this option.", 'monetico-for-gravity-forms')
			);
		}

		//Add post fields if form has a post
		$form = $this->get_current_form();
		// No current form outside the feed editor (e.g. GFAPI::add_feed)
		if ($form && GFCommon::has_post_field(rgar($form, 'fields', array()))) {
			$post_settings = array(
				'name'    => 'post_checkboxes',
				'label'   => esc_html__('Posts', 'monetico-for-gravity-forms'),
				'type'    => 'checkbox',
				'tooltip' => '<h6>' . esc_html__('Posts', 'monetico-for-gravity-forms') . '</h6>' . esc_html__('Enable this option if you would like to only create the post after payment has been received.', 'monetico-for-gravity-forms'),
				'choices' => array(
					array('label' => esc_html__('Create post only when payment is received.', 'monetico-for-gravity-forms'), 'name' => 'delayPost'),
				),
			);

			$fields[] = $post_settings;
		}

		//Adding custom settings for backwards compatibility with hook 'gform_monetico_add_option_group'
		$fields[] = array(
			'name'  => 'custom_options',
			'label' => '',
			'type'  => 'custom',
		);


		//--get billing info section and add customer first/last name
		$billing_info   = parent::get_field('billingInformation', $default_settings);
		$billing_fields = $billing_info['field_map'];
		$this->log_debug(__METHOD__ . "(): billing_info : " . print_r($billing_info, true));
		$add_first_name = true;
		$add_last_name  = true;
		/// Les champs que l'on souhaite passer en obligatoire pour 3DSecure 2
		$tab_required = ['address', 'city', 'zip', 'country'];
		foreach ($billing_fields as $key => $mapping) {
			//add first/last name if it does not already exist in billing fields
			if ($mapping['name'] == 'firstName') {
				$add_first_name = false;
			} else if ($mapping['name'] == 'lastName') {
				$add_last_name = false;
			}
			/// Passage des champs en obligatoire pour 3DS2
			if (in_array($mapping['name'], $tab_required)):
				$billing_info['field_map'][$key]['required'] = true;
			endif;
		}

		if ($add_last_name) {
			//add last name
			array_unshift($billing_info['field_map'], array('name' => 'lastName', 'label' => esc_html__('Last Name', 'monetico-for-gravity-forms'), 'required' => false));
		}
		if ($add_first_name) {
			array_unshift($billing_info['field_map'], array('name' => 'firstName', 'label' => esc_html__('First Name', 'monetico-for-gravity-forms'), 'required' => false));
		}
		$default_settings = parent::replace_field('billingInformation', $billing_info, $default_settings);
		//----------------------------------------------------------------------------------------------------

		//hide default display of setup fee, not used by Monetico
		$default_settings = parent::remove_field('setupFee', $default_settings);


		//-----------------------------------------------------------------------------------------------------

		/**
		 * Filter through the feed settings fields for the Paypal feed
		 *
		 * @param array $default_settings The Default feed settings
		 * @param array $form The Form object to filter through
		 */
		return apply_filters('gform_monetico_feed_settings_fields', $default_settings, $form);
	}
	/**
	 * set icon for settings page
	 * @return string
	 */
	public function plugin_settings_icon()
	{
		return '<i class="fa fa-credit-card" aria-hidden="true"></i>';
	}

	/// Ajout Guilamu depuis eway pro
	protected function getCurrencies($default_label)
	{
		if (!class_exists('RGCurrency', false)) {
			require_once \GFCommon::get_base_path() . '/currency.php';
		}
		$currencies = \RGCurrency::get_currencies();

		$options = [];

		if ($default_label) {
			$options[] = ['value' => '', 'label' => $default_label];
		}

		// translators: 1: currency code; 2: currency name
		$optionFormat = __('%1$s &mdash; %2$s', 'currency list', 'monetico-for-gravity-forms');

		foreach ($currencies as $ccode => $currency) {
			$options[] = ['value' => $ccode, 'label' => sprintf($optionFormat, esc_html($ccode), $currency['name'])];
		}

		return $options;
	}

	public function field_map_title()
	{
		return esc_html__('Monetico field', 'monetico-for-gravity-forms');
	}

	public function settings_custom($field, $echo = true)
	{

		ob_start();
?>
		<div id='gf_monetico_custom_settings'>
			<?php
			do_action('gform_monetico_add_option_group', $this->get_current_feed(), $this->get_current_form());
			?>
		</div>

		<script type='text/javascript'>
			jQuery(document).ready(function() {
				jQuery('#gf_monetico_custom_settings label.left_header').css('margin-left', '-200px');
			});
		</script>

		<?php

		$html = ob_get_clean();

		if ($echo) {
			echo $html;
		}

		return $html;
	}
	/**
	 * Prevent the GFPaymentAddOn version of the options field being added to the feed settings.
	 * 
	 * @return bool
	 */
	public function option_choices()
	{
		return false;
	}

	public function save_feed_settings($feed_id, $form_id, $settings)
	{

		//--------------------------------------------------------
		//For backwards compatibility
		$feed = $this->get_feed($feed_id);

		//Saving new fields into old field names to maintain backwards compatibility for delayed payments
		$settings['type'] = $settings['transactionType'];

		if (isset($settings['recurringAmount'])) {
			$settings['recurring_amount_field'] = $settings['recurringAmount'];
		}

		$feed['meta'] = $settings;
		$feed         = apply_filters('gform_monetico_save_config', $feed);

		//call hook to validate custom settings/meta added using gform_monetico_add_option_group action hook
		$is_validation_error = apply_filters('gform_monetico_config_validation', false, $feed);
		if ($is_validation_error) {
			//fail save
			return false;
		}

		$settings = $feed['meta'];
		$this->log_debug(__METHOD__ . "(): settings : " . print_r($settings, true));
		//--------------------------------------------------------

		return parent::save_feed_settings($feed_id, $form_id, $settings);
	}


	//------ SENDING TO MONETICO -----------//

	public function redirect_url($feed, $submission_data, $form, $entry)
	{


		//Don't process redirect url if request is not a Monetico return
		if (! rgempty('gf_monetico_return', $_GET)) {
			return false;
		}

		//updating lead's payment_status to Processing
		GFAPI::update_entry_property($entry['id'], 'payment_status', 'Processing');

		//Getting Url (Production or Sandbox)
		$url = $feed['meta']['mode'] == 'production' ? $this->production_url : $this->test_url;

		define("MONETICOPAIEMENT_URLSERVER", $url);

		$return_url = $this->return_url($form['id'], $entry['id']);


		$query_string = $this->get_product_query_string($submission_data, $entry['id']);

		$query_string = gf_apply_filters('gform_monetico_query', $form['id'], $query_string, $form, $entry, $feed, $submission_data);

		if (! $query_string) {
			$this->log_debug(__METHOD__ . '(): NOT sending to Monetico: The price is either zero or the gform_monetico_query filter was used to remove the querystring that is sent to Monetico.');

			return '';
		}

		/// Monetico permet de transmettre les données en GET !

		$TPE = $feed['meta']['tpe']; // TPE
		date_default_timezone_set('Europe/Paris');
		$sDate = date('d/m/Y:H:i:s'); // date
		$sMontant = number_format(str_replace(",", ".", rgar($submission_data, 'payment_amount')), 2, ".", ""); // montant
		$sReference = $entry['id']; // reference
		$url_retour_ok = $return_url;
		$url_retour_err = $return_url;
		$sLangue = $feed['meta']['merchant_country'];
		$societe = $feed['meta']['code_societe'];
		$sEmail = rgar($submission_data, 'email'); // mail
		$sTexteLibre = $sReference . "|" . wp_hash($sReference); // texte-libre
		/// « Utiliser la devise par défaut » enregistre une valeur vide : on prend la devise de l'entrée (réglage Gravity Forms)
		$sDevise = rgars($feed, 'meta/currency_code') ? $feed['meta']['currency_code'] : rgar($entry, 'currency', GFCommon::get_currency());

		$cle = $feed['meta']['cle'];

		$sNbrEch = "";
		$sDateEcheance1 = "";
		$sMontantEcheance1 = "";
		$sDateEcheance2 = "";
		$sMontantEcheance2 = "";
		$sDateEcheance3 = "";
		$sMontantEcheance3 = "";
		$sDateEcheance4 = "";
		$sMontantEcheance4 = "";


		// 3DS 2
		/// Les champs ajoutés manuellement dans les information de facturation (prénom, nom) ne sont pas accessible dans $submission_data contrairement aux champs natifs
		/// 3D Secure sélectif : sous le seuil du feed, aucun challenge n'est demandé
		$ThreeDSecureChallenge = 'no_preference';
		$threeds_min_amount = str_replace(',', '.', trim((string) rgars($feed, 'meta/threeds_min_amount')));
		if (is_numeric($threeds_min_amount) && (float) $sMontant < (float) $threeds_min_amount) {
			$ThreeDSecureChallenge = 'no_challenge_requested';
		}
		$ThreeDSecureChallenge = apply_filters('gf_monetico_change_ThreeDSecureChallenge', $ThreeDSecureChallenge);
		$facturation_prenom = rgar($entry, $feed['meta']['billingInformation_firstName']);
		$facturation_prenom = substr($this->sansCaracteresSpeciaux(apply_filters('gf_monetico_change_facturation_prenom', $facturation_prenom)), 0, 45);
		$facturation_nom = rgar($entry, $feed['meta']['billingInformation_lastName']);
		$facturation_nom = substr($this->sansCaracteresSpeciaux(apply_filters('gf_monetico_change_facturation_nom', $facturation_nom)), 0, 45);
		$facturation_email = $sEmail;
		$facturation_email = substr(apply_filters('gf_monetico_change_facturation_email', $facturation_email), 0, 100);
		$facturation_adresse_1 = rgar($submission_data, 'address');
		$facturation_adresse_1 = substr($this->sansCaracteresSpeciaux(apply_filters('gf_monetico_change_facturation_adresse_1', $facturation_adresse_1)), 0, 50);
		$facturation_adresse_2 = rgar($submission_data, 'address2');;
		$facturation_adresse_2 = substr($this->sansCaracteresSpeciaux(apply_filters('gf_monetico_change_facturation_adresse_2', $facturation_adresse_2)), 0, 50);
		$facturation_cp = rgar($submission_data, 'zip');;
		$facturation_cp = substr($this->sansCaracteresSpeciaux(apply_filters('gf_monetico_change_facturation_cp', $facturation_cp)), 0, 10);
		$facturation_ville = rgar($submission_data, 'city');;
		$facturation_ville = substr($this->sansCaracteresSpeciaux(apply_filters('gf_monetico_change_facturation_ville', $facturation_ville)), 0, 50);
		$facturation_pays = rgar($submission_data, 'country');;
		$facturation_pays = substr($this->sansCaracteresSpeciaux(apply_filters('gf_monetico_change_facturation_pays', $facturation_pays)), 0, 2);

		$sContexteCommande = $this->build_contexte_commande(array(
			'firstName'  => $facturation_prenom,
			'lastName'   => $facturation_nom,
			'email'      => $facturation_email,
			'address1'   => $facturation_adresse_1,
			'address2'   => $facturation_adresse_2,
			'city'       => $facturation_ville,
			'postalCode' => $facturation_cp,
			'country'    => $facturation_pays,
		));

		define("MONETICOPAIEMENT_KEY", $cle);
		define("MONETICOPAIEMENT_EPTNUMBER", $TPE);
		define("MONETICOPAIEMENT_COMPANYCODE", $societe);
		define("MONETICOPAIEMENT_URLOK", $url_retour_ok);
		define("MONETICOPAIEMENT_URLKO", $url_retour_err);

		require_once(MONETICO_GF_PATH . "monetico/MoneticoPaiement_Ept.inc.php");

		$oEpt = new MoneticoPaiement_Ept($sLangue);
		$oHmac = new MoneticoPaiement_Hmac($oEpt);

		$phase1go_fields = implode(
			'*',
			[
				"TPE={$oEpt->sNumero}",
				"ThreeDSecureChallenge=$ThreeDSecureChallenge",
				"contexte_commande=$sContexteCommande",
				"date=$sDate",
				"dateech1=$sDateEcheance1",
				"dateech2=$sDateEcheance2",
				"dateech3=$sDateEcheance3",
				"dateech4=$sDateEcheance4",
				"lgue={$oEpt->sLangue}",
				"mail=$sEmail",
				"montant=$sMontant{$sDevise}",
				"montantech1=$sMontantEcheance1",
				"montantech2=$sMontantEcheance2",
				"montantech3=$sMontantEcheance3",
				"montantech4=$sMontantEcheance4",
				"nbrech=$sNbrEch",
				"reference=$sReference",
				"societe={$oEpt->sCodeSociete}",
				"texte-libre=$sTexteLibre",
				"url_retour_err=$oEpt->sUrlKO",
				"url_retour_ok=$oEpt->sUrlOK",
				"version={$oEpt->sVersion}"
			]
		);
		$this->log_debug(__METHOD__ . "(): phase1go_fields: " . print_r($phase1go_fields, true));
		$sMAC = $oHmac->computeHmac($phase1go_fields); // MAC

		$payment_fields = $this->payment_form_fields(array(
			'version' => $oEpt->sVersion, 'TPE' => $oEpt->sNumero, 'date' => $sDate, 'contexte_commande' => $sContexteCommande,
			'ThreeDSecureChallenge' => $ThreeDSecureChallenge, 'montant' => $sMontant . $sDevise, 'reference' => $sReference,
			'url_retour_ok' => $oEpt->sUrlOK, 'url_retour_err' => $oEpt->sUrlKO, 'lgue' => $oEpt->sLangue, 'societe' => $oEpt->sCodeSociete,
			'mail' => $sEmail, 'texte-libre' => $sTexteLibre, 'MAC' => $sMAC,
		));

		// Store payment data for potential URL regeneration (failed/abandoned payments)
		$this->store_payment_data_for_regeneration($entry['id'], $feed, array(
			'amount'                => $sMontant,
			'currency'              => $sDevise,
			'email'                 => $sEmail,
			'language'              => $sLangue,
			'form_id'               => $form['id'],
			'reference'             => $sReference,
			'return_url'            => $return_url,
			'ThreeDSecureChallenge' => $ThreeDSecureChallenge,
			'billing' => array(
				'firstName'  => $facturation_prenom,
				'lastName'   => $facturation_nom,
				'email'      => $facturation_email,
				'address1'   => $facturation_adresse_1,
				'address2'   => $facturation_adresse_2,
				'city'       => $facturation_ville,
				'postalCode' => $facturation_cp,
				'country'    => $facturation_pays,
			),
		));

		/// Monetico attend un formulaire POST (doc. Page de paiement 1.5.2) : le client est redirigé vers une page
		/// locale qui le poste automatiquement. Le formulaire scellé est conservé une heure.
		$token = wp_generate_password(32, false);
		set_transient('monetico_gf_pay_' . $token, array('action' => $url . MONETICOPAIEMENT_URLPAYMENT, 'fields' => $payment_fields), HOUR_IN_SECONDS);

		return add_query_arg('moneticoPay', $token, home_url('/'));
	}

	/// Contexte de la commande pour 3DS2 : JSON UTF-8 encodé en base 64 (doc. Page de paiement 4.4).
	/// addressLine1, city, postalCode et country sont obligatoires ; une donnée facultative vide ne doit pas
	/// être envoyée en chaîne vide, elle est donc omise.
	private function build_contexte_commande($billing)
	{
		$contexte = array(
			'firstName'    => rgar($billing, 'firstName'),
			'lastName'     => rgar($billing, 'lastName'),
			'email'        => rgar($billing, 'email'),
			'addressLine1' => (string) rgar($billing, 'address1'),
			'addressLine2' => rgar($billing, 'address2'),
			'city'         => (string) rgar($billing, 'city'),
			'postalCode'   => (string) rgar($billing, 'postalCode'),
			'country'      => strtoupper((string) rgar($billing, 'country')),
		);
		foreach (array('firstName', 'lastName', 'email', 'addressLine2') as $optional) {
			if ('' === (string) $contexte[$optional]) {
				unset($contexte[$optional]);
			}
		}

		return base64_encode(wp_json_encode(array('billing' => $contexte), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}

	/**
	 * Store payment data for potential URL regeneration (failed/abandoned payments)
	 * Called from redirect_url() to save data needed to regenerate payment link
	 */
	private function store_payment_data_for_regeneration($entry_id, $feed, $data)
	{
		$payment_data = array(
			'payment_amount'        => $data['amount'],
			'currency_code'         => $data['currency'],
			'email'                 => $data['email'],
			'language'              => $data['language'],
			'form_id'               => $data['form_id'],
			'feed_id'               => $feed['id'],
			'reference'             => $data['reference'],
			'return_url'            => $data['return_url'],
			'ThreeDSecureChallenge' => $data['ThreeDSecureChallenge'],
			'billing'               => $data['billing'],
		);
		gform_update_meta($entry_id, 'monetico_payment_data', $payment_data);
	}

	/**
	 * Payment link for an entry (failed/abandoned payments), used by the merge tag and the entry detail box.
	 * The link is stable and signed; the payment form itself is generated when the customer opens it,
	 * with a fresh date and a new unique reference.
	 *
	 * @param int $entry_id The entry ID
	 * @return string|false The payment link or false when the entry has no stored payment data
	 */
	public function get_payment_link_url($entry_id)
	{
		if (empty(gform_get_meta($entry_id, 'monetico_payment_data'))) {
			return false;
		}

		return add_query_arg(array('moneticoLink' => (int) $entry_id, 'key' => $this->get_payment_link_key($entry_id)), home_url('/'));
	}

	private function get_payment_link_key($entry_id)
	{
		return wp_hash('monetico_payment_link|' . (int) $entry_id);
	}

	/**
	 * Build the sealed payment form for an entry (for failed/abandoned payments)
	 *
	 * @param int $entry_id The entry ID
	 * @return array|false array('action' => payment page URL, 'fields' => form fields) or false on failure
	 */
	private function build_link_payment_form($entry_id)
	{
		$entry = GFAPI::get_entry($entry_id);
		if (is_wp_error($entry)) {
			$this->log_error(__METHOD__ . '(): Could not get entry: ' . $entry->get_error_message());
			return false;
		}

		// Get stored payment data
		$payment_data = gform_get_meta($entry_id, 'monetico_payment_data');
		if (empty($payment_data)) {
			$this->log_error(__METHOD__ . '(): No payment data found for entry ' . $entry_id);
			return false;
		}

		// Get the feed
		$feed = $this->get_feed($payment_data['feed_id']);
		if (! $feed || ! rgar($feed, 'is_active')) {
			$this->log_error(__METHOD__ . '(): Feed not found or inactive for entry ' . $entry_id);
			return false;
		}

		// Get URL (Production or Sandbox)
		$url = $feed['meta']['mode'] == 'production' ? $this->production_url : $this->test_url;

		$TPE = $feed['meta']['tpe'];
		date_default_timezone_set('Europe/Paris');
		$sDate = date('d/m/Y:H:i:s'); // Fresh timestamp
		$sMontant = $payment_data['payment_amount'];
		$sDevise = $payment_data['currency_code'];
		/// Monetico exige une référence unique à chaque demande de paiement : {ID entrée}R{n}
		/// L'entrée est retrouvée au retour CGI2 par le texte-libre, qui garde l'ID seul
		$attempt = (int) gform_get_meta($entry_id, 'monetico_payment_attempt') + 1;
		gform_update_meta($entry_id, 'monetico_payment_attempt', $attempt);
		$sReference = $payment_data['reference'] . 'R' . $attempt;
		$sEmail = $payment_data['email'];
		$sLangue = $payment_data['language'];
		$societe = $feed['meta']['code_societe'];
		$cle = $feed['meta']['cle'];
		$sTexteLibre = $payment_data['reference'] . '|' . wp_hash($payment_data['reference']);
		$url_retour_ok = $payment_data['return_url'];
		$url_retour_err = $payment_data['return_url'];
		$ThreeDSecureChallenge = $payment_data['ThreeDSecureChallenge'];

		// Rebuild the 3DS2 context
		$billing = $payment_data['billing'];
		$sContexteCommande = $this->build_contexte_commande($billing);

		// Empty installment fields
		$sNbrEch = "";
		$sDateEcheance1 = "";
		$sMontantEcheance1 = "";
		$sDateEcheance2 = "";
		$sMontantEcheance2 = "";
		$sDateEcheance3 = "";
		$sMontantEcheance3 = "";
		$sDateEcheance4 = "";
		$sMontantEcheance4 = "";

		require_once(MONETICO_GF_PATH . 'monetico/MoneticoPaiement_Ept.inc.php');

		/// Pas de MoneticoPaiement_Ept ici : il lit des constantes qui peuvent déjà être définies dans la requête
		/// (retour CGI2 avec URLOK vide, renvoi groupé de plusieurs entrées) et fausseraient le MAC.
		/// MoneticoPaiement_Hmac n'a besoin que d'un objet exposant getCle().
		$oHmac = new MoneticoPaiement_Hmac(new class($cle) {
			private $cle;
			public function __construct($cle) { $this->cle = $cle; }
			public function getCle() { return $this->cle; }
		});
		$sVersion = MONETICOPAIEMENT_VERSION;

		$phase1go_fields = implode(
			'*',
			[
				"TPE=$TPE",
				"ThreeDSecureChallenge=$ThreeDSecureChallenge",
				"contexte_commande=$sContexteCommande",
				"date=$sDate",
				"dateech1=$sDateEcheance1",
				"dateech2=$sDateEcheance2",
				"dateech3=$sDateEcheance3",
				"dateech4=$sDateEcheance4",
				"lgue=$sLangue",
				"mail=$sEmail",
				"montant=$sMontant{$sDevise}",
				"montantech1=$sMontantEcheance1",
				"montantech2=$sMontantEcheance2",
				"montantech3=$sMontantEcheance3",
				"montantech4=$sMontantEcheance4",
				"nbrech=$sNbrEch",
				"reference=$sReference",
				"societe=$societe",
				"texte-libre=$sTexteLibre",
				"url_retour_err=$url_retour_err",
				"url_retour_ok=$url_retour_ok",
				"version=$sVersion"
			]
		);

		$this->log_debug(__METHOD__ . '(): Building payment link form, phase1go_fields: ' . print_r($phase1go_fields, true));
		$sMAC = $oHmac->computeHmac($phase1go_fields);

		return array(
			'action' => $url . MONETICOPAIEMENT_URLPAYMENT,
			'fields' => $this->payment_form_fields(array(
				'version' => $sVersion, 'TPE' => $TPE, 'date' => $sDate, 'contexte_commande' => $sContexteCommande,
				'ThreeDSecureChallenge' => $ThreeDSecureChallenge, 'montant' => $sMontant . $sDevise, 'reference' => $sReference,
				'url_retour_ok' => $url_retour_ok, 'url_retour_err' => $url_retour_err, 'lgue' => $sLangue, 'societe' => $societe,
				'mail' => $sEmail, 'texte-libre' => $sTexteLibre, 'MAC' => $sMAC,
			)),
		);
	}

	/// Champs du formulaire de paiement : les champs de paiement fractionné, scellés vides, sont envoyés vides
	private function payment_form_fields($fields)
	{
		return $fields + array('dateech1' => '', 'dateech2' => '', 'dateech3' => '', 'dateech4' => '', 'montantech1' => '', 'montantech2' => '', 'montantech3' => '', 'montantech4' => '', 'nbrech' => '');
	}

	/// Page locale ?moneticoPay={jeton} (paiement initial) ou ?moneticoLink={ID}&key={signature} (lien de paiement renvoyé) :
	/// affiche le formulaire scellé et le poste automatiquement vers Monetico
	public function maybe_render_payment_form()
	{
		if (isset($_GET['moneticoPay'])) {
			$token = sanitize_key(wp_unslash($_GET['moneticoPay']));
			$form = '' !== $token ? get_transient('monetico_gf_pay_' . $token) : false;
			if (empty($form['action'])) {
				$this->payment_page_error(__('This payment page has expired. Please submit the form again.', 'monetico-for-gravity-forms'), 410);
			}
			$this->render_payment_form($form['action'], $form['fields']);
		}

		if (isset($_GET['moneticoLink'])) {
			$entry_id = absint($_GET['moneticoLink']);
			$key = isset($_GET['key']) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
			if (!$entry_id || !hash_equals($this->get_payment_link_key($entry_id), $key)) {
				$this->payment_page_error(__('This payment link is invalid.', 'monetico-for-gravity-forms'), 403);
			}

			$entry = GFAPI::get_entry($entry_id);
			if (is_wp_error($entry)) {
				$this->payment_page_error(__('This payment link is invalid.', 'monetico-for-gravity-forms'), 404);
			}
			if (in_array(rgar($entry, 'payment_status'), array('Paid', 'Approved', 'Refunded'), true)) {
				$this->payment_page_error(__('This payment has already been made. Thank you!', 'monetico-for-gravity-forms'), 200);
			}

			$form = $this->build_link_payment_form($entry_id);
			if (!$form) {
				$this->payment_page_error(__('This payment link can no longer be used. Please contact the site owner.', 'monetico-for-gravity-forms'), 410);
			}
			$this->render_payment_form($form['action'], $form['fields']);
		}
	}

	private function payment_page_error($message, $status)
	{
		nocache_headers();
		wp_die(esc_html($message), esc_html__('Monetico payment', 'monetico-for-gravity-forms'), array('response' => $status));
	}

	private function render_payment_form($action, $fields)
	{
		nocache_headers();
		header('Content-Type: text/html; charset=utf-8');
		header('X-Robots-Tag: noindex, nofollow');
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?php esc_html_e('Monetico payment', 'monetico-for-gravity-forms'); ?></title>
<style>body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;min-height:90vh;align-items:center;justify-content:center;text-align:center;padding:16px;color:#1d2327}button{font-size:16px;padding:10px 20px;cursor:pointer}</style>
</head>
<body>
<form id="monetico-payment-form" method="post" action="<?php echo esc_url($action); ?>" accept-charset="UTF-8" target="_top">
<?php foreach ($fields as $name => $value) : ?>
<input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
<?php endforeach; ?>
<p><?php esc_html_e('Redirecting to the Monetico secure payment page…', 'monetico-for-gravity-forms'); ?></p>
<p><button type="submit"><?php esc_html_e('Continue to payment', 'monetico-for-gravity-forms'); ?></button></p>
</form>
<script>document.getElementById('monetico-payment-form').submit();</script>
</body>
</html>
<?php
		exit;
	}


	/**
	 * Add Monetico payment link merge tag to the merge tags dropdown
	 */
	public function add_monetico_merge_tags($merge_tags, $form_id, $fields, $element_id)
	{
		$merge_tags[] = array(
			'label' => esc_html__('Monetico Payment Link', 'monetico-for-gravity-forms'),
			'tag'   => '{monetico_payment_link}',
		);
		return $merge_tags;
	}

	/**
	 * Replace the Monetico payment link merge tag with the actual regenerated URL
	 */
	public function replace_monetico_merge_tags($text, $form, $entry, $url_encode, $esc_html, $nl2br, $format)
	{
		if (strpos($text, '{monetico_payment_link}') === false) {
			return $text;
		}

		$payment_url = '';
		if (! empty($entry['id'])) {
			$payment_url = $this->get_payment_link_url($entry['id']);
			if (! $payment_url) {
				$payment_url = '';
			}
		}

		if ($url_encode) {
			$payment_url = urlencode($payment_url);
		} elseif ($esc_html) {
			$payment_url = esc_html($payment_url);
		}

		return str_replace('{monetico_payment_link}', $payment_url, $text);
	}

	/**
	 * Display payment link meta box on entry detail page for entries with incomplete payment
	 */
	public function entry_detail_payment_link_box($form, $entry)
	{
		// Only show for entries with Processing or Failed payment status
		$payment_status = rgar($entry, 'payment_status');
		if (! in_array($payment_status, array('Processing', 'Failed', 'Pending'))) {
			return;
		}

		// Check if we have payment data stored
		$payment_data = gform_get_meta($entry['id'], 'monetico_payment_data');
		if (empty($payment_data)) {
			return;
		}

		$payment_url = $this->get_payment_link_url($entry['id']);
		if (! $payment_url) {
			return;
		}

		?>
		<div class="postbox">
			<h3 class="hndle" style="cursor:default;">
				<span><?php esc_html_e('Monetico Payment Link', 'monetico-for-gravity-forms'); ?></span>
			</h3>
			<div class="inside">
				<p><?php esc_html_e('This entry has an incomplete payment. Use the link below to resend to the customer or use the {monetico_payment_link} merge tag in a notification.', 'monetico-for-gravity-forms'); ?></p>
				<p>
					<input type="text" id="monetico_payment_link" value="<?php echo esc_url($payment_url); ?>" readonly style="width:100%;" onclick="this.select();" />
				</p>
				<p>
					<button type="button" class="button" onclick="
						var link = document.getElementById('monetico_payment_link');
						link.select();
						document.execCommand('copy');
						alert('<?php echo esc_js(__('Payment link copied to clipboard!', 'monetico-for-gravity-forms')); ?>');
					"><?php esc_html_e('Copy Link', 'monetico-for-gravity-forms'); ?></button>
				</p>
				<p class="description">
					<?php printf(esc_html__('Amount: %s %s', 'monetico-for-gravity-forms'), esc_html($payment_data['payment_amount']), esc_html($payment_data['currency_code'])); ?>
				</p>
			</div>
		</div>
<?php
	}

	//----- REMBOURSEMENT (service recredit_paiement.cgi) ----------//

	/**
	 * Display refund box on entry detail page for paid entries
	 */
	public function entry_detail_refund_box($form, $entry)
	{
		if (! in_array(rgar($entry, 'payment_status'), array('Paid', 'Approved')) || rgar($entry, 'payment_method') != 'Monetico') {
			return;
		}
		if (! GFCommon::current_user_can_any($this->_capabilities_settings_page)) {
			return;
		}

		$paid      = (float) $entry['payment_amount'];
		$refunded  = (float) gform_get_meta($entry['id'], 'monetico_refunded_amount');
		$remaining = round($paid - $refunded, 2);
		if ($remaining <= 0) {
			return;
		}

		?>
		<div class="postbox">
			<h3 class="hndle" style="cursor:default;">
				<span><?php esc_html_e('Monetico Refund', 'monetico-for-gravity-forms'); ?></span>
			</h3>
			<div class="inside">
				<p class="description">
					<?php printf(esc_html__('Refundable amount: %s', 'monetico-for-gravity-forms'), esc_html(GFCommon::to_money($remaining, $entry['currency']))); ?>
					<?php if ($refunded > 0) {
						echo '<br />' . sprintf(esc_html__('Already refunded: %s', 'monetico-for-gravity-forms'), esc_html(GFCommon::to_money($refunded, $entry['currency'])));
					} ?>
				</p>
				<p>
					<label for="monetico_refund_amount"><?php esc_html_e('Amount to refund', 'monetico-for-gravity-forms'); ?></label><br />
					<input type="text" id="monetico_refund_amount" value="<?php echo esc_attr(number_format($remaining, 2, '.', '')); ?>" style="width:100%;" />
				</p>
				<p>
					<button type="button" class="button" id="monetico_refund_button"><?php esc_html_e('Refund', 'monetico-for-gravity-forms'); ?></button>
					<span id="monetico_refund_result"></span>
				</p>
				<p class="description"><?php esc_html_e('The refund option must be enabled on your TPE, and in production the IP address of this server must be declared to Monetico technical support.', 'monetico-for-gravity-forms'); ?></p>
			</div>
		</div>
		<script>
			jQuery(function($) {
				$('#monetico_refund_button').on('click', function() {
					var $button = $(this), amount = $('#monetico_refund_amount').val();
					if (!confirm(<?php echo wp_json_encode(__('Refund this amount to the customer? This cannot be undone.', 'monetico-for-gravity-forms')); ?> + ' (' + amount + ')')) {
						return;
					}
					$button.prop('disabled', true);
					$('#monetico_refund_result').text('…');
					$.post(ajaxurl, {
						action: 'gf_monetico_refund',
						entry_id: <?php echo (int) $entry['id']; ?>,
						amount: amount,
						nonce: <?php echo wp_json_encode(wp_create_nonce('gf_monetico_refund_' . $entry['id'])); ?>
					}).done(function(response) {
						if (response.success) {
							window.location.reload();
						} else {
							$('#monetico_refund_result').text(response.data);
							$button.prop('disabled', false);
						}
					}).fail(function() {
						$('#monetico_refund_result').text(<?php echo wp_json_encode(__('The request failed. Check the entry notes before trying again.', 'monetico-for-gravity-forms')); ?>);
						$button.prop('disabled', false);
					});
				});
			});
		</script>
<?php
	}

	/**
	 * AJAX handler of the refund button
	 */
	public function ajax_refund()
	{
		$entry_id = absint(rgpost('entry_id'));
		if (! check_ajax_referer('gf_monetico_refund_' . $entry_id, 'nonce', false) || ! GFCommon::current_user_can_any($this->_capabilities_settings_page)) {
			wp_send_json_error(__('You are not allowed to refund this payment.', 'monetico-for-gravity-forms'));
		}

		$entry = GFAPI::get_entry($entry_id);
		if (is_wp_error($entry)) {
			wp_send_json_error($entry->get_error_message());
		}

		$result = $this->refund_monetico_payment($entry, rgpost('amount'));
		if (is_wp_error($result)) {
			wp_send_json_error($result->get_error_message());
		}
		wp_send_json_success();
	}

	/**
	 * Refund all or part of a paid entry through the Monetico recredit_paiement service
	 *
	 * @param array        $entry  The entry
	 * @param string|float $amount Amount to refund, "12,50" or "12.50"
	 * @return true|WP_Error
	 */
	public function refund_monetico_payment($entry, $amount)
	{
		$feed = $this->get_payment_feed($entry);
		if (! $feed) {
			return new WP_Error('monetico_refund', __('The Monetico feed of this entry no longer exists.', 'monetico-for-gravity-forms'));
		}

		$amount    = round((float) str_replace(',', '.', trim((string) $amount)), 2);
		$paid      = (float) $entry['payment_amount'];
		$refunded  = (float) gform_get_meta($entry['id'], 'monetico_refunded_amount');
		$remaining = round($paid - $refunded, 2);
		if ($amount <= 0 || $amount > $remaining) {
			return new WP_Error('monetico_refund', sprintf(__('The amount must be between 0.01 and %s.', 'monetico-for-gravity-forms'), number_format($remaining, 2, '.', '')));
		}

		/// Entrées payées avant la 1.0.7 : la référence vaut l'ID de l'entrée si aucun lien n'a été renvoyé,
		/// et la date de commande est déduite de la date de paiement
		$reference = gform_get_meta($entry['id'], 'monetico_paid_reference');
		if (! $reference) {
			if (gform_get_meta($entry['id'], 'monetico_payment_attempt')) {
				return new WP_Error('monetico_refund', __('The Monetico reference of this payment is unknown (payment link resent before version 1.0.7). Refund it from the Monetico dashboard.', 'monetico-for-gravity-forms'));
			}
			$reference = (string) $entry['id'];
		}
		$date_commande = gform_get_meta($entry['id'], 'monetico_order_date');
		if (! $date_commande) {
			$date_commande = wp_date('d/m/Y', strtotime($entry['payment_date'] . ' UTC'), new DateTimeZone('Europe/Paris'));
		}

		$payment_data = gform_get_meta($entry['id'], 'monetico_payment_data');
		$langue = strtoupper(rgar($payment_data, 'language'));
		if (! in_array($langue, array('DE', 'EN', 'ES', 'FR', 'IT', 'JA', 'NL', 'PT', 'SV'))) {
			$langue = 'FR';
		}

		$devise = $entry['currency'];
		/// Remboursement global (paiement carte / Apple Pay) : sans num_autorisation ni date_remise,
		/// que le retour CGI2 ne fournit pas, mais avec le montant déjà remboursé
		$fields = array(
			'TPE'                    => $feed['meta']['tpe'],
			'date'                   => wp_date('d/m/Y:H:i:s', null, new DateTimeZone('Europe/Paris')),
			'date_commande'          => $date_commande,
			'lgue'                   => $langue,
			'montant'                => number_format($paid, 2, '.', '') . $devise,
			'montant_deja_recredite' => number_format($refunded, 2, '.', '') . $devise,
			'montant_recredit'       => number_format($amount, 2, '.', '') . $devise,
			'reference'              => $reference,
			'societe'                => $feed['meta']['code_societe'],
			'version'                => '3.0',
		);
		$fields['MAC'] = $this->compute_service_mac($fields, $feed['meta']['cle']);

		$url = $feed['meta']['mode'] == 'production' ? 'https://payment-api.e-i.com/recredit_paiement.cgi' : 'https://payment-api.e-i.com/test/recredit_paiement.cgi';
		$this->log_debug(__METHOD__ . "(): Refund request for entry {$entry['id']}: " . print_r($fields, true));

		$response = wp_remote_post($url, array('body' => $fields, 'timeout' => 45));
		if (is_wp_error($response)) {
			$this->log_error(__METHOD__ . '(): ' . $response->get_error_message());
			return new WP_Error('monetico_refund', sprintf(__('Monetico could not be reached: %s', 'monetico-for-gravity-forms'), $response->get_error_message()));
		}

		$body = wp_remote_retrieve_body($response);
		$this->log_debug(__METHOD__ . "(): Refund response for entry {$entry['id']}: " . $body);
		$result = $this->parse_service_response($body);
		$cdr = rgar($result, 'cdr');

		if ($cdr !== '0') {
			$message = $this->get_refund_error_message($cdr, rgar($result, 'lib'));
			$this->add_note($entry['id'], sprintf(__('Monetico refund of %s refused: %s', 'monetico-for-gravity-forms'), GFCommon::to_money($amount, $devise), $message));
			return new WP_Error('monetico_refund', $message);
		}

		$refunded = round($refunded + $amount, 2);
		gform_update_meta($entry['id'], 'monetico_refunded_amount', $refunded);

		$user = wp_get_current_user();
		$action = array(
			'type'           => 'refund_payment',
			'transaction_id' => $entry['transaction_id'],
			'entry_id'       => $entry['id'],
			'amount'         => $amount,
			/// Remboursement partiel : l'entrée reste payée
			'payment_status' => $refunded >= $paid ? 'Refunded' : $entry['payment_status'],
			'note'           => sprintf(__('Monetico refund of %s accepted (total refunded: %s). Monetico reference: %s. By: %s', 'monetico-for-gravity-forms'), GFCommon::to_money($amount, $devise), GFCommon::to_money($refunded, $devise), $reference, $user->exists() ? $user->display_name : 'System'),
		);
		$this->refund_payment($entry, $action);

		return true;
	}

	/// Sceau des services Monetico (remboursement, capture…) : tous les champs envoyés, triés par ordre ASCII des noms,
	/// sous la forme nom=valeur séparés par *, en HMAC-SHA1 avec la clé du TPE
	private function compute_service_mac($fields, $cle)
	{
		require_once(MONETICO_GF_PATH . 'monetico/MoneticoPaiement_Ept.inc.php');

		ksort($fields, SORT_STRING);
		$pairs = array();
		foreach ($fields as $name => $value) {
			$pairs[] = "$name=$value";
		}

		$oHmac = new MoneticoPaiement_Hmac(new class($cle) {
			private $cle;
			public function __construct($cle) { $this->cle = $cle; }
			public function getCle() { return $this->cle; }
		});
		return $oHmac->computeHmac(implode('*', $pairs));
	}

	/// Réponse texte des services Monetico : une ligne nom=valeur par champ
	private function parse_service_response($body)
	{
		$result = array();
		foreach (preg_split('/\r\n|\r|\n/', trim((string) $body)) as $line) {
			$pos = strpos($line, '=');
			if ($pos !== false) {
				$result[trim(substr($line, 0, $pos))] = trim(substr($line, $pos + 1));
			}
		}
		return $result;
	}

	private function get_refund_error_message($cdr, $lib)
	{
		$messages = array(
			'-1'  => __('The refund was refused.', 'monetico-for-gravity-forms'),
			'-30' => __('Merchant not identified: check the TPE and company code of the feed.', 'monetico-for-gravity-forms'),
			'-31' => __('Invalid MAC: check the key of the feed.', 'monetico-for-gravity-forms'),
			'-32' => __('Your TPE is not allowed to make refunds, or the IP address of this server is not declared: contact Monetico technical support.', 'monetico-for-gravity-forms'),
			'-33' => __('The refund request has expired: check the date and time of the server.', 'monetico-for-gravity-forms'),
			'-35' => __('The amounts do not match those of the bank: a refund may have been made from the Monetico dashboard.', 'monetico-for-gravity-forms'),
			'-36' => __('The maximum number of refunds of your TPE has been reached.', 'monetico-for-gravity-forms'),
			'-37' => __('The order does not exist at Monetico.', 'monetico-for-gravity-forms'),
			'-41' => __('Monetico technical problem: try again later.', 'monetico-for-gravity-forms'),
			'-44' => __('Another operation is in progress on this order: try again later.', 'monetico-for-gravity-forms'),
			'-46' => __('The order is already fully refunded.', 'monetico-for-gravity-forms'),
			'-51' => __('A global refund is not allowed for this order: refund it from the Monetico dashboard.', 'monetico-for-gravity-forms'),
			'-52' => __('The amount already refunded does not match the bank: a refund may have been made from the Monetico dashboard.', 'monetico-for-gravity-forms'),
		);
		if ($cdr === '' || $cdr === null) {
			return __('Unexpected response from Monetico. See the add-on logs.', 'monetico-for-gravity-forms');
		}
		$message = isset($messages[$cdr]) ? $messages[$cdr] : sanitize_text_field($lib);
		return sprintf('%s (cdr %s)', $message, sanitize_text_field($cdr));
	}

	private function sansCaracteresSpeciaux($str0)
	{
		$str0 = preg_replace('#[^[:alnum:]]#u', ' ', $str0);
		$str0 = trim($str0);
		$str0 = str_replace('###antiSlashe###t', ' ', $str0); // tabulations
		$str0 = preg_replace('!\s+!', ' ', $str0); // espaces multiples
		return $str0;
	}
	public function get_product_query_string($submission_data, $entry_id)
	{

		if (empty($submission_data)) {
			return false;
		}

		$query_string   = '';
		$payment_amount = rgar($submission_data, 'payment_amount');
		$setup_fee      = rgar($submission_data, 'setup_fee');
		$trial_amount   = rgar($submission_data, 'trial');
		$line_items     = rgar($submission_data, 'line_items');
		$discounts      = rgar($submission_data, 'discounts');

		$product_index = 1;
		$shipping      = '';
		$discount_amt  = 0;
		$cmd           = '_cart';
		$extra_qs      = '&upload=1';

		//work on products
		if (is_array($line_items)) {
			foreach ($line_items as $item) {
				$product_name = urlencode($item['name']);
				$quantity     = $item['quantity'];
				$unit_price   = $item['unit_price'];
				$options      = rgar($item, 'options');
				$product_id   = $item['id'];
				$is_shipping  = rgar($item, 'is_shipping');

				if ($is_shipping) {
					//populate shipping info
					$shipping .= ! empty($unit_price) ? "&shipping_1={$unit_price}" : '';
				} else {
					//add product info to querystring
					$query_string .= "&item_name_{$product_index}={$product_name}&amount_{$product_index}={$unit_price}&quantity_{$product_index}={$quantity}";
				}
				//add options
				if (! empty($options)) {
					if (is_array($options)) {
						$option_index = 1;
						foreach ($options as $option) {
							// Trim option label to prevent PayPal displaying an error instead of the cart.
							$option_label = urlencode(substr($option['field_label'], 0, 64));
							$option_name  = urlencode($option['option_name']);
							$query_string .= "&on{$option_index}_{$product_index}={$option_label}&os{$option_index}_{$product_index}={$option_name}";
							$option_index++;
						}
					}
				}
				$product_index++;
			}
		}

		//look for discounts
		if (is_array($discounts)) {
			foreach ($discounts as $discount) {
				$discount_full = abs($discount['unit_price']) * $discount['quantity'];
				$discount_amt += $discount_full;
			}
			if ($discount_amt > 0) {
				$query_string .= "&discount_amount_cart={$discount_amt}";
			}
		}

		$query_string .= "{$shipping}&cmd={$cmd}{$extra_qs}";

		//save payment amount to lead meta
		gform_update_meta($entry_id, 'payment_amount', $payment_amount);

		return $payment_amount > 0 ? $query_string : false;
	}

	/// Génération de l'URL de retour (pas nécessaire pour Monetico mais pour les autres passerelles)
	public function return_url($form_id, $lead_id)
	{
		$pageURL = GFCommon::is_ssl() ? 'https://' : 'http://';

		$server_port = apply_filters('gform_monetico_return_url_port', $_SERVER['SERVER_PORT']);

		if ($server_port != '80') {
			$pageURL .= $_SERVER['SERVER_NAME'] . ':' . $server_port . $_SERVER['REQUEST_URI'];
		} else {
			$pageURL .= $_SERVER['SERVER_NAME'] . $_SERVER['REQUEST_URI'];
		}

		$ids_query = "ids={$form_id}|{$lead_id}";
		$ids_query .= '&hash=' . wp_hash($ids_query);

		$url = add_query_arg('gf_monetico_return', base64_encode($ids_query), $pageURL);

		$query = 'gf_monetico_return=' . base64_encode($ids_query);
		/**
		 * Filters PayPal's return URL, which is the URL that users will be sent to after completing the payment on PayPal's site.
		 * Useful when URL isn't created correctly (could happen on some server configurations using PROXY servers).
		 *
		 * @since 2.4.5
		 *
		 * @param string  $url 	The URL to be filtered.
		 * @param int $form_id	The ID of the form being submitted.
		 * @param int $entry_id	The ID of the entry that was just created.
		 * @param string $query	The query string portion of the URL.
		 */
		return apply_filters('gform_monetico_return_url', $url, $form_id, $lead_id, $query);
	}
	/// Page de retour client de la banque
	public static function maybe_thankyou_page()
	{
		$instance = self::get_instance();

		if (! $instance->is_gravityforms_supported()) {
			return;
		}
		if ($str = rgget('gf_monetico_return')) {
			$str = base64_decode($str);

			parse_str($str, $query);
			if (wp_hash('ids=' . $query['ids']) == $query['hash']) {
				list($form_id, $lead_id) = explode('|', $query['ids']);

				$form = GFAPI::get_form($form_id);
				$lead = GFAPI::get_entry($lead_id);
				// Le flux associé à l’entrée
				$feed = $instance->get_payment_feed($lead);

				if (! class_exists('GFFormDisplay')) {
					require_once(GFCommon::get_base_path() . '/form_display.php');
				}

				$confirmation = GFFormDisplay::handle_confirmation($form, $lead, false);
				if (is_array($confirmation) && isset($confirmation['redirect'])) {
					header("Location: {$confirmation['redirect']}");
					exit;
				}
				$payment_status = rgar($lead, 'payment_status');
				switch ($payment_status): // Authorized, Paid, Processing, Pending, Active, Expired, Failed, Cancelled, Approved, Reversed, Refunded, Voided
					case "Failed":
						$confirmation = "<div class='gform_confirmation_wrapper'><div class='gform_confirmation_message'>" . nl2br($feed['meta']['erreur_message']) . "</div></div>"; // $instance->get_setting('erreur_message')
						break;
					case "Processing":
						$confirmation = "<div class='gform_confirmation_wrapper'><div class='gform_confirmation_message'>" . nl2br($feed['meta']['annulation_message']) . "</div></div>";
						break;
				endswitch;
				GFFormDisplay::$submission[$form_id] = array('is_confirmation' => true, 'confirmation_message' => $confirmation, 'form' => $form, 'lead' => $lead);
			}
		}
	}

	public function delay_post($is_disabled, $form, $entry)
	{

		$feed            = $this->get_payment_feed($entry);
		$submission_data = $this->get_submission_data($feed, $form, $entry);

		if (! $feed || empty($submission_data['payment_amount'])) {
			return $is_disabled;
		}

		return ! rgempty('delayPost', $feed['meta']);
	}

	public function delay_notification($is_disabled, $notification, $form, $entry)
	{
		if (rgar($notification, 'event') != 'form_submission') {
			return $is_disabled;
		}

		$feed            = $this->get_payment_feed($entry);
		$submission_data = $this->get_submission_data($feed, $form, $entry);

		if (! $feed || empty($submission_data['payment_amount'])) {
			return $is_disabled;
		}

		$selected_notifications = is_array(rgar($feed['meta'], 'selectedNotifications')) ? rgar($feed['meta'], 'selectedNotifications') : array();

		return isset($feed['meta']['delayNotification']) && in_array($notification['id'], $selected_notifications) ? true : $is_disabled;
	}
	
	
	/// Ajout de Guilamu, récupération depuis class-gf-payment-addon.php
	
	/*
	maybe_process_callback() Appelé par un hook
		is_callback_valid() On vérifie si c'est bien l'URL retour automatique de la banque
		callback() Renvoi un tableau sur un switch de l'état du paiement (paiement, paytest, Annulation, ...)
			verify_monetico_ipn() On vérifie la clé HMAC
			can_process_ipn() On peut vérifier d'autres critères et filtrer le processus
			process_ipn() Traite les données reçues en fonction de l'état du paiement et renvoi un tableau
		process_callback_action() Applique le tableau pour valider ou annuler le paiement
		post_callback()
	*/
	//------- PROCESSING MONETICO IPN (Callback) -----------//


	/// il faut outrepasser la fonction maybe_process_callback() de class-gf-payment-addon.php
	/// car cette fonction gère le retour de l'ipn (affichage + die())

	// Cette fonction existe dans class-gf-payment-addon.php mais elle est vide, outrepassée ici
	// idem pour post_callback
	public function callback()
	{

		if (! $this->is_gravityforms_supported()) {
			return false;
		}

		$this->log_debug(__METHOD__ . '(): IPN request received. Starting to process => ' . print_r($_POST, true));

		// Valid IPN requests must have a reference field
		$custom_field = rgpost('texte-libre');
		if (empty($custom_field)) {
			$this->log_error(__METHOD__ . '(): IPN request does not have a reference field, so it was not created by Gravity Forms. Aborting.');
			/// Pour Monetico nous renvoyons tout de suite la réponse d'échec à Monetico
			/// parceque si l'on renvoi false nous aurons un message qui ne sera pas valide pour Monetico
			$receipt = MONETICOPAIEMENT_PHASE2BACK_MACNOTOK . "IPN request does not have a reference field, so it was not created by Gravity Forms.";
			$reponse = sprintf(MONETICOPAIEMENT_PHASE2BACK_RECEIPT, $receipt);
			echo $reponse;
			die();
		}

		//------- On va vérifier le HMAC du retour CGI2 ---------------------//
		$is_verified = $this->verify_monetico_ipn();
		/// Guilamu
		/// Pour Monetico on ne va pas revenir en erreur WP ni en false. 
		/// C'est du au fait que Monetico attend un retour en cas d'erreur 
		/// et qu'il est plus simple d'envoyer l'erreur en amont dans verify_monetico_ipn()
		$this->log_debug(__METHOD__ . '(): HMAC keys match.');

		/// Sceau validé : Monetico attend exactement « version=2 / cdr=0 », quel que soit le code-retour
		/// (doc. Page de paiement 1.5.3.3). Tout ce que Gravity Forms affiche ensuite (« Callback bypassed »,
		/// doublon, etc.) est mis en tampon puis jeté par send_monetico_ack().
		$this->ack_ob_level = ob_get_level();
		ob_start();


		//------ Getting entry related to this IPN ----------------------------------------------//
		/// On doit récupérer l'identifiant de l'entrée du formulaire, à voir depuis quel champ, +référence+, -texte libre-
		$entry = $this->get_entry($custom_field);

		//Ignore orphan IPN messages (ones without an entry)
		if (! $entry) {
			$this->log_error(__METHOD__ . '(): Entry could not be found. Aborting.');
			return false;
		}
		$this->log_debug(__METHOD__ . '(): Entry has been found => ' . print_r($entry, true));

		if ($entry['status'] == 'spam') {
			$this->log_error(__METHOD__ . '(): Entry is marked as spam. Aborting.');
			return false;
		}

		//------ Getting feed related to this IPN ------------------------------------------//
		$feed = $this->get_payment_feed($entry);

		//Ignore IPN messages from forms that are no longer configured with the Monetico add-on
		if (! $feed || ! rgar($feed, 'is_active')) {
			$this->log_error(__METHOD__ . "(): Form no longer is configured with Monetico Addon. Form ID: {$entry['form_id']}. Aborting.");
			return false;
		}
		$this->log_debug(__METHOD__ . "(): Form {$entry['form_id']} is properly configured.");

		//----- Making sure this IPN can be processed -------------------------------------//
		/// On peut ajouter quelques vérifications et il y a un filtre pour annuler le processus
		if (! $this->can_process_ipn($feed, $entry)) {
			$this->log_debug(__METHOD__ . '(): IPN cannot be processed.');
			return false;
		}


		//----- Processing IPN ------------------------------------------------------------//
		$this->log_debug(__METHOD__ . '(): Processing IPN...');
		$action = $this->process_ipn($feed, $entry, rgpost('code-retour'), rgpost('motifrefus'), rgpost('filtragecause'), rgpost('filtragevaleur'), rgpost('authentification'), rgpost('numauto'), rgpost('montant'), rgpost('reference'));
		$this->log_debug(__METHOD__ . '(): IPN processing complete.');

		if (rgempty('entry_id', $action)) {
			return false;
		}

		return $action;
	}

	public function get_payment_feed($entry, $form = false)
	{

		$feed = parent::get_payment_feed($entry, $form);


		$feed = apply_filters('gform_monetico_get_payment_feed', $feed, $entry, $form ? $form : GFAPI::get_form($entry['form_id']));

		return $feed;
	}

	// Cette fonction existe dans class-gf-payment-addon.php mais elle est vide, outrepassée ici
	public function post_callback($callback_action, $callback_result)
	{
		if (is_wp_error($callback_action) || ! $callback_action || rgempty('entry_id', $callback_action)) {
			$this->send_monetico_ack();
			return false;
		}

		//run the necessary hooks
		$entry          = GFAPI::get_entry($callback_action['entry_id']);
		$feed           = $this->get_payment_feed($entry);
		$transaction_id = rgar($callback_action, 'transaction_id');
		$amount         = rgar($callback_action, 'amount');
		$subscriber_id  = rgar($callback_action, 'subscriber_id');
		$pending_reason = rgpost('pending_reason');
		$reason         = rgpost('reason_code');
		$status         = rgpost('payment_status');
		$txn_type       = rgpost('txn_type');
		$parent_txn_id  = rgpost('parent_txn_id');

		//run gform_monetico_fulfillment only in certain conditions
		if (rgar($callback_action, 'ready_to_fulfill') && ! rgar($callback_action, 'abort_callback')) {
			$this->fulfill_order($entry, $transaction_id, $amount, $feed);
		} else {
			if (rgar($callback_action, 'abort_callback')) {
				$this->log_debug(__METHOD__ . '(): Callback processing was aborted. Not fulfilling entry.');
			} else {
				$this->log_debug(__METHOD__ . '(): Entry is already fulfilled or not ready to be fulfilled, not running gform_monetico_fulfillment hook.');
			}
		}
		do_action('gform_post_payment_status', $feed, $entry, $status, $transaction_id, $subscriber_id, $amount, $pending_reason, $reason);
		if (has_filter('gform_post_payment_status')) {
			$this->log_debug(__METHOD__ . '(): Executing functions hooked to gform_post_payment_status.');
		}

		do_action('gform_monetico_ipn_' . $txn_type, $entry, $feed, $status, $txn_type, $transaction_id, $parent_txn_id, $subscriber_id, $amount, $pending_reason, $reason);
		if (has_filter('gform_monetico_ipn_' . $txn_type)) {
			$this->log_debug(__METHOD__ . "(): Executing functions hooked to gform_monetico_ipn_{$txn_type}.");
		}

		do_action('gform_monetico_post_ipn', $_POST, $entry, $feed, false);
		if (has_filter('gform_monetico_post_ipn')) {
			$this->log_debug(__METHOD__ . '(): Executing functions hooked to gform_monetico_post_ipn.');
		}

		$this->send_monetico_ack();
	}

	/// Accusé de réception d'un retour CGI2 dont le sceau est valide : le tampon ouvert dans callback()
	/// est vidé pour que la réponse soit exactement celle attendue par Monetico
	private function send_monetico_ack()
	{
		if (null === $this->ack_ob_level) {
			return;
		}

		while (ob_get_level() > $this->ack_ob_level) {
			ob_end_clean();
		}
		$this->ack_ob_level = null;

		if (!headers_sent()) {
			header('Content-Type: text/plain; charset=utf-8');
		}
		echo sprintf(MONETICOPAIEMENT_PHASE2BACK_RECEIPT, MONETICOPAIEMENT_PHASE2BACK_MACOK);
	}
	/// Guilamu, c'est ici que l'on va calculer le HMAC
	private function verify_monetico_ipn()
	{ // 3DS2 compatible

		require_once(MONETICO_GF_PATH . "monetico/MoneticoPaiement_Ept.inc.php");

		$MoneticoPaiement_bruteVars = getMethode();

		/// La référence peut valoir {ID}R{n} (lien de paiement renvoyé) : l'ID de l'entrée est pris dans le texte-libre {ID}|{hash}
		$texte_libre = isset($MoneticoPaiement_bruteVars['texte-libre']) ? $MoneticoPaiement_bruteVars['texte-libre'] : '';
		$entry_id = (int) strtok($texte_libre, '|');
		$entry          = GFAPI::get_entry($entry_id);
		$feed           = $this->get_payment_feed($entry);
		if (!defined('MONETICOPAIEMENT_KEY')) define("MONETICOPAIEMENT_KEY", apply_filters('gf_monetico_change_cle', $feed['meta']['cle']));
		if (!defined('MONETICOPAIEMENT_EPTNUMBER')) define("MONETICOPAIEMENT_EPTNUMBER", apply_filters('gf_monetico_change_tpe', $feed['meta']['tpe']));
		if (!defined('MONETICOPAIEMENT_COMPANYCODE')) define("MONETICOPAIEMENT_COMPANYCODE", apply_filters('gf_monetico_change_code_societe', $feed['meta']['code_societe']));
		if (!defined('MONETICOPAIEMENT_URLSERVER')) define("MONETICOPAIEMENT_URLSERVER", "");
		if (!defined('MONETICOPAIEMENT_URLOK')) define("MONETICOPAIEMENT_URLOK", "");
		if (!defined('MONETICOPAIEMENT_URLKO')) define("MONETICOPAIEMENT_URLKO", "");

		$oEpt = new MoneticoPaiement_Ept();
		$oHmac = new MoneticoPaiement_Hmac($oEpt);

		$MAC_source = $this->computeHmacSource($MoneticoPaiement_bruteVars, $oEpt);
		$computed_MAC = $oHmac->computeHmac($MAC_source);
		$congruent_MAC = array_key_exists('MAC', $MoneticoPaiement_bruteVars) && hash_equals($computed_MAC, strtolower((string) $MoneticoPaiement_bruteVars['MAC']));

		if (! $congruent_MAC) {
			/// Pour Monetico nous allons gérer ici le die() et le message renvoyé à Monetico

			/// Ne jamais renvoyer le MAC calculé : n'importe qui pourrait poster de faux champs,
			/// récupérer le sceau correct dans la réponse et le rejouer pour marquer une entrée payée
			$reponse = sprintf(MONETICOPAIEMENT_PHASE2BACK_RECEIPT, MONETICOPAIEMENT_PHASE2BACK_MACNOTOK);
			echo $reponse;
			$this->log_debug(__METHOD__ . '(): HMAC keys do not match. MAC source: ' . $MAC_source);
			$this->log_error(__METHOD__ . "(): Invalid MAC for entry {$entry_id}. Check that the feed key matches the TPE and the environment (test / production) of this payment, and that the key was not regenerated in the Monetico dashboard.");
			die();
		}
		return true;
	}
	private function computeHmacSource($source, $oEpt)
	{
		$anomalies = null;
		if (array_key_exists('TPE', $source))
			$anomalies = $source["TPE"] != $oEpt->sNumero ? ":TPE" : null;
		if (array_key_exists('MAC', $source))
			unset($source['MAC']);
		else
			$anomalies .= ":MAC";
		if ($anomalies != null)
			return "anomaly_detected" . $anomalies;
		ksort($source);
		array_walk($source, function (&$a, $b) {
			$a = "$b=$a";
		});
		return implode('*', $source);
	}

	private function process_ipn($config, $entry, $code_retour, $motifrefus, $filtragecause, $filtragevaleur, $authentification, $numauto, $montant, $reference)
	{
		$this->log_debug(__METHOD__ . "(): Code retour : {$code_retour} - Motif refus : {$motifrefus} - Cause filtrage : {$filtragecause} - Valeur filtrage : {$filtragevaleur} - Authentification : {$authentification} - Num Auto : {$numauto} - Montant : {$montant} - Reference : {$reference}");
		$action = array();
		// paiement
		// Annulation
		// payetest
		/// « payetest » n'existe qu'en environnement de test : reçu sur un flux en production, c'est une anomalie,
		/// l'entrée ne doit pas être marquée payée (doc. Page de paiement 1.5.3.1)
		if ('payetest' === strtolower($code_retour) && 'production' === rgars($config, 'meta/mode')) {
			$this->log_error(__METHOD__ . "(): Test payment (payetest) received for entry {$entry['id']} whose feed is in production mode. Entry not marked as paid.");
			GFPaymentAddOn::add_note($entry['id'], sprintf(__('Monetico test payment received while the feed is in production mode: the entry was not marked as paid. Check this payment in your Monetico dashboard. Reference: %s', 'monetico-for-gravity-forms'), $reference));
			return $action;
		}

		switch (strtolower($code_retour)):
			case "payetest":
			case "paiement":

				$authentification = json_decode(base64_decode($authentification), true);
				$msg_3ds = $this->get_authentification($authentification);
				if (!empty($authentification['details']['status3ds'])): // 3DS1 uniquement
					$msg_3ds = " " . $this->get_status3ds($authentification['details']['status3ds']);
				endif;

				//creates transaction
				$action['id']               = $numauto . '_' . $reference;
				$action['type']             = 'complete_payment';
				$action['transaction_id']   = $numauto;
				$action['amount']           = $montant;
				$action['entry_id']         = $entry['id'];
				$action['payment_date']     = gmdate('y-m-d H:i:s');
				$action['payment_method']	= 'Monetico';
				$action['ready_to_fulfill'] = ! $entry['is_fulfilled'] ? true : false;
				$action['note']           	= trim(__("Credit card payment confirmed.", 'monetico-for-gravity-forms') . ' ' . $msg_3ds . "\n" . $this->get_payment_details($numauto, $reference));

				/// Nécessaires au remboursement : référence réellement payée ({ID} ou {ID}R{n}) et date de commande JJ/MM/AAAA
				/// (le CGI2 envoie la date au format JJ/MM/AAAA_a_HH:MM:SS)
				gform_update_meta($entry['id'], 'monetico_paid_reference', sanitize_text_field($reference));
				gform_update_meta($entry['id'], 'monetico_order_date', substr(sanitize_text_field(rgpost('date')), 0, 10));

				if (! $this->is_valid_initial_payment_amount($entry['id'], $montant)) {
					//create note and transaction
					$this->log_debug(__METHOD__ . '(): Payment amount does not match product price. Entry will not be marked as Approved.');
					GFPaymentAddOn::add_note($entry['id'], sprintf(__('Payment amount (%s) does not match product price. Entry will not be marked as Approved. Transaction ID: %s', 'monetico-for-gravity-forms'), GFCommon::to_money($montant, $entry['currency']), $numauto));
					GFPaymentAddOn::insert_transaction($entry['id'], 'payment', $numauto, $montant);

					$action['abort_callback'] = true;
				}

				break;
			case "annulation":
				$msg_err = $this->get_motifrefus($motifrefus, $filtragecause, $filtragevaleur);
				/// numauto est vide sur un refus : la date du retour distingue deux refus successifs,
				/// sinon Gravity Forms prend le second pour un doublon et l'ignore
				$action['id']             = 'fail_' . $reference . '_' . rgpost('date');
				$action['type']           = 'fail_payment';
				$action['transaction_id'] = $numauto;
				$action['entry_id']       = $entry['id'];
				$action['amount']         = $montant;
				$action['note']           = trim(__("Credit Card Payment: FAIL\nError:", 'monetico-for-gravity-forms') . ' ' . $msg_err . "\n" . $this->get_payment_details($numauto, $reference));
				break;
		endswitch;
		return $action;
	}

	/// Détails du paiement pour la note de l'entrée (carte, moyen de paiement, autorisation, référence Monetico)
	private function get_payment_details($numauto, $reference)
	{
		$details = array();
		$brand = sanitize_text_field(rgpost('brand'));
		$mode = sanitize_text_field(rgpost('modepaiement'));
		if ($brand) {
			$details[] = sprintf(__('Card: %s', 'monetico-for-gravity-forms'), $brand);
		}
		if ($mode && $mode != $brand) {
			$details[] = sprintf(__('Payment method: %s', 'monetico-for-gravity-forms'), $mode);
		}
		if ($numauto) {
			$details[] = sprintf(__('Authorization number: %s', 'monetico-for-gravity-forms'), sanitize_text_field($numauto));
		}
		if ($reference) {
			$details[] = sprintf(__('Monetico reference: %s', 'monetico-for-gravity-forms'), sanitize_text_field($reference));
		}
		return implode(' - ', $details);
	}

	public function get_entry($custom_field)
	{

		//Getting entry associated with this IPN message (entry id is sent in the 'custom' field)
		list($entry_id, $hash) = explode('|', $custom_field);
		$hash_matches = wp_hash($entry_id) == $hash;

		//allow the user to do some other kind of validation of the hash
		$hash_matches = apply_filters('gform_monetico_hash_matches', $hash_matches, $entry_id, $hash, $custom_field);

		//Validates that Entry Id wasn't tampered with
		if (! $hash_matches) {
			$this->log_error(__METHOD__ . "(): Entry ID verification failed. Hash does not match. Custom field: {$custom_field}. Aborting.");

			return false;
		}

		$this->log_debug(__METHOD__ . "(): IPN message has a valid custom field: {$custom_field}");

		$entry = GFAPI::get_entry($entry_id);

		if (is_wp_error($entry)) {
			$this->log_error(__METHOD__ . '(): ' . $entry->get_error_message());

			return false;
		}

		return $entry;
	}

	/// Guilamu, on peut retrouver quelques vérifications à ce niveau et il y a un filtre pour refuser le processus
	public function can_process_ipn($feed, $entry)
	{

		$this->log_debug(__METHOD__ . '(): Checking that IPN can be processed.');

		//Pre IPN processing filter. Allows users to cancel IPN processing
		$cancel = apply_filters('gform_monetico_pre_ipn', false, $_POST, $entry, $feed);

		if ($cancel) {
			$this->log_debug(__METHOD__ . '(): IPN processing cancelled by the gform_monetico_pre_ipn filter. Aborting.');
			do_action('gform_monetico_post_ipn', $_POST, $entry, $feed, true);
			if (has_filter('gform_monetico_post_ipn')) {
				$this->log_debug(__METHOD__ . '(): Executing functions hooked to gform_monetico_post_ipn.');
			}
			return false;
		}

		return true;
	}

	/// Guilamu
	/// Retour de l'authentification 3DS
	private function get_authentification($authentification)
	{
		$protocol = $authentification['protocol'];
		$version = $authentification['version'];
		switch ($authentification['status']):
			case "authenticated":
				return sprintf(__("%s authentication is successful.", 'monetico-for-gravity-forms'), $protocol . ' ' . $version);
				break;
			case "authentication_not_performed":
				return sprintf(__("The %s authentication could not be completed (technical problem or other).", 'monetico-for-gravity-forms'), $protocol . ' ' . $version);
				break;
			case "not_authenticated":
				return sprintf(__("%s authentication failed.", 'monetico-for-gravity-forms'), $protocol . ' ' . $version);
				break;
			case "authentication_rejected":
				return sprintf(__("%s authentication has been denied by the issuer.", 'monetico-for-gravity-forms'), $protocol . ' ' . $version);
				break;
			case "authentication_attempted":
				return sprintf(__("An %s authentication attempt has been made. The authentication could not be done but a proof was generated (CAVV).", 'monetico-for-gravity-forms'), $protocol . ' ' . $version);
				break;
			case "not_enrolled":
				return sprintf(__("The card is not enrolled in %s.", 'monetico-for-gravity-forms'), $protocol . ' ' . $version);
				break;
			case "disabled":
				$msg_3ds = __("3DSecure has been disengaged.", 'monetico-for-gravity-forms'); // 3DS1
				if (!empty($authentification['details']['disablingReason'])):
					switch ($authentification['details']['disablingReason']):
						case "commercant":
							$msg_3ds .= " " . __("Explicit disengaging.", 'monetico-for-gravity-forms');
							break;
						case "seuilnonatteint":
							$msg_3ds .= " " . __("Disengagement because the amount of the transaction does not reach the configured amount.", 'monetico-for-gravity-forms');
							break;
						case "scoring":
							$msg_3ds .= " " . __("Disengagement on scoring pattern.", 'monetico-for-gravity-forms');
							break;
					endswitch;
				endif;
				return $msg_3ds;
				break;
			default:
				return "";
		endswitch;
	}
	/// Guilamu
	/// Retour du niveau de risque 3DS
	private function get_status3ds($status3ds)
	{
		switch ($status3ds):
			case "-1":
				return __("The transaction was not done according to the 3DSecure protocol and the risk of unpaid is high.", 'monetico-for-gravity-forms');
				break;
			case "1":
				return __("The transaction was made according to 3DS protocol and the risk level is", 'monetico-for-gravity-forms') . " " . __("low.", 'monetico-for-gravity-forms');
				break;
			case "4":
				return __("The transaction was made according to 3DS protocol and the risk level is", 'monetico-for-gravity-forms') . " " . __("high.", 'monetico-for-gravity-forms');
				break;
		endswitch;
	}
	/// Guilamu
	/// Retour de l'erreur de paiement (refus, filtrage, 3ds, ...)
	private function get_motifrefus($motifrefus, $filtragecause, $filtragevaleur)
	{
		switch ($motifrefus):
			case "Appel Phonie":
				return __("The customer's bank requests additional information.", "monetico-for-gravity-forms");
				break;
			case "Refus":
			case "Interdit":
				return __("The customer's bank refuses to grant permission.", "monetico-for-gravity-forms");
				break;
			case "Filtrage":
				$msg_err = __("The payment request was blocked by the filter settings that the merchant has implemented in its Fraud Prevention Module.", "monetico-for-gravity-forms");
				$filtrage = array(1 => __("IP address", "monetico-for-gravity-forms"), 2 => __("Card number", "monetico-for-gravity-forms"), 3 => __("Card BIN", "monetico-for-gravity-forms"), 4 => __("Country card", "monetico-for-gravity-forms"), 5 => __("Country IP", "monetico-for-gravity-forms"), 6 => __("Consistency country / card countries IP", "monetico-for-gravity-forms"), 7 => __("Disposable email", "monetico-for-gravity-forms"), 8 => __("Limitation amount for a CB over time", "monetico-for-gravity-forms"), 9 => __("Limitation of number of transactions for a CB over time", "monetico-for-gravity-forms"), 11 => __("Limitation of number of transactions over a period alias", "monetico-for-gravity-forms"), 12 => __("Limitation on amount per alias over time", "monetico-for-gravity-forms"), 13 => __("Limitation amount by IP over time", "monetico-for-gravity-forms"), 14 => __("Limitation of number of transactions by IP over time", "monetico-for-gravity-forms"), 15 => __("Testers cards", "monetico-for-gravity-forms"), 16 => __("Limitation on number of aliases by CB", "monetico-for-gravity-forms"));
				$filtres = explode("-", $filtragecause);
				$filtres_valeur = explode("-", $filtragevaleur); // Valeur ayant déclenché le filtrage, même ordre que la cause
				$liste = "";
				foreach ($filtres as $key => $filtre) {
					if (trim($filtre) != "" && is_numeric($filtre)) {
						$liste .= (isset($filtrage[(int) $filtre]) ? $filtrage[(int) $filtre] : $filtre) . " (" . (isset($filtres_valeur[$key]) ? $filtres_valeur[$key] : '') . "), ";
					}
				}
				if ($liste != "") {
					$liste = substr($liste, 0, -2);
					$msg_err .= " " . __("Cause of filtering:", "monetico-for-gravity-forms") . " " . $liste . ".";
				}
				return $msg_err;
				break;
			case "3DSecure":
				return __("3D Secure authentication negatively received from the holder's bank.", "monetico-for-gravity-forms");
				break;
			default:
				return __("Unknown error", "monetico-for-gravity-forms");
		endswitch;
	}
	/// Outrepasse la fonction présente dans class-gf-payment-addon.php
	/// Guilamu
	public function is_callback_valid()
	{
		/// /?moneticoListener=monetico_retour_banque
		if (rgget('moneticoListener') != 'monetico_retour_banque') {
			return false;
		}

		return true;
	}

	//------- ADMIN FUNCTIONS/HOOKS -----------//

	public function init_admin()
	{

		parent::init_admin();

		add_action('gform_payment_status', array($this, 'admin_edit_payment_status'), 3, 3);
		add_action('gform_payment_date', array($this, 'admin_edit_payment_date'), 3, 3);
		add_action('gform_payment_transaction_id', array($this, 'admin_edit_payment_transaction_id'), 3, 3);
		add_action('gform_payment_amount', array($this, 'admin_edit_payment_amount'), 3, 3);
		add_action('gform_after_update_entry', array($this, 'admin_update_payment'), 4, 2);

		// Add merge tag to the merge tag dropdown in form/notification editor
		add_filter('gform_custom_merge_tags', array($this, 'add_monetico_merge_tags'), 10, 4);

		// Add payment link meta box on entry detail page
		add_action('gform_entry_detail_sidebar_middle', array($this, 'entry_detail_payment_link_box'), 10, 2);

		// Refund box on entry detail page for paid entries
		add_action('gform_entry_detail_sidebar_middle', array($this, 'entry_detail_refund_box'), 11, 2);
	}

	public function init_ajax()
	{
		parent::init_ajax();

		add_action('wp_ajax_gf_monetico_refund', array($this, 'ajax_refund'));
	}

	/**
	 * Add supported notification events.
	 *
	 * @param array $form The form currently being processed.
	 *
	 * @return array
	 */
	public function supported_notification_events($form)
	{
		if (! $this->has_feed($form['id'])) {
			return false;
		}

		return array(
			'complete_payment'          => esc_html__('Payment Completed', 'monetico-for-gravity-forms'),
			'fail_payment'              => esc_html__('Payment Failed', 'monetico-for-gravity-forms'),
			'add_pending_payment'       => esc_html__('Payment Pending', 'monetico-for-gravity-forms'),
			'refund_payment'            => esc_html__('Payment Refunded', 'monetico-for-gravity-forms'),
		);
	}

	public function admin_edit_payment_status($payment_status, $form, $entry)
	{
		if ($this->payment_details_editing_disabled($entry)) {
			return $payment_status;
		}

		//create drop down for payment status
		$payment_string = gform_tooltip('monetico_edit_payment_status', '', true);
		$payment_string .= '<select id="payment_status" name="payment_status">';
		$payment_string .= '<option value="' . $payment_status . '" selected>' . $payment_status . '</option>';
		$payment_string .= '<option value="Paid">Paid</option>';
		$payment_string .= '</select>';

		return $payment_string;
	}

	public function admin_edit_payment_date($payment_date, $form, $entry)
	{
		if ($this->payment_details_editing_disabled($entry)) {
			return $payment_date;
		}

		$payment_date = $entry['payment_date'];
		if (empty($payment_date)) {
			$payment_date = gmdate('y-m-d H:i:s');
		}

		$input = '<input type="text" id="payment_date" name="payment_date" value="' . $payment_date . '">';

		return $input;
	}

	public function admin_edit_payment_transaction_id($transaction_id, $form, $entry)
	{
		if ($this->payment_details_editing_disabled($entry)) {
			return $transaction_id;
		}

		$input = '<input type="text" id="monetico_transaction_id" name="monetico_transaction_id" value="' . $transaction_id . '">';

		return $input;
	}

	public function admin_edit_payment_amount($payment_amount, $form, $entry)
	{
		if ($this->payment_details_editing_disabled($entry)) {
			return $payment_amount;
		}

		if (empty($payment_amount)) {
			$payment_amount = GFCommon::get_order_total($form, $entry);
		}

		$input = '<input type="text" id="payment_amount" name="payment_amount" class="gform_currency" value="' . $payment_amount . '">';

		return $input;
	}

	public function admin_update_payment($form, $entry_id)
	{
		check_admin_referer('gforms_save_entry', 'gforms_save_entry');

		//update payment information in admin, need to use this function so the lead data is updated before displayed in the sidebar info section
		$entry = GFFormsModel::get_lead($entry_id);

		if ($this->payment_details_editing_disabled($entry, 'update')) {
			return;
		}

		//get payment fields to update
		$payment_status = rgpost('payment_status');
		//when updating, payment status may not be editable, if no value in post, set to lead payment status
		if (empty($payment_status)) {
			$payment_status = $entry['payment_status'];
		}

		$payment_amount      = GFCommon::to_number(rgpost('payment_amount'));
		$payment_transaction = rgpost('monetico_transaction_id');
		$payment_date        = rgpost('payment_date');

		$status_unchanged = $entry['payment_status'] == $payment_status;
		$amount_unchanged = $entry['payment_amount'] == $payment_amount;
		$id_unchanged     = $entry['transaction_id'] == $payment_transaction;
		$date_unchanged   = $entry['payment_date'] == $payment_date;

		if ($status_unchanged && $amount_unchanged && $id_unchanged && $date_unchanged) {
			return;
		}

		if (empty($payment_date)) {
			$payment_date = gmdate('y-m-d H:i:s');
		} else {
			//format date entered by user
			$payment_date = date('Y-m-d H:i:s', strtotime($payment_date));
		}

		global $current_user;
		$user_id   = 0;
		$user_name = 'System';
		if ($current_user && $user_data = get_userdata($current_user->ID)) {
			$user_id   = $current_user->ID;
			$user_name = $user_data->display_name;
		}

		$entry['payment_status'] = $payment_status;
		$entry['payment_amount'] = $payment_amount;
		$entry['payment_date']   = $payment_date;
		$entry['transaction_id'] = $payment_transaction;

		// if payment status does not equal approved/paid or the lead has already been fulfilled, do not continue with fulfillment
		if (($payment_status == 'Approved' || $payment_status == 'Paid') && ! $entry['is_fulfilled']) {
			$action['id']             = $payment_transaction;
			$action['type']           = 'complete_payment';
			$action['transaction_id'] = $payment_transaction;
			$action['amount']         = $payment_amount;
			$action['entry_id']       = $entry['id'];

			$this->complete_payment($entry, $action);
			$this->fulfill_order($entry, $payment_transaction, $payment_amount);
		}
		//update lead, add a note
		GFAPI::update_entry($entry);
		GFFormsModel::add_note($entry['id'], $user_id, $user_name, sprintf(esc_html__('Payment information was manually updated. Status: %s. Amount: %s. Transaction ID: %s. Date: %s', 'monetico-for-gravity-forms'), $entry['payment_status'], GFCommon::to_money($entry['payment_amount'], $entry['currency']), $payment_transaction, $entry['payment_date']));
	}

	public function fulfill_order(&$entry, $transaction_id, $amount, $feed = null)
	{

		if (! $feed) {
			$feed = $this->get_payment_feed($entry);
		}

		$form = GFFormsModel::get_form_meta($entry['form_id']);
		if (rgars($feed, 'meta/delayPost')) {
			$this->log_debug(__METHOD__ . '(): Creating post.');
			$entry['post_id'] = GFFormsModel::create_post($form, $entry);
			$this->log_debug(__METHOD__ . '(): Post created.');
		}

		if (rgars($feed, 'meta/delayNotification')) {
			//sending delayed notifications
			$notifications = $this->get_notifications_to_send($form, $feed);
			GFCommon::send_notifications($notifications, $form, $entry, true, 'form_submission');
		}

		do_action('gform_monetico_fulfillment', $entry, $feed, $transaction_id, $amount);
		if (has_filter('gform_monetico_fulfillment')) {
			$this->log_debug(__METHOD__ . '(): Executing functions hooked to gform_monetico_fulfillment.');
		}
	}

	/**
	 * Retrieve the IDs of the notifications to be sent.
	 *
	 * @param array $form The form which created the entry being processed.
	 * @param array $feed The feed which processed the entry.
	 *
	 * @return array
	 */
	public function get_notifications_to_send($form, $feed)
	{
		$notifications_to_send  = array();
		$selected_notifications = rgars($feed, 'meta/selectedNotifications');

		if (is_array($selected_notifications)) {
			// Make sure that the notifications being sent belong to the form submission event, just in case the notification event was changed after the feed was configured.
			foreach ($form['notifications'] as $notification) {
				if (rgar($notification, 'event') != 'form_submission' || ! in_array($notification['id'], $selected_notifications)) {
					continue;
				}

				$notifications_to_send[] = $notification['id'];
			}
		}

		return $notifications_to_send;
	}

	private function is_valid_initial_payment_amount($entry_id, $amount_paid)
	{

		//get amount initially sent to monetico
		$amount_sent = gform_get_meta($entry_id, 'payment_amount');
		if (empty($amount_sent)) {
			return true;
		}

		$epsilon    = 0.00001;
		$is_equal   = abs(floatval($amount_paid) - floatval($amount_sent)) < $epsilon;
		$is_greater = floatval($amount_paid) > floatval($amount_sent);

		//initial payment is valid if it is equal to or greater than product/subscription amount
		if ($is_equal || $is_greater) {
			return true;
		}

		return false;
	}

	/**
	 * Editing of the payment details should only be possible if the entry was processed by Monetico, if the payment status is Pending or Processing, and the transaction was not a subscription.
	 *
	 * @param array $entry The current entry
	 * @param string $action The entry detail page action, edit or update.
	 *
	 * @return bool
	 */
	public function payment_details_editing_disabled($entry, $action = 'edit')
	{
		if (! $this->is_payment_gateway($entry['id'])) {
			// Entry was not processed by this add-on, don't allow editing.
			return true;
		}

		$payment_status = rgar($entry, 'payment_status');
		if ($payment_status == 'Approved' || $payment_status == 'Paid' || rgar($entry, 'transaction_type') == 2) {
			// Editing not allowed for this entries transaction type or payment status.
			return true;
		}

		if ($action == 'edit' && rgpost('screen_mode') == 'edit') {
			// Editing is allowed for this entry.
			return false;
		}

		if ($action == 'update' && rgpost('screen_mode') == 'view' && rgpost('action') == 'update') {
			// Updating the payment details for this entry is allowed.
			return false;
		}

		// In all other cases editing is not allowed.

		return true;
	}

	public function uninstall()
	{
		parent::uninstall();
		delete_option('gform_monetico_sslverify');
	}
}
