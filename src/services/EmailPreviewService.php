<?php
/**
 * QR payments plugin for Craft CMS
 *
 * Generates EPC QR code SEPA payment requests and lets the site owner
 * manually track and reconcile payment status from the control panel.
 *
 * @link      https://digitalastronaut.be
 * @copyright Copyright (c) 2026 Digitalastronaut
 */

namespace digitalastronaut\craftqrpayments\services;

use Craft;
use craft\base\Component;
use craft\helpers\DateTimeHelper;
use craft\helpers\Html;
use craft\helpers\MoneyHelper;
use craft\web\View;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\models\PaymentType;
use digitalastronaut\craftqrpayments\QrPayments;
use digitalastronaut\craftqrpayments\web\assets\frontend\FrontendAssetBundle;

use Throwable;

/**
 * Class EmailPreviewService
 *
 * Renders every payment email, subscription email, and the payer-facing
 * pay page against fake, in-memory test data rather than a real
 * `Payment`/`Subscription` row, so the live preview under a template
 * field — on a `PaymentType`'s or `SubscriptionType`'s Emails tab, or a
 * `PaymentType`'s Payment template tab — can show either the plugin's
 * own bundled default or a site's custom override — see
 * `PaymentTypesController::actionPreviewEmail()`/
 * `actionPreviewPayPage()` and
 * `SubscriptionTypesController::actionPreviewEmail()`. Without this,
 * trying out a custom layout meant either waiting for the cron job
 * (`ReminderController::actionSend()`) or a real status change to
 * trigger the actual send.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.8.0-beta
 */
class EmailPreviewService extends Component {
    // =========================================================================
    // Const Properties
    // =========================================================================

    /** @see PaymentType::$paymentCreatedTemplate */
    public const EMAIL_PAYMENT_CREATED = 'paymentCreatedTemplate';

    /** @see PaymentType::$paymentStatusChangedTemplate */
    public const EMAIL_STATUS_CHANGED = 'paymentStatusChangedTemplate';

    /** @see PaymentType::$paymentsSummaryTemplate */
    public const EMAIL_PAYMENTS_SUMMARY = 'paymentsSummaryTemplate';

    /** @see PaymentType::$paymentReminderTemplate */
    public const EMAIL_PAYMENT_REMINDER = 'paymentReminderTemplate';

    /** @see SubscriptionType::$subscriptionRequestedTemplate */
    public const EMAIL_SUBSCRIPTION_REQUESTED = 'subscriptionRequestedTemplate';

    /** @see SubscriptionType::$subscriptionCancelledTemplate */
    public const EMAIL_SUBSCRIPTION_CANCELLED = 'subscriptionCancelledTemplate';

    /** @see SubscriptionType::$subscriptionExpiredTemplate */
    public const EMAIL_SUBSCRIPTION_EXPIRED = 'subscriptionExpiredTemplate';

    /** @see SubscriptionType::$subscriptionActivatedTemplate */
    public const EMAIL_SUBSCRIPTION_ACTIVATED = 'subscriptionActivatedTemplate';

    /** @see SubscriptionType::$subscriptionExpiringTemplate */
    public const EMAIL_SUBSCRIPTION_EXPIRING = 'subscriptionExpiringTemplate';

    /**
     * @var array<string, string> Maps each `PaymentType`/`SubscriptionType`
     * template-override attribute to the plugin's own bundled default it
     * falls back to — see the matching `getTemplate()` methods on the
     * real queue jobs (`SendPaymentCreatedEmailJob` and friends) this
     * mirrors.
     */
    private const DEFAULT_TEMPLATES = [
        self::EMAIL_PAYMENT_CREATED => 'qr-payments/emails/_paymentCreated',
        self::EMAIL_STATUS_CHANGED => 'qr-payments/emails/_statusChanged',
        self::EMAIL_PAYMENTS_SUMMARY => 'qr-payments/emails/_paymentsSummary',
        self::EMAIL_PAYMENT_REMINDER => 'qr-payments/emails/_paymentReminder',
        self::EMAIL_SUBSCRIPTION_REQUESTED => 'qr-payments/emails/_subscriptionRequested',
        self::EMAIL_SUBSCRIPTION_CANCELLED => 'qr-payments/emails/_subscriptionCancelled',
        self::EMAIL_SUBSCRIPTION_EXPIRED => 'qr-payments/emails/_subscriptionExpired',
        self::EMAIL_SUBSCRIPTION_ACTIVATED => 'qr-payments/emails/_subscriptionActivated',
        self::EMAIL_SUBSCRIPTION_EXPIRING => 'qr-payments/emails/_subscriptionExpiring',
    ];

