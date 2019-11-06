<?php

namespace Combodo\StripeV3\Action;

use Combodo\StripeV3\Exception\TokenNotFound;
use Combodo\StripeV3\Keys;
use Combodo\StripeV3\Request\handleCheckoutCompletedEvent;
use Combodo\StripeV3\Request\HandlePaymentIntentSucceededEvent;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetBinaryStatus;
use Payum\Core\Request\GetToken;
use Payum\Core\Security\TokenInterface;
use Stripe\Customer;
use Stripe\Event;
use Stripe\PaymentIntent;
use Stripe\Plan;
use Stripe\Stripe;
use Stripe\Subscription;

class PaymentIntentSucceededAction implements ActionInterface, GatewayAwareInterface, ApiAwareInterface, PaymentIntentSucceededInformationProvider
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

    /** @var GetBinaryStatus $status */
    private $status;
    /** @var TokenInterface $token */
    private $token;

    /**
     * {@inheritdoc}
     *
     * @param handleCheckoutCompletedEvent $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $event = $request->getEvent();
        $this->handleEvent($event);
    }

    private function handleEvent(Event $event): void
    {
        $metadata = $event->data->object->metadata;
        $tokenHash = $metadata->client_reference_id;

        $this->token = $this->findTokenByHash($tokenHash);
        $this->status = $this->findStatusByToken($this->token);

        $this->status->markCaptured();

        $paymentIntent = $event->data->object;
        Stripe::setApiKey($this->api->getSecretKey());

        // create Customer based on PaymentIntent and subscription
        $customer = Customer::create([
            'email' => $paymentIntent->charges->data[0]->billing_details->email,
            'payment_method' => $paymentIntent->payment_method,
            'invoice_settings' => [
                'default_payment_method' => $paymentIntent->payment_method,
            ],
        ]);

        if (!isset($metadata->plan)) {
            $plan = Plan::create([
                'amount' => $paymentIntent->amount,
                'currency' => $paymentIntent->currency,
                'interval' => $paymentIntent->metadata->interval,
                'product' => $paymentIntent->metadata->productId,
            ]);

            $metadata->plan = $plan->id;
        }

        // add +1 month or year because the user has been charged already
        // this prevents double charge on the first billing period
        Subscription::create([
            'customer' => $customer->id,
            'items' => [['plan' => $metadata->plan]],
            'expand' => ['latest_invoice.payment_intent'],
            'trial_end' => strtotime('+1 '.$metadata->interval, $paymentIntent->created),
        ]);
    }

    private function findTokenByHash($tokenHash): TokenInterface
    {
        $getTokenRequest = new GetToken($tokenHash);

        try {
            $this->gateway->execute($getTokenRequest);
        } catch (LogicException $exception) {
            throw new TokenNotFound('The requested token was not found');
        }

        $token = $getTokenRequest->getToken();

        return $token;
    }

    private function findStatusByToken(TokenInterface $token): GetBinaryStatus
    {
        $status = new GetBinaryStatus($token);
        $this->gateway->execute($status);

        if (empty($status->getValue())) {
            throw new LogicException('The payment status could not be fetched');
        }

        return $status;
    }

    public function getStatus(): ?GetBinaryStatus
    {
        return $this->status;
    }

    public function getToken(): ?TokenInterface
    {
        return $this->token;
    }

    public function supports($request)
    {
        return $request instanceof HandlePaymentIntentSucceededEvent;
    }
}
