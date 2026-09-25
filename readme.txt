# WooCommerce EU VAT / OSS Rules

## Įdiegimas

1. WordPress administracijoje eikite į Plugins → Add New → Upload Plugin.
2. Įkelkite `woo-eu-vat-oss.zip`.
3. Aktyvuokite pluginą.
4. Eikite į WooCommerce → EU VAT / OSS.
5. Įjunkite arba išjunkite OSS.

## WooCommerce nustatymai

Prieš naudodami pluginą patikrinkite WooCommerce nustatymus:

1. Eikite į WooCommerce → Settings → General ir įjunkite „Enable tax rates and calculations“.
2. Skiltyje „Selling location(s)“ pasirinkite šalis, į kurias parduodate. Įtraukite visas norimas ES šalis, ne tik Lietuvą.
3. Eikite į WooCommerce → Settings → Tax ir nustatykite „Calculate tax based on“ į „Customer shipping address“.
4. WooCommerce → Settings → Tax → Standard rates lentelėje įveskite Lietuvos tarifą (pvz., 21 %) ir kiekvienos kitos parduodamos ES šalies tarifą. OSS įjungus užsienio ES B2C pirkėjui naudojama jo šalies WooCommerce Tax Rate; OSS išjungus užsienio ES B2C pirkėjui naudojamas WooCommerce LT tarifas.
5. Patikrinkite kiekvienos tarifų eilutės šalies kodą, tarifą, prioritetą ir tai, kad tarifas taikomas pristatymui, jei reikia apmokestinti pristatymą.

Pluginas tarifų lentelės nesukuria ir nepakeičia. Jei WooCommerce nėra įvesto konkrečios šalies tarifo arba prekės tax status nėra „Taxable“, pluginas negali pritaikyti to tarifo. Nustatymas „Calculate tax based on“ taip pat turi atitikti norimą mokesčių vietą.

## Laukai

Įdiekite ir aktyvuokite (Checkout Field Editor for WooCommerce) checkout laukų pluginą, kuris leidžia kurti pasirinktinius atsiskaitymo laukus. Sukurkite šiuos laukus su nurodytais laukų ID / laukų pavadinimais ir pridėkite juos prie atsiskaitymo formos:

- `billing_as_b2b` – checkbox, reikšmės `1` arba `0`; etiketė, pvz., „Perka kaip įmonė“.
- `billing_b2b_company` – tekstas, įmonės pavadinimas.
- `billing_b2b_imones_kodas` – tekstas, įmonės kodas.
- `billing_pvm_moketojo_kodas` – tekstas, PVM mokėtojo kodas. Šiuo metu pluginas jo netikrina VIES sistemoje.
- `billing_imones_adresas` – tekstas, įmonės adresas.
- `billing_country` – pirkėjo šalis. Naudoti WooCommerce standartinį Billing/Shipping country lauką, o ne kurti antrą šalies lauką.

Keturi įmonės tekstiniai laukai turi būti atsiskaitymo puslapyje. Žemiau pateiktas kodas juos padaro privalomus, kai pažymėtas `billing_as_b2b`, ir išvalo bei atžymi kaip neprivalomus, kai įmonė nepasirinkta.

## Įmonės laukų validavimas

Įdėkite šį kodą į aktyvios child temos `functions.php` failą arba į PHP kodo fragmentų papildinį. Nedėkite jo į WooCommerce ar šio plugino failus, nes atnaujinimas gali pakeisti tuos failus. Pateiktas kodas skirtas klasikiniam WooCommerce checkout; Checkout Blocks gali reikalauti atskiros Blocks integracijos. Jei naudojamas Polylang, klaidų tekstai verčiami per `pll__`; be Polylang rodomas numatytasis tekstas.

```php
function wceu_company_field_message( $message ) {
	return function_exists( 'pll__' ) ? pll__( $message ) : $message;
}

function wceu_custom_checkout_company_fields_script() {
	if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || is_order_received_page() ) {
		return;
	}
	?>
	<script>
	jQuery(function($) {
		function toggleCompanyFields() {
			var isCompany = $('#billing_as_b2b').is(':checked');
			var selectors = [
				'#billing_b2b_company',
				'#billing_b2b_imones_kodas',
				'#billing_pvm_moketojo_kodas',
				'#billing_imones_adresas'
			];

			$.each(selectors, function(index, selector) {
				var $field = $(selector);
				var $wrapper = $field.closest('.form-row');
				var $label = $wrapper.find('label');

				if (isCompany) {
					$field.prop('required', true).attr('aria-required', 'true');
					$wrapper.addClass('validate-required');
					$label.find('.optional').remove();
					if ( ! $label.find('.required').length ) {
						$label.append('&nbsp;<span class="required" aria-hidden="true">*</span>');
					}
				} else {
					$field.val('').prop('required', false)
						.removeAttr('required aria-required aria-invalid');
					$wrapper.removeClass(
						'validate-required woocommerce-invalid ' +
						'woocommerce-invalid-required-field woocommerce-validated'
					);
					$label.find('.required').remove();
				}
			});
		}

		$(document.body).on('change', '#billing_as_b2b', function() {
			toggleCompanyFields();
			$(document.body).trigger('update_checkout');
		});

		$(document.body).on('updated_checkout', function() {
			toggleCompanyFields();
			$('#billing_as_b2b_field .optional').remove();
		});

		toggleCompanyFields();
		$('#billing_as_b2b_field .optional').remove();
	});
	</script>
	<?php
}
add_action( 'wp_footer', 'wceu_custom_checkout_company_fields_script' );

function wceu_validate_company_checkout_fields( $data, $errors ) {
	$company_selected = isset( $_POST['billing_as_b2b'] ) && in_array(
		strtolower( wc_clean( wp_unslash( $_POST['billing_as_b2b'] ) ) ),
		array( '1', 'yes', 'on', 'true' ),
		true
	);

	if ( ! $company_selected ) {
		return;
	}

	$required_fields = array(
		'billing_b2b_company'       => 'Prašome įvesti įmonės pavadinimą.',
		'billing_b2b_imones_kodas'  => 'Prašome įvesti įmonės kodą.',
		'billing_pvm_moketojo_kodas'=> 'Prašome įvesti PVM mokėtojo kodą.',
		'billing_imones_adresas'    => 'Prašome įvesti įmonės adresą.',
	);

	foreach ( $required_fields as $field => $message ) {
		$value = isset( $_POST[ $field ] )
			? trim( wc_clean( wp_unslash( $_POST[ $field ] ) ) )
			: '';

		if ( '' === $value ) {
			$errors->add( $field . '_required', wceu_company_field_message( $message ) );
		}
	}
}
add_action( 'woocommerce_after_checkout_validation', 'wceu_validate_company_checkout_fields', 10, 2 );
```

## Taisyklės

- Lietuva → WooCommerce LT Tax Rate.
- Kita ES šalis + pažymėta „Perka kaip įmonė“ → 0%.
- Kita ES šalis + B2C + OSS ON → tos šalies WooCommerce Tax Rate.
- Kita ES šalis + B2C + OSS OFF → LT WooCommerce Tax Rate.
- Ne ES → WooCommerce taisyklės nepakeičiamos.

## Svarbu

Pluginas nekeičia WooCommerce Tax Rates duomenų bazėje. Jis tik perrašo mokesčio skaičiavimo rezultatą krepšelio/checkout metu.

B2B 0% taikomas pagal „Perka kaip įmonė“ pasirinkimą ir pirkėjo šalį. VAT numeris šiuo metu nevaliduojamas ir mokesčio skaičiavimui įtakos neturi.