    /** Fake beneficiary/IBAN/BIC used for every preview, independent of this type's own (possibly still-unset) values. */
    private const TEST_BENEFICIARY_NAME = 'Jane Doe';
    private const TEST_IBAN = 'BE68539007547034';
    private const TEST_BIC = 'GKCCBEBB';

    /**
     * Never-persisted stand-ins so link-building code on the fake
     * `Payment` (`getUrl()`, `getCpEditUrl()`) has something to work
     * with instead of bailing out on a null `id`/`uid` — needed by the
     * pay-page preview's "I've paid" form (`redirectInput()`/
     * `hiddenInput('uid', ...)`), which a real send never has to worry
     * about since it only ever renders for an already-saved payment.
     */
    private const TEST_PAYMENT_ID = 999999999;
    private const TEST_PAYMENT_UID = '00000000-0000-0000-0000-000000000000';

    // =========================================================================
    // Public Methods
    // =========================================================================

    /**
     * @param string $emailKey
     * @return bool Whether `$emailKey` is one of the `EMAIL_*` constants.
     */
    public static function isValidEmailKey(string $emailKey): bool {
        return isset(self::DEFAULT_TEMPLATES[$emailKey]);
    }

    /**
     * @param string $emailKey One of the `EMAIL_*` constants.
     * @param string|null $typeName Shown in the preview in place of a real `PaymentType::$name`/`SubscriptionType::$name` — only used by the emails that actually display it (the payments summary and every subscription email).
     * @return string Rendered HTML of the plugin's own bundled default template.
     * @throws Throwable if the default template itself fails to render.
     */
    public function renderDefault(string $emailKey, ?string $typeName = null): string {
        return $this->render(self::DEFAULT_TEMPLATES[$emailKey], View::TEMPLATE_MODE_CP, $emailKey, $typeName);
    }

    /**
     * @param string $emailKey One of the `EMAIL_*` constants.
     * @param string $template Site template path to render instead of the bundled default.
     * @param string|null $typeName
     * @return string Rendered HTML, or a friendly inline notice if `$template` doesn't exist (e.g. a path still being typed in).
     * @throws Throwable if `$template` exists but fails to render.
     */
    public function renderCustom(string $emailKey, string $template, ?string $typeName = null): string {
        if (!Craft::$app->getView()->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            return $this->notFoundHtml($template);
        }

        return $this->render($template, View::TEMPLATE_MODE_SITE, $emailKey, $typeName);
    }

