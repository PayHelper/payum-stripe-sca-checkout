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
use Stripe\PaymentIntent;
use Stripe\Stripe;

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
            Stripe::setApiKey($this->api->getSecretKey());

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
}
