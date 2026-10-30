# Monetico for Gravity Forms

[![Latest Release](https://img.shields.io/github/v/release/guilamu/monetico-for-gravity-forms?color=blue)](https://github.com/guilamu/monetico-for-gravity-forms/releases) [![License: AGPL-3.0](https://img.shields.io/badge/license-AGPL--3.0-green.svg)](LICENSE) [![WordPress: 5.8+](https://img.shields.io/badge/WordPress-5.8%2B-blue.svg)](https://wordpress.org) [![PHP: 7.4+](https://img.shields.io/badge/PHP-7.4%2B-purple.svg)](https://php.net)

Unofficial Monetico payment gateway for Gravity Forms: card payments, 3D Secure 2, payment links and refunds. Use at your own risk.

## Disclaimer — read before use

**This plugin is unofficial and is provided "as is", without any warranty of any kind. Its author can in no event be held liable for any payment made, received, missed, duplicated, wrongly recorded or refunded through it, nor for any direct or indirect loss (financial, commercial, data or otherwise) resulting from its use, misuse or malfunction.**

- It is not affiliated with, endorsed or supported by Monetico, Crédit Mutuel, CIC, Euro-Information or Gravity Forms (Rocketgenius).
- It is a community takeover of an abandoned plugin. Nobody guarantees that it works on your site, with your bank contract, or with future versions of Monetico, Gravity Forms, WordPress or PHP.
- Your Monetico dashboard is the only reference for what was actually paid. Never rely on the payment status of a Gravity Forms entry alone: check every payment, especially before shipping goods or delivering a service.
- Test thoroughly in Monetico test mode before going into production, and again after every update.
- **If you need a guaranteed, supported solution, do not use this plugin**: use an official Monetico module or a commercially supported payment add-on instead.

By installing or using this plugin, you accept full and sole responsibility for its use.

> **Avertissement (FR)** — Ce plugin n'est pas officiel et il est fourni « en l'état », sans aucune garantie. Son auteur ne peut **en aucun cas** être tenu responsable des paiements effectués, reçus, manqués, dupliqués, mal enregistrés ou remboursés au moyen de ce plugin, ni d'aucune perte directe ou indirecte liée à son utilisation. Il n'est affilié ni à Monetico, ni au Crédit Mutuel, ni au CIC, ni à Euro-Information, ni à Gravity Forms. Votre tableau de bord Monetico reste la seule référence. Si vous avez besoin d'une solution garantie et assistée, n'utilisez pas ce plugin : préférez un module officiel Monetico ou une extension bénéficiant d'un support commercial. En l'installant, vous en assumez seul l'entière responsabilité.

## Payments
- Redirect the customer to the Monetico payment page after form submission, in test or production mode
- Send the billing details expected by 3D Secure 2 (name, e-mail, address, country code)
- Set a minimum amount below which no 3D Secure challenge is requested
- Delay post creation and "Form is submitted" notifications until the payment is confirmed
- Record the card, payment method, authorisation number and Monetico reference in an entry note

## Payment Follow-up
- Resend a payment link for failed or abandoned payments, from the entry detail page or with the `{monetico_payment_link}` merge tag in notifications
- Refund a payment, fully or partially, from the entry detail page
- Trigger notifications on payment events, including "Payment refunded"
- Verify the MAC seal of every Monetico CGI2 return before updating an entry

## Key Features
- **Multilingual:** works with forms in any language; amounts are sent in the entry currency
- **Translation-Ready:** all strings are internationalized, French translation included
- **Secure:** every CGI2 return is authenticated with the Monetico MAC seal, invalid returns are rejected and logged
- **GitHub Updates:** automatic updates from GitHub releases
- **Migration:** settings, feeds and payment history of the legacy "Gravity Forms Monetico" plugin are migrated automatically

## Requirements
- Gravity Forms 2.5 or higher
- A Monetico contract with a TPE number, a site code (company code) and a security key
- The CGI2 return URL declared in your Monetico dashboard
- WordPress 5.8 or higher
- PHP 7.4 or higher

## Installation
1. Upload the `monetico-for-gravity-forms` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Forms → Settings → Monetico**, declare the CGI2 return URL shown there in your Monetico dashboard, then tick the confirmation box
4. Open a form, go to **Settings → Monetico** and create a feed with your TPE, site code, key and mode (start with **Test**)
5. Make test payments, check them in the Monetico dashboard, and only then switch the feed to **Production**

## FAQ
### I used "Gravity Forms Monetico". What happens when I activate this plugin?
Its settings, feeds, paid entries, processed feeds and CGI2 history are migrated, then the old plugin is deactivated automatically. Your feeds keep working: you do not need to recreate them. You can delete the old plugin afterwards.

### Does it work with Monetico Retail?
No. This plugin uses **Monetico Paiement** (Euro-Information: TPE number, site code, MAC key, `p.monetico-services.com`). **Monetico Retail** (gateway.monetico-retail.com) is a different platform with a different API: it is not supported.

### What is the CGI2 return URL?
It is the address Monetico calls to confirm a payment: `https://your-site/?moneticoListener=monetico_retour_banque`. Without it, entries are never marked as paid.

### Why does Monetico refuse to switch my TPE to production?
Monetico requires three successful test payments within seven days, each with a CGI2 return correctly processed by the site.

### A payment appears in Monetico but not in Gravity Forms (or the other way round). Which one is right?
The Monetico dashboard. Check the Gravity Forms logs (**Forms → Settings → Logging**) for an invalid MAC: it usually means a wrong key, TPE or mode in the feed.

### Which form fields should I map in the feed?
Map the customer's name, e-mail and an **Address** field in the feed's billing information. 3D Secure 2 requires the billing address line, city, postal code and country: without them, more payments may be challenged or declined by the card issuer.

### How is the customer sent to Monetico?
After submission, the customer lands on a page of your site (`?moneticoPay=…`) that posts the sealed payment form to Monetico, as Monetico requires. Payment links sent later (`{monetico_payment_link}`) point to `?moneticoLink=…`: the payment is generated when the customer clicks, and the link says the payment was already made once it is paid. If you use a page cache, make sure URLs with these parameters are not cached (the plugin sends no-cache headers).

### Can I customize the data sent to Monetico?
Yes, with the `gform_monetico_query` filter, among others (`gform_monetico_return_url`, `gform_monetico_fulfillment`, `gform_monetico_post_ipn`):
```php
add_filter( 'gform_monetico_query', function( $query_string, $form, $entry, $feed, $submission_data ) {
    return $query_string;
}, 10, 5 );
```

## Project Structure
```
.
├── monetico-for-gravity-forms.php     # Main plugin file, bootstrap
├── class-monetico-gf-addon.php        # Gravity Forms payment add-on (feeds, payment, CGI2, refunds)
├── class-monetico-gf-migration.php    # Migration from "Gravity Forms Monetico"
├── README.md
├── includes
│   ├── class-github-updater.php       # GitHub auto-updates
│   └── Parsedown.php                  # Markdown parser for the "View details" popup
├── monetico
│   ├── License.txt                    # Monetico kit license
│   └── MoneticoPaiement_Ept.inc.php   # Monetico payment kit (MAC computation)
└── languages
    ├── monetico-for-gravity-forms-fr_FR.mo  # French translation (binary)
    └── monetico-for-gravity-forms-fr_FR.po  # French translation (source)
```

## Changelog

### 0.1.0 - 2026-10-30
- Initial release
- Monetico Paiement (Monetico Online) payment gateway for Gravity Forms, in test or production mode
- Sealed payment request posted to the Monetico payment page, with 3D Secure 2 billing context
- Optional minimum amount below which no 3D Secure challenge is requested
- Payment confirmation through the Monetico CGI2 return, with MAC seal verification and amount check
- Post creation and "Form is submitted" notifications delayed until the payment is confirmed
- Payment links for failed or abandoned payments (`{monetico_payment_link}` merge tag and entry detail box)
- Full or partial refunds from the entry detail page, with a "Payment refunded" notification event
- Automatic migration from the "Gravity Forms Monetico" plugin, which is then deactivated
- Automatic updates from GitHub releases
- French translation

## Security

If you discover a security vulnerability in this plugin, please report it responsibly through [GitHub Security Advisories](https://github.com/guilamu/monetico-for-gravity-forms/security/advisories/new). Do not open a public issue for security reports.

## Contributing

Contributions are welcome! Please open an issue or submit a pull request on [GitHub](https://github.com/guilamu/monetico-for-gravity-forms).

For translations, the plugin uses WordPress i18n. You can contribute translations by editing the `.po` files in the `languages/` directory and generating the corresponding `.mo` files with the `wp i18n` CLI commands.

## License
This project is licensed under the GNU Affero General Public License v3.0 (AGPL-3.0) — see the [LICENSE](LICENSE) file for details. As stated in the license, the program is provided without any warranty.

It is based on "Gravity Forms Monetico", released under GPL-2.0-or-later. The Monetico payment kit in `monetico/` keeps its own license (`monetico/License.txt`).

---

Made with love for the WordPress community