    /**
     * @param string $status One of `Payment::STATUS_*` — see `Payment::statuses()`.
     * @param string|null $template Site template path to render instead of the plugin's bundled pay page (`qr-payments/components/_pay`); null renders the bundled default. The bundled default's own stylesheet is only injected when this is null, so a custom template keeps its own page styling.
     * @return string Rendered HTML, or a friendly inline notice if `$template` doesn't exist (e.g. a path still being typed in).
     * @throws Throwable if the template fails to render.
     */
    public function renderPayPagePreview(string $status, ?string $template = null): string {
        $view = Craft::$app->getView();

        $mode = View::TEMPLATE_MODE_CP;
        $templatePath = 'qr-payments/components/_pay';

        if ($template !== null) {
            if (!$view->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
                return $this->notFoundHtml($template);
            }

            $templatePath = $template;
            $mode = View::TEMPLATE_MODE_SITE;
        }

        // Only the plugin's own bundled template wants this bundle's
        // `html { background-color }` and card styling — a custom
        // `payTemplate` has its own site layout/look, and injecting this
        // on top of it would paint over whatever that template's own
        // page background is meant to be.
        $newCssFiles = [];

        if ($template === null) {
            // Snapshotting `cssFiles` before/after these two calls, rather
            // than letting `renderPageTemplate()` auto-inject everything
            // currently registered on this request's *shared* View, the way
            // `Controller::renderTemplate()`'s response formatter does for
            // the real pay page. Inside an authenticated CP request that
            // view already carries the CP's own cp.css/admin.css/Tailwind
            // reset/etc. from the rest of the page — flushing all of that
            // into this iframe clobbers `.qr-pay`'s plain, site-styled look
            // with the CP's own theme instead of just linking our one
            // stylesheet. Diffing lets us inject only what these two calls
            // themselves just registered.
            $cssFilesBefore = $view->cssFiles;

            $view->registerAssetBundle(FrontendAssetBundle::class);
            QrPayments::getInstance()->getVite()->register('src/web/assets/frontend/frontend.js');

            $newCssFiles = array_diff_key($view->cssFiles, $cssFilesBefore);

            // Roll back immediately: this preview shouldn't leave its own
            // registrations sitting on the shared View for whatever else
            // renders later in the same CP request.
            $view->cssFiles = $cssFilesBefore;
        }

        $payment = $this->buildFakePaymentForStatus($status);

        // Only `requested`/`partial` ever render a QR (`_pay.twig`'s
        // `actionable` check) — skip generating one for every other
        // status rather than feeding it a "remaining" amount that can
        // legitimately be zero (e.g. `overpaid`, once net paid exceeds
        // the total), which `SepaQrData` rejects outright.
        $actionable = \in_array($status, [Payment::STATUS_REQUESTED, Payment::STATUS_PARTIAL], true);

        $html = $view->renderTemplate($templatePath, [
            'payment' => $payment,
            // The payment's own `remainingAmount`, not its full `amount` —
            // for `partial` this is less than the total, same as the real
            // pay page's QR (see `Payment::getPayableQrCodeDataUri()`), so
            // the preview's QR always matches what its own text says is
            // left to pay.
            'qrCodeDataUri' => $actionable
                ? $this->buildFakeQrCodeDataUri((float) MoneyHelper::toDecimal($payment->getRemainingAmount()))
                : null,
        ], $mode);

        if ($newCssFiles === []) return $html;

        // Rebuilt as plain, immediately-applied `<link>` tags rather than
        // injected verbatim: Vite's own registered tag uses the
        // `media="print"` + `onload="this.media='all'"` async-loading
        // trick, and that `onload` handler is itself JS — blocked by this
        // iframe's `sandbox=""` (no `allow-scripts`) the same as every
        // other script on this preview, so the stylesheet would otherwise
        // stay inert at `media="print"` forever and never actually apply.
        $hrefs = [];
        foreach ($newCssFiles as $tag) {
            if (preg_match('/\bhref="([^"]+)"/', $tag, $matches)) $hrefs[] = $matches[1];
        }

        $stylesheetTags = implode('', array_map(
            fn(string $href) => Html::tag('link', '', ['rel' => 'stylesheet', 'href' => $href]),
            $hrefs,
        ));

        $withStylesheet = preg_replace('/<\/head>/i', $stylesheetTags . '</head>', $html, 1);

        return $withStylesheet ?? $html;
    }

    // =========================================================================
    // Private Methods
    // =========================================================================

    /**
     * @param string $template
     * @param string $mode
     * @param string $emailKey
     * @param string|null $typeName
     * @return string
     * @throws Throwable if the template fails to render.
     */
    private function render(string $template, string $mode, string $emailKey, ?string $typeName): string {
        return Craft::$app->getView()->renderTemplate($template, $this->getTestVariables($emailKey, $typeName), $mode);
    }

