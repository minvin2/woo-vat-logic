# WooCommerce EU VAT / OSS Rules

## Įdiegimas

1. WordPress administracijoje eikite į Plugins → Add New → Upload Plugin.
2. Įkelkite `woo-eu-vat-oss.zip`.
3. Aktyvuokite pluginą.
4. Eikite į WooCommerce → EU VAT / OSS.
5. Įjunkite arba išjunkite OSS.

## Laukai

Pluginas naudoja jau esančius Custom Checkout Fields laukus:

- `billing_as_b2b`
- `billing_b2b_company`
- `billing_b2b_imones_kodas`
- `billing_pvm_moketojo_kodas`
- `billing_imones_adresas`
- `billing_country`

## Taisyklės

- Lietuva → WooCommerce LT Tax Rate.
- Kita ES šalis + pažymėta „Perka kaip įmonė“ → 0%.
- Kita ES šalis + B2C + OSS ON → tos šalies WooCommerce Tax Rate.
- Kita ES šalis + B2C + OSS OFF → LT WooCommerce Tax Rate.
- Ne ES → WooCommerce taisyklės nepakeičiamos.

## Svarbu

Pluginas nekeičia WooCommerce Tax Rates duomenų bazėje. Jis tik perrašo mokesčio skaičiavimo rezultatą krepšelio/checkout metu.

B2B 0% taikomas pagal „Perka kaip įmonė“ pasirinkimą ir pirkėjo šalį. VAT numeris šiuo metu nevaliduojamas ir mokesčio skaičiavimui įtakos neturi.
