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

namespace digitalastronaut\craftqrpayments\console\controllers;

use digitalastronaut\craftqrpayments\QrPayments;

use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Class SubscriptionController
 *
 * One cron entry (`qr-payments/subscription/advance`, run at least daily)
 * advances every active subscription whose `nextDueDate` has arrived:
 * `SubscriptionsService::advance()` stamps out that cycle's `Payment` and
 * pushes `nextDueDate` forward by the subscription's `interval`. No
 * gateway integration, same as a one-off payment — this only ever creates
 * the next payment for manual reconciliation.
 *
 * @author      Digitalastronaut
 * @package     QrPayments
 * @since       v1.5.0-beta
 */
class SubscriptionController extends Controller {
    public $defaultAction = 'advance';

    /**
     * @return int
     */
    public function actionAdvance(): int {
        $subscriptions = QrPayments::getInstance()->getSubscriptions()->getDueSubscriptions();

        if (empty($subscriptions)) {
            $this->stdout("Nothing to advance\n");

            return ExitCode::OK;
        }

        $advanced = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $payment = QrPayments::getInstance()->getSubscriptions()->advance($subscription);
            } catch (Throwable $e) {
                $this->stderr("Failed to advance subscription #{$subscription->id}: {$e->getMessage()}\n");
                continue;
            }

            $this->stdout("Advanced subscription #{$subscription->id}, created payment #{$payment->id}\n");

            $advanced++;
        }

        $this->stdout("Advanced {$advanced} of " . count($subscriptions) . " due subscription(s)\n");

        return ExitCode::OK;
    }
}
