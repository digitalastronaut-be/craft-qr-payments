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
use craft\db\Query;
use craft\elements\User;
use craft\helpers\App;
use craft\models\FieldLayout;
use craft\web\View;

use digitalastronaut\craftqrpayments\elements\Payment;
use digitalastronaut\craftqrpayments\helpers\Table;
use digitalastronaut\craftqrpayments\QrPayments;

use InvalidArgumentException;

/**
 * Class PaymentType
 *
 * Groups `Payment` elements that share a field layout — the same
 * relationship Craft's own `EntryType` has to `Section`. Every payment
 * belongs to exactly one type, which is what lets different kinds of
 * payments (e.g. a webshop order vs. a course booking) carry different
 * custom fields, their own beneficiary/IBAN/BIC, and their own email/pay
 * page templates. This is a plain database row, not project config, so —
 * unlike the plugin's global `Settings` — it stays editable on a live
 * site even with `allowAdminChanges` off.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.1.0-beta
 */
class PaymentType extends Model {
    public ?int $id = null;
    public ?string $uid = null;

    public string $name = '';
    public string $handle = '';
    public ?int $fieldLayoutId = null;
    public ?int $defaultDueDateDays = 30;

    /**
     * @var string Stored as a raw string so it can be an `$ENV_VAR`
     * reference (`suggestEnvVars` in the edit template) rather than a
     * plaintext name in the database; resolve with `getBeneficiaryName()`,
     * never this raw property, so env vars actually get parsed.
     */
    public string $beneficiaryName = '';

    /**
     * @var string Same `$ENV_VAR`-reference support as `$beneficiaryName`; resolve via `getIban()`.
     */
    public string $iban = '';

    /**
     * @var string Same `$ENV_VAR`-reference support as `$beneficiaryName`; resolve via `getBic()`. Optional — never required, unlike `$beneficiaryName`/`$iban`.
     */
    public string $bic = '';

    /**
     * @var string Prepended to every reference generated for a payment of
     * this type. Only letters and digits are kept; anything else is stripped.
     */
    public string $referencePrefix = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/components/_pay`, when set.
     */
    public string $payTemplate = '';

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
     * @var int[] User element IDs the payments-summary digest for this type is sent to. Empty means no summary digest for this type at all.
     */
    public array $reminderRecipients = [];

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_paymentsSummary`, when set. This is the
     * admin-facing digest of every needs-action payment of this type.
     */
    public string $paymentsSummaryTemplate = '';

    /**
     * @var string Site template path rendered instead of the plugin's
     * bundled `qr-payments/emails/_paymentReminder`, when set. This is the
     * payer-facing nudge sent 7 days before a payment is due, and again
     * the day it expires — not the admin-facing summary digest above.
     */
    public string $paymentReminderTemplate = '';

    private ?FieldLayout $_fieldLayout = null;

    /**
     * @return FieldLayout The saved layout for this type, or a fresh
     * unsaved one (typed to `Payment`) if none has been assigned yet —
     * so the field layout designer always has something to render.
     */
    public function getFieldLayout(): FieldLayout {
        if ($this->_fieldLayout !== null) return $this->_fieldLayout;

        $layout = $this->fieldLayoutId !== null
            ? Craft::$app->getFields()->getLayoutById($this->fieldLayoutId)
            : null;

        return $this->_fieldLayout = $layout ?? Craft::createObject([
            'class' => FieldLayout::class,
            'type' => Payment::class,
        ]);
    }

    /**
     * @param FieldLayout $fieldLayout
     * @return void
     */
    public function setFieldLayout(FieldLayout $fieldLayout): void {
        $this->_fieldLayout = $fieldLayout;
        $this->fieldLayoutId = $fieldLayout->id;
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
            [['name', 'handle', 'beneficiaryName', 'iban', 'defaultDueDateDays'], 'required'],
            [['name'], 'string', 'max' => 255],
            [['handle'], 'string', 'max' => 64],
            [
                ['handle'], 'match',
                'pattern' => '/^[a-zA-Z][a-zA-Z0-9_]*$/',
                'message' => Craft::t('qr-payments', 'This can only contain letters, numbers, and underscores, and must start with a letter.'),
            ],
            [['handle'], 'validateHandleIsUnique'],
            [['defaultDueDateDays'], 'integer', 'min' => 1],

            [['beneficiaryName'], 'string', 'max' => 70],
            [['iban'], 'string', 'max' => 34],
            [['bic'], 'string', 'max' => 11],
            [['beneficiaryName', 'iban'], 'validateEnvVarResolves'],
            [['iban'], 'validateIbanValue'],

            [['referencePrefix'], 'string', 'max' => 26],
            [['payTemplate', 'paymentCreatedTemplate', 'paymentStatusChangedTemplate', 'paymentsSummaryTemplate', 'paymentReminderTemplate'], 'string'],
            [['payTemplate', 'paymentCreatedTemplate', 'paymentStatusChangedTemplate', 'paymentsSummaryTemplate', 'paymentReminderTemplate'], 'validateTemplateExists'],

            [['reminderRecipients'], 'validateReminderRecipients'],
        ]);
    }

    /**
     * @param string $attribute
     * @return void
     */
    public function validateHandleIsUnique(string $attribute): void {
        $query = (new Query())
            ->from(Table::QR_PAYMENTS_PAYMENTTYPES)
            ->where(['handle' => $this->$attribute]);

        if ($this->id) $query->andWhere(['not', ['id' => $this->id]]);

        if ($query->exists()) {
            $this->addError($attribute, Craft::t('qr-payments', 'This handle is already in use.'));
        }
    }

    /**
     * Catches a `$ENV_VAR` reference that doesn't resolve to anything: the
     * raw attribute is non-empty (so `required` passes it), but the actual
     * value used at payment generation time would be blank.
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

    /**
     * Confirms every posted recipient is an existing user id — the
     * `elementSelectField` in the edit template only ever posts ids it
     * resolved from a real user, but this also catches a stale id left
     * over from a since-deleted user.
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

        if ((int) User::find()->id($ids)->status(null)->count() !== count(array_unique($ids))) {
            $this->addError($attribute, Craft::t('qr-payments', 'One or more selected recipients could not be found.'));
        }
    }

    /**
     * Catches a typo'd override template path at save time, rather than
     * only failing once a payment/email actually tries to render it.
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
}