    /**
     * @param string $emailKey
     * @param string|null $typeName
     * @return array
     */
    private function getTestVariables(string $emailKey, ?string $typeName): array {
        return match ($emailKey) {
            self::EMAIL_PAYMENT_CREATED => [
                'payment' => $this->buildFakePayment(),
                // The real send (`SendTemplatedEmailJob::execute()`) embeds the
                // payment's QR code into the outgoing message and hands the
                // template a `cid:` reference; there's no message to embed
                // into here, so a plain data URI stands in instead.
                'qrContentId' => $this->buildFakeQrCodeDataUri(),
            ],
            self::EMAIL_STATUS_CHANGED => [
                'payment' => $this->buildFakePayment(['status' => Payment::STATUS_PAID]),
                'previousStatusLabel' => Payment::statuses()[Payment::STATUS_REQUESTED]['label'],
                'statusLabel' => Payment::statuses()[Payment::STATUS_PAID]['label'],
            ],
            self::EMAIL_PAYMENTS_SUMMARY => [
                'payments' => $this->buildFakePaymentsList(),
                'paymentType' => $this->buildFakePaymentType($typeName),
            ],
            self::EMAIL_PAYMENT_REMINDER => [
                // Due in 7 days rather than overdue — the same "due soon"
                // branch `ReminderController::actionSend()` queues this
                // template for first, before the "overdue" one three
                // template fields down.
                'payment' => $this->buildFakePayment(['dueDate' => DateTimeHelper::currentUTCDateTime()->modify('+7 days')]),
            ],
            self::EMAIL_SUBSCRIPTION_REQUESTED,
            self::EMAIL_SUBSCRIPTION_CANCELLED,
            self::EMAIL_SUBSCRIPTION_EXPIRING => [
                'subscription' => $this->buildFakeSubscription($typeName),
            ],
            self::EMAIL_SUBSCRIPTION_ACTIVATED,
            self::EMAIL_SUBSCRIPTION_EXPIRED => [
                'subscription' => $this->buildFakeSubscription($typeName),
                // The real send points at the payment that triggered this
                // event (see `SendSubscriptionActivatedEmailJob`/
                // `SendSubscriptionExpiredEmailJob::getTemplateVariables()`)
                // — `buildFakeSubscription()`'s plain array has no backing
                // `Payment` to read that off, so the same fake payment every
                // other preview here already uses stands in instead.
                'paymentUrl' => $this->buildFakePayment()->getUrl(),
            ],
            default => [],
        };
    }

    /**
     * @param array $overrides Payment attributes to override on top of the shared fake defaults.
     * @return Payment An unsaved, in-memory payment — never persisted or validated.
     */
    private function buildFakePayment(array $overrides = []): Payment {
        return new Payment(array_merge([
            'id' => self::TEST_PAYMENT_ID,
            'uid' => self::TEST_PAYMENT_UID,
            'beneficiaryName' => self::TEST_BENEFICIARY_NAME,
            'iban' => self::TEST_IBAN,
            'bic' => self::TEST_BIC,
            'remittanceReference' => $this->testReference('PREVIEW001'),
            'email' => 'payer@example.com',
            'status' => Payment::STATUS_REQUESTED,
            'dueDate' => DateTimeHelper::currentUTCDateTime()->modify('+30 days'),
            'amount' => 42.50,
        ], $overrides));
    }

