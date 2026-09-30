# Creating a payment

## From the control panel

Go to **QR Payments → New payment** to create a payment by hand: enter an amount, the payer's email, and optionally a due date override. Saving generates the EPC QR code and any custom fields on the payment's field layout, then opens the payment's edit page.

<!-- ![The "New payment" form (amount and email fields) side by side with the resulting payment edit page showing the generated QR code](../public/images/creating-a-payment.png) -->
_Screenshot: the New payment form, and/or the payment edit page right after saving it — the QR code in the sidebar is the main thing worth showing._

## From your own site

A site can also request a payment directly, without visiting the control panel — see the [Integration guide](/integration/introduction) for the public, anonymous endpoint used for this.

## Custom fields

Payments are Craft elements with their own field layout (**Settings → Fields**, and the **Payment** field layout under **Settings → Elements**), so you can add whatever extra fields a payment needs — an order reference, internal notes, a linked order entry — alongside the built-in amount, email, due date and status.
