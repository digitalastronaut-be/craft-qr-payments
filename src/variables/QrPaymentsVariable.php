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

namespace digitalastronaut\craftqrpayments\variables;

use Craft;

use yii\base\Behavior;

use digitalastronaut\craftqrpayments\elements\db\PaymentQuery;
use digitalastronaut\craftqrpayments\elements\Payment;

/**
 * Class QrPaymentsVariable
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.0.0-beta
 */
class QrPaymentsVariable extends Behavior {
    /**
     * @return self
     */
    public function getQrPayments(): self {
        return $this;
    }

    /**
     * @param array $criteria
     * @return PaymentQuery
     */
    public function payments(array $criteria = []): PaymentQuery {
        $query = Payment::find();
        Craft::configure($query, $criteria);
        return $query;
    }
}
