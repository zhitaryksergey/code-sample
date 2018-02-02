<?php

namespace common\components;

use Carbon\Carbon;
use common\models\Customer;
use common\models\CustomerPlans;
use common\models\InvoiceLine;
use common\models\PaymentMethod;
use common\models\SubscriptionPendingCancel;
use common\models\SubscriptionPendingDowngrade;
use Yii;
use yii\base\Component;

/**
 * Class RenewalService
 * @package common\components
 */
class RenewalService extends Component
{
    /**
     * @param $customerId
     * @param $paymentMethodId
     * @param $subscriptionIds
     */
    public function renewal($customerId, $paymentMethodId, $subscriptionIds)
    {
        $customer = Customer::findOne($customerId);
        $subscriptions = CustomerPlans::find()->with('subscription')->where(['id' => $subscriptionIds])->all();
        $pendingCancelIds = from(
            SubscriptionPendingCancel::find()
                ->select(['customer_plans_id'])
                ->where(['customer_plans_id' => $subscriptionIds])
                ->all()
        )->select(function ($v) {
            return $v->customer_plans_id;
        })->toList();

        $subsToCancel = from($subscriptions)
            ->where(function ($v) use ($pendingCancelIds) {
                return in_array($v->id, $pendingCancelIds);
            });

        foreach ($subsToCancel as $sub) {
            Yii::$app->cancelService->cancelSubscription($sub);
        }

        $activeSubs = from($subscriptions)->where(function ($v) {
            return $v->status = CustomerPlans::STATUS_ACTIVE;
        })->toList();
        if (count($activeSubs) == 0) {
            return;
        }

        $pendingDowngrade =
            SubscriptionPendingDowngrade::find()
                ->where(['customer_plans_id' => $subscriptionIds])
                ->indexBy('customer_plans_id')
                ->all();

        foreach ($activeSubs as $sub) {
            if (array_key_exists($sub->id, $pendingDowngrade)) {
                $downgrade = $pendingDowngrade[$sub->id];
                $sub->rate = $downgrade->price;
                $sub->plan_id = $downgrade->new_plan_id;
                $sub->subscription_id = $downgrade->new_subscription_id;
                $sub->save();
                $downgrade->delete();

                $this->sendDowngrade($sub);
            }
        }

        $invoiceLines = [];
        foreach ($activeSubs as $sub) {
            $line = new InvoiceLine();
            $line->customer_plans_ID = $sub->id;
            $line->cost = $sub->rate;
            $line->text = $sub->subscription->name;
            $invoiceLines[] = $line;
        }

        $invoice = \Yii::$app->invoiceGenerator->generate(
            $customerId,
            $invoiceLines,
            $paymentMethodId,
            'incomplete'
        );

        foreach ($activeSubs as $sub) {
            $sub->updateBillDates();
        }

        if ($invoice->state == 'paid') {
            $this->sendActive($activeSubs);
        } else if (!PaymentMethod::isCheckOrCash($paymentMethodId)) {
            foreach ($invoiceLines as $invoiceLine) {
                $invoiceLine->customerPlans->status = CustomerPlans::STATUS_BILLING_SUSPEND;
                $invoiceLine->customerPlans->save(false);
            }
            $this->sendSuspend($activeSubs);
            Yii::$app->emailGenerator->paymentDueEmail($invoice);
        }

        $intervals = from($activeSubs)->groupBy(function ($v) {
            return $v->plan->billing_interval;
        })->select(function ($v, $k) {
            return $k;
        })->toList();

        foreach ($intervals as $interval) {
            $customerTerm = $customer->termByInterval($interval);
            if ($customerTerm != null && Carbon::parse($customerTerm)->isToday()) {
                $customer->updateTermByInterval($interval, Carbon::now()->addSeconds($interval)->toDateString());
            }
        }

    }

    /**
     * @param $customerPlans
     */
    private function sendDowngrade($customerPlans)
    {
        Yii::$app->callbackSenderService->sendDowngrade($customerPlans);
    }

    /**
     * @param $subscriptions
     */
    private function sendActive($subscriptions)
    {
        $deviceIds = from($subscriptions)->select(function ($v) {
            return $v->device_id;
        })->toArray();

        Yii::$app->callbackSenderService->sendActive($deviceIds);
    }

    /**
     * @param $subscriptions
     */
    private function sendSuspend($subscriptions)
    {
        $deviceIds = from($subscriptions)->select(function ($v) {
            return $v->device_id;
        })->toArray();

        Yii::$app->callbackSenderService->sendSuspend($deviceIds);
    }

}