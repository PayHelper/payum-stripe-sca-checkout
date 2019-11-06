<?php

namespace Combodo\StripeV3\Action\Api;

use Combodo\StripeV3\Request\Api\CreatePaymentIntent;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Bridge\Spl\ArrayObject;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Reply\HttpResponse;
use Combodo\StripeV3\Keys;
use Combodo\StripeV3\Request\Api\ObtainToken;
use Stripe\Checkout\Session;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\Plan;
use Stripe\Stripe;
use Stripe\Subscription;

/**
 * @property Keys $keys alias of $api
 * @property Keys $api
 */
class CreatePaymentIntentAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface
{
    use ApiAwareTrait {
        setApi as _setApi;
    }
    use GatewayAwareTrait;

    /**
     * @var string
     */
    protected $templateName;

    /**
     * @deprecated BC will be removed in 2.x. Use $this->api
     *
     * @var Keys
     */
    protected $keys;

    public function __construct()
    {
        $this->apiClass = Keys::class;
    }

    /**
     * {@inheritdoc}
     */
    public function setApi($api)
    {
        $this->_setApi($api);

        // Has more meaning than api since it is just the api keys!
        $this->keys = $this->api;
    }

    /**
     * {@inheritdoc}
     */
    public function execute($request)
    {
        /* @var $request ObtainToken */
        RequestNotSupportedException::assertSupports($this, $request);

        $model = ArrayObject::ensureArrayObject($request->getModel());

        if (null === $model['plan'] && !isset($model['metadata']['productId']) && '' === $model['metadata']['productId']) {
            throw new LogicException('The product id has to be set.');
        }

        if (isset($model['metadata']['payment_intent_id']) && !empty($model['metadata']['payment_intent_id'])) {
            Stripe::setApiKey($this->keys->getSecretKey());

            $intervalsMap = [
                '1 month' => 'month',
                '1 year' => 'year',
            ];

            $params = [
                'client_reference_id' => $request->getToken()->getHash(),
                'plan' => $model['plan'] ?? null,
                'order_id' => $model['order_id'],
                'productId' => $model['metadata']['productId'] ?? null,
            ];

            if ('3 months' === $model['interval']) {
                $params['interval'] = 'month';
                $params['interval_count'] = 3;
            } else {
                $params['interval'] = $intervalsMap[$model['interval']];
            }

            PaymentIntent::update($model['metadata']['payment_intent_id'], [
                'metadata' => $params,
            ]);

//            // create subscription here?
//            // create Customer based on PaymentIntent and subscription
//            $customer = Customer::create([
//                'email' => $model['metadata']['email'],
//                'payment_method' => $paymentIntent->payment_method,
//                'invoice_settings' => [
//                    'default_payment_method' => $paymentIntent->payment_method,
//                ],
//            ]);
//
//            if (!isset($model['plan'])) {
//                $plan = Plan::create([
//                    'amount' => $paymentIntent->amount,
//                    'currency' => $paymentIntent->currency,
//                    'interval' => $paymentIntent->metadata->interval,
//                    'product' => $paymentIntent->metadata->productId,
//                ]);
//
//                $model['plan'] = $plan->id;
//            }
//
//            Subscription::create([
//                'customer' => $customer->id,
//                'items' => [['plan' => $model['plan']]],
//                'expand' => ['latest_invoice.payment_intent'],
//            ]);
        }

        throw new HttpResponse('');
    }

    /**
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof CreatePaymentIntent &&
            $request->getModel() instanceof \ArrayAccess
        ;
    }

    /**
     * @param             $request
     * @param ArrayObject $model
     *
     * @return Session
     */
    private function obtainSession(ObtainToken $request, ArrayObject $model): Session
    {
        Stripe::setApiKey($this->keys->getSecretKey());

        $rawUrl = $request->getToken()->getTargetUrl();
        $separator = self::computeSeparator($rawUrl);
        $successUrl = "{$rawUrl}{$separator}checkout_status=completed";

        $rawUrl = $request->getToken()->getAfterUrl();
        $separator = self::computeSeparator($rawUrl);
        $cancelUrl = "{$rawUrl}{$separator}checkout_status=canceled";

        if (!array_key_exists('plan', $model)) {
            throw new LogicException('The subscription plan has to be set.');
        }

        // create a new pricing plan
        if (null === $model['plan']) {
            if (!isset($model['metadata']['productId']) && '' === $model['metadata']['productId']) {
                throw new LogicException('The product id has to be set.');
            }

            $intervalsMap = [
                '1 month' => 'month',
                '3 months' => 'quarterly',
                '1 year' => 'yearly',
            ];

            $plan = Plan::create([
                'amount' => $model['amount'],
                'currency' => $model['currency'],
                'interval' => $intervalsMap[$model['interval']],
                'product' => $model['metadata']['productId'],
            ]);

            $model['plan'] = $plan->id;
        }

        $params = [
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'payment_method_types' => ['card'],
            'mode' => 'subscription',
            'subscription_data' => [
                'items' => [[
                    'plan' => $model['plan'],
                ]],
                'metadata' => [
                    'client_reference_id' => $request->getToken()->getHash(),
                ],
            ],
            'client_reference_id' => $request->getToken()->getHash(),
            'customer_email' => $model['metadata']['email'] ?? null,
        ];

        $session = Session::create($params);

        return $session;
    }

    /**
     * @param string $rawUrl
     *
     * @return string
     */
    private static function computeSeparator(string $rawUrl): string
    {
        $query = parse_url($rawUrl, PHP_URL_QUERY);
        if ('' != $query) {
            $separator = '&';
        } else {
            $separator = '?';
        }

        return $separator;
    }
}
