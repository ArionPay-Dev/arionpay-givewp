# ArionPay for GiveWP

Accept crypto donations (Bitcoin, USDT, Litecoin, Ethereum, TRON and more) in GiveWP through [ArionPay](https://arionpay.com). The money goes straight to your own wallet: ArionPay never holds it.

Works with GiveWP's visual form builder forms and legacy forms. Tested on WordPress 7.1.4 with GiveWP 4.19.2 and PHP 8.3: donation, late payment, payment tolerance, changed amount, double payment and webhook checks.

## Install

1. **Plugins > Add New > Upload Plugin**, choose `arionpay-for-givewp.zip`, install and activate.
2. **Donations > Settings > Payment Gateways > ArionPay**: fill in the keys (see below) and save.
3. **Donations > Settings > Payment Gateways > Gateways**: turn on **ArionPay (crypto)** (for visual form builder forms and, if you use them, legacy forms). Keep GiveWP **Test Mode** on for now.

| Setting | Where in the ArionPay dashboard | Starts with |
| --- | --- | --- |
| API key | Open your store, **API key** | `pk_` |
| API secret | Open your store, **API secret** (asks for your authenticator code) | `sk_` |
| Webhook signing secret | **Webhooks** page, **signing secret** | `whsec_` |

The secrets are never shown again on the settings page; leave the fields empty to keep them. Nothing to paste into ArionPay: each invoice tells ArionPay where to send its payment updates (the address is shown on the settings page).

Also check, in your ArionPay store settings, that **Old ArionPay (v1) integration compatibility** is off.

## Try it first

1. With GiveWP **Test Mode** on, open a donation form, choose **Crypto (Bitcoin, USDT and more)** and donate.
2. On the ArionPay page, choose a coin and use the test payment buttons. No real money moves.
3. The donation should change from **Pending** to **Complete**, with the note "[TEST - no real money] ArionPay invoice ... is paid", and the donor gets the usual receipt.
4. Turn GiveWP **Test Mode** off before you take real donations.

## How donations change

- The donor confirms the donation and goes to the ArionPay page. The donation waits as **Pending**.
- When ArionPay confirms the full payment, the donation is **Complete** and the blockchain transaction id is saved.
- It is completed only if the paid invoice still matches it: same currency and amount. Otherwise a note explains why.
- A payment that is not exact goes to **Payment Radar** in your ArionPay dashboard. A small difference inside your store's payment tolerance (default 1%) is accepted automatically; anything else you decide, and if you accept it the donation is completed here too.
- A late payment still completes the donation, with a note. Payment seen, partial payment, expired and "needs review" updates become notes.
- Coming back from the payment page never completes a donation.

## Good to know

- ArionPay shows only for currencies it accepts: USD, EUR, GBP, CAD, AUD, CHF, JPY, TRY, AED, INR, BRL, RUB, CNY, SGD, HKD, KRW, MXN, PLN, SEK, NOK, ZAR.
- Recurring donations are not supported: crypto payments are one-off.
- Refunds are made from your own wallet; then mark the donation refunded by hand.
- The plugin sends ArionPay the donation number, amount, currency and form name. It does not send the donor's name or email.

## Build from source

The release zip is made with `python3 build.py`. It copies the [ArionPay PHP library](https://github.com/ArionPay-Dev/arionpay-php) into the plugin first, from a folder named `arionpay-php` next to this one:

```bash
git clone https://github.com/ArionPay-Dev/arionpay-php ../arionpay-php
python3 build.py
```