    /**
     * @param string $status One of `Payment::STATUS_*`.
     * @return Payment A fake payment for the pay-page preview, with status
     * history entries backing it when the status itself implies some —
     * without this, `_pay.twig`'s paid/refunded/overpaid math sees
     * nothing but the bare `amount` and €0.00 everywhere else, which
     * looks broken rather than showing what each status actually means.
     */
    private function buildFakePaymentForStatus(string $status): Payment {
        $payment = $this->buildFakePayment(['status' => $status]);

        if ($status === Payment::STATUS_PARTIAL) {
            $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYMENT, Payment::parseMoney(25.00), null);
        } elseif ($status === Payment::STATUS_OVERPAID) {
            $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYMENT, Payment::parseMoney(50.00), null);
        } elseif ($status === Payment::STATUS_REFUNDED) {
            $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_PAYMENT, Payment::parseMoney(42.50), null);
            $payment->pushStatusHistoryEntry(Payment::HISTORY_TYPE_REFUND, Payment::parseMoney(42.50), null);
        } elseif ($status === Payment::STATUS_EXPIRED) {
            // The base fake payment's `dueDate` defaults to 30 days in the
            // future — fine for every other status, but `_pay.twig` reads
            // `getDueInLabel()` off it here to say how long ago it expired,
            // so this preview needs a `dueDate` that's actually in the past.
            $payment->dueDate = DateTimeHelper::currentUTCDateTime()->modify('-3 days');
        }

        return $payment;
    }

    /**
     * @return Payment[] Three unsaved payments illustrating the mix a real digest shows: due soon, due later, and overdue.
     */
    private function buildFakePaymentsList(): array {
        $now = DateTimeHelper::currentUTCDateTime();

        return [
            $this->buildFakePayment([
                'remittanceReference' => $this->testReference('PREVIEW001'),
                'email' => 'payer1@example.com',
                'amount' => 125.00,
                'dueDate' => (clone $now)->modify('+5 days'),
            ]),
            $this->buildFakePayment([
                'remittanceReference' => $this->testReference('PREVIEW002'),
                'email' => 'payer2@example.com',
                'amount' => 60.00,
                'dueDate' => (clone $now)->modify('+18 days'),
            ]),
            $this->buildFakePayment([
                'remittanceReference' => $this->testReference('PREVIEW003'),
                'email' => 'payer3@example.com',
                'amount' => 89.99,
                'dueDate' => (clone $now)->modify('-3 days'),
            ]),
        ];
    }

    /**
     * @param string|null $typeName
     * @return PaymentType An unsaved payment type carrying nothing but a display name — the only attribute `_paymentsSummary.twig` reads off it.
     */
    private function buildFakePaymentType(?string $typeName): PaymentType {
        return new PaymentType([
            'name' => $typeName !== null && $typeName !== '' ? $typeName : Craft::t('qr-payments', 'Example type'),
        ]);
    }

    /**
     * @param string|null $typeName
     * @return array A fake, in-memory stand-in for a `Subscription` — a
     * plain array rather than the real element class, since every
     * subscription email only ever reads `subscriptionType.name` and
     * `coverageEnd` off it (confirmed against every bundled
     * `_subscription*.twig`), and the real `Subscription` element
     * requires a persisted, DB-backed `subscriptionTypeId` to resolve
     * `getSubscriptionType()` — more than this preview needs.
     */
    private function buildFakeSubscription(?string $typeName): array {
        return [
            'subscriptionType' => [
                'name' => $typeName !== null && $typeName !== '' ? $typeName : Craft::t('qr-payments', 'Example plan'),
            ],
            'coverageEnd' => DateTimeHelper::currentUTCDateTime()->modify('+30 days'),
        ];
    }

    /**
     * @param float $amount Defaults to the same 42.50 every other fake
     * payment here uses; the pay-page preview overrides this with the
     * outstanding balance instead, so a `partial` preview's QR matches
     * what its own text says is left to pay — see `getPayableQrCodeDataUri()`
     * for why the real pay page can't just read this off the payment's
     * stored `qrCode` column either.
     * @return string A `data:` URI for a real QR code, so `_paymentCreated.twig`'s `<img>` actually shows something scannable.
     */
    private function buildFakeQrCodeDataUri(float $amount = 42.50): string {
        return QrPayments::getInstance()->getEpcQr()->generateQrCode(
            beneficiaryName: self::TEST_BENEFICIARY_NAME,
            iban: self::TEST_IBAN,
            amount: $amount,
            bic: self::TEST_BIC,
            remittanceReference: $this->testReference('PREVIEW001'),
        )->getDataUri();
    }

    /**
     * @param string $body
     * @return string A valid-shaped RF creditor reference, so the amount/reference pair looks like something the reconciliation flow would actually recognize.
     */
    private function testReference(string $body): string {
        return QrPayments::getInstance()->getEpcQr()->generateRfReference($body);
    }

    /**
     * @param string $template
     * @return string A minimal, muted HTML page explaining that `$template` couldn't be found — shown in place of a render error while a custom template path is still being typed in (or simply hasn't been created yet).
     */
    private function notFoundHtml(string $template): string {
        $message = Craft::t('qr-payments', 'Could not find the template “{template}”.', ['template' => $template]);

        return '<!DOCTYPE html><html><head><meta charset="UTF-8">' .
            '<style>html{color-scheme:light dark;}body{margin:0;padding:24px;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;font-size:13px;color:#888888;}</style>' .
            '</head><body>' . Html::encode($message) . '</body></html>';
    }
}
