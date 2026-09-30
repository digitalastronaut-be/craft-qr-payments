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

namespace digitalastronaut\craftqrpayments\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use craft\helpers\App;
use craft\web\View;

use digitalastronaut\craftqrpayments\QrPayments;

use InvalidArgumentException;

/**
 * Class Settings
 *
 * Holds the beneficiary bank details every generated payment is stamped
 * with. Stored as raw strings so they can be `$ENV_VAR` references
 * (`suggestEnvVars` in the settings template) rather than plaintext IBANs
 * in project config; resolve with the getX() accessors, never the raw
 * property, so env vars actually get parsed.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class Settings extends Model {
    public string $pluginName = 'QR Payments';
    public string $beneficiaryName = '';
    public string $iban = '';
    public string $bic = '';
    public string $referencePrefix = 'PAY';
    public int $defaultDueDateDays = 30;

    /**
     * @var string Site route segment the public "pay" and "status" pages
     * are served under (e.g. `betalen` instead of the default
     * `qr-payments/payments`), so the plugin handle doesn't have to show
     * up in a customer-facing production URL. No leading or trailing slash.
     */
    public string $paymentRoute = 'qr-payments/payments';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_paymentCreated`, when set.
     */
    public string $paymentCreatedTemplate = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_statusChanged`, when set.
     */
    public string $paymentStatusChangedTemplate = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/components/_pay`, when set.
     */
    public string $payTemplate = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_paymentReminder`, when set.
     */
    public string $paymentReminderTemplate = '';

    /**
     * @var int[] User element IDs the payment reminder digest is sent to.
     */
    public array $reminderRecipients = [];

    /**
     * `_includes/forms/elementSelect.twig` always posts a plain-string
     * placeholder input for `reminderRecipients` so “nothing selected” is
     * distinguishable from “field not submitted” — normalize that here
     * before it hits the typed `array` property, since with zero elements
     * actually selected no `[]` inputs exist to make it an array on its own.
     *
     * @param array|null $values
     * @param bool $safeOnly
     * @return void
     */
    public function setAttributes($values, $safeOnly = true): void {
        if (isset($values['reminderRecipients']) && !is_array($values['reminderRecipients'])) {
            $values['reminderRecipients'] = $values['reminderRecipients'] === '' ? [] : (array) $values['reminderRecipients'];
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * @return string
     */
    public function getBeneficiaryName(): string {
        return (string) (App::parseEnv($this->beneficiaryName) ?? '');
    }

    /**
     * @return string
     */
    public function getIban(): string {
        return (string) (App::parseEnv($this->iban) ?? '');
    }

    /**
     * @return string|null
     */
    public function getBic(): ?string {
        $bic = App::parseEnv($this->bic);

        return $bic ? (string) $bic : null;
    }

    /**
     * @return array
     */
    protected function defineRules(): array {
        return array_merge(parent::defineRules(), [
            [['beneficiaryName', 'iban'], 'required'],
            [['pluginName', 'beneficiaryName', 'iban', 'bic', 'paymentRoute', 'paymentCreatedTemplate', 'paymentStatusChangedTemplate', 'payTemplate', 'paymentReminderTemplate'], 'string'],
            [['beneficiaryName', 'iban'], 'validateEnvVarResolves'],
            [['iban'], 'validateIbanValue'],
            [['referencePrefix'], 'string', 'max' => 26],
            [['defaultDueDateDays'], 'required'],
            [['defaultDueDateDays'], 'integer', 'min' => 1],
            [['paymentRoute'], 'required'],
            [
                ['paymentRoute'], 'match',
                'pattern' => '/^[a-zA-Z0-9_-]+(\/[a-zA-Z0-9_-]+)*$/',
                'message' => Craft::t('qr-payments', 'This can only contain letters, numbers, hyphens, and underscores, grouped into segments with a “/”. No leading or trailing slash.'),
            ],
            [['paymentCreatedTemplate', 'paymentStatusChangedTemplate', 'payTemplate', 'paymentReminderTemplate'], 'validateTemplateExists'],
            [['reminderRecipients'], 'validateReminderRecipients'],
        ]);
    }

    /**
     * Catches a `$ENV_VAR` reference that doesn't resolve to anything: the
     * raw attribute is non-empty (so `required` passes it), but the actual
     * value used at generation time would be blank.
     *
     * @param string $attribute
     * @return void
     */
    public function validateEnvVarResolves(string $attribute): void {
        if ($this->$attribute === '') return;

        $getter = 'get' . ucfirst($attribute);

        if ($this->$getter() === '') {
            $this->addError($attribute, Craft::t('qr-payments', 'Could not resolve a value for this environment variable.'));
        }
    }

    /**
     * Catches a typo'd override template path at save time, rather than
     * only failing once a payment email actually tries to send.
     *
     * @param string $attribute
     * @return void
     */
    public function validateTemplateExists(string $attribute): void {
        $template = $this->$attribute;

        if ($template === '') return;

        if (!Craft::$app->getView()->doesTemplateExist($template, View::TEMPLATE_MODE_SITE)) {
            $this->addError($attribute, Craft::t('qr-payments', 'Could not find the template “{template}”.', [
                'template' => $template,
            ]));
        }
    }

    /**
     * Confirms every posted recipient is an existing user id — the
     * `elementSelectField` in the settings template only ever posts ids it
     * resolved from a real user, but this also catches a stale id left over
     * from a since-deleted user.
     *
     * @param string $attribute
     * @return void
     */
    public function validateReminderRecipients(string $attribute): void {
        $ids = $this->$attribute;

        if (!$ids) return;

        foreach ($ids as $id) {
            if (!is_numeric($id)) {
                $this->addError($attribute, Craft::t('qr-payments', 'One or more selected recipients could not be found.'));
                return;
            }
        }

        $ids = array_map('intval', $ids);

        if (User::find()->id($ids)->status(null)->count() !== count(array_unique($ids))) {
            $this->addError($attribute, Craft::t('qr-payments', 'One or more selected recipients could not be found.'));
        }
    }

    /**
     * Runs the same checksum, format, and SEPA-country checks used when
     * actually generating a payment QR code, so a bad IBAN is caught here
     * instead of only failing once someone tries to create a payment.
     *
     * @param string $attribute
     * @return void
     */
    public function validateIbanValue(string $attribute): void {
        if ($this->$attribute === '') return;

        $iban = $this->getIban();

        if ($iban === '') return;

        try {
            QrPayments::getInstance()->getEpcQr()->validateIban($iban);
        } catch (InvalidArgumentException $e) {
            $this->addError($attribute, $e->getMessage());
        }
    }
}
