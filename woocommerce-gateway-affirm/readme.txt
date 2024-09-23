=== WooCommerce Affirm Gateway ===
Author: WooCommerce
Tags: woocommerce
Stable tag: 2.4.1
Requires at least: 6.1
Tested up to: 6.4.2
Requires PHP: 7.4
Requires WooCommerce at least: 8.0
Tested WooCommerce up to: 8.5.0
License: GPL-3.0-or-later
License URI: https://www.gnu.org/licenses/gpl-3.0.html

The Affirm payment gateway lets your store accept monthly payments for purchases.

== Description ==

Affirm is the top-rated pay-over-time solution offering Pay in 4 for everyday purchases or monthly installments for higher-ticket items.

= Flexible payments help shoppers say yes. =
Affirm’s tailored Buy Now Pay Later programs remove price as a barrier, turning browsers into buyers, increasing average order value, and expanding your customer base.

Affirm is modernizing consumer credit and changing the way people shop. We partner with 250k retailers to give our network of over 16 million users the flexibility to buy what they want today and make simple payments over time.

Our transparent terms (no fees, no compounding interest) boost customer satisfaction and result in higher conversion rates and repeat purchases for merchants.

= Say yes to more =

Go live in no time
We’ll guide you through our straightforward integration in an hour or less.

Market Affirm with ease
Our toolkit makes it easy to implement best-in-class Affirm marketing across all your channels.

Drive conversion and AOV
Empower more shoppers to buy exactly what they want, when they want it.

Maximize your revenue
Capture more conversions with the right terms that will perform well for your particular business.

Approve more customers
Our machine-learning based underwriting approves 20% more customers than our competitors, on average. We approve order values from $50 to $30,000.

= How to Get Started =

1. Sign up for a merchant account with Affirm.
2. Download the Affirm-WooCommerce extension.
3. Install Affirm on your WooCommerce store.
4. Enter your API details at WooCommerce > Settings > Payments > Affirm.

See [https://woocommerce.com/document/woocommerce-gateway-affirm/](https://woocommerce.com/document/woocommerce-gateway-affirm/) for full documentation.

== Installation ==

= Automatic installation =

Automatic installation is the easiest option as WordPress handles the file transfers itself and you don’t need to leave your web browser. To
automatically install WooCommerce Gateway Affirm, log in to your WordPress dashboard, navigate to the Plugins menu, and click **Add New**.

In the search field type "WooCommerce Gateway Affirm" and click **Search Plugins**. Once you've found our plugin you can install it by clicking **Install Now**, as well as view details about it such as the point release, rating, and description.

= Manual installation =

The manual installation method involves downloading our plugin and uploading it to your web server via your favorite FTP application. The WordPress codex contains [instructions on how to do this here](http://codex.wordpress.org/Managing_Plugins#Manual_Plugin_Installation).

= Updating =

Automatic updates should work like a charm; as always though, ensure you backup your site just in case.

== Frequently Asked Questions ==

= Affirm is not showing. =
Confirm that:

- Your site’s currency is set to USD. Go to: WooCommerce > Settings > General > Currency.
- Customers have a U.S.-based billing address to use Affirm.
- SSL is enabled.
- Your site is in Live (not Test) mode.

= Nothing happens when customer attempts to pay with Affirm. =
This error may be caused by non-standard/poorly coded themes and JavaScript (JS) issues. Common issues include:

- JavaScript errors on checkout page – To view the error, open your browser error console (in Chrome: View > developer > JavaScript console) and look for red errors. This should indicate where the error is located and lead you to the problem, e.g., Loading jQuery incorrectly
- Failing to load scripts – Affirm loads JavaScript which it needs to function. If these are not loaded, you will see errors. Most common reasons are:
  - Theme is missing wp_head() or wp_footer() calls.
  - Old overridden template files from WooCommerce inside your theme.
  - Loading headers/footers in a non-standard way. WooCommerce uses get_header()’s get_header action to init the checkout and load scripts. If you are not using get_header() you either need to do so, or you need to trigger the get_header action manually using: do_action( ‘get_header ); in your custom header loader.

= Is it possible to override templates on the Affirm pages? =
No. These are fixed/static pages from Affirm.

= Why should the Enhanced Analytics box be unticked? =
At this time the feature is inactive on stores. The information can only be accessed by an Affirm representative.

== Screenshots ==

1. Our transparent terms (no fees, no compounding interest) boost customer satisfaction and result in higher conversion rates and repeat purchases for merchants.
2. Capture more conversions with the right terms that will perform well for your particular business
3. Here’s how it works

== Changelog ==

= 2.4.1 - 2024-06-27 =
* Tweak - Use admin theme color in selectors.
* Dev - Remove Woo plugin header.

[See changelog for all versions](https://raw.githubusercontent.com/woocommerce/woocommerce-gateway-affirm/trunk/changelog.txt).
