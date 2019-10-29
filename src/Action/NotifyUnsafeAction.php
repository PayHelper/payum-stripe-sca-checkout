<?php

namespace Combodo\StripeV3\Action;

use Combodo\StripeV3\Keys;
use Combodo\StripeV3\Request\handleCheckoutCompletedEvent;
use Combodo\StripeV3\Request\HandleSubscriptionCancelledEvent;
use Payum\Core\Action\ActionInterface;
use Payum\Core\ApiAwareInterface;
use Payum\Core\ApiAwareTrait;
use Payum\Core\Exception\LogicException;
use Payum\Core\Exception\RequestNotSupportedException;
use Payum\Core\GatewayAwareInterface;
use Payum\Core\GatewayAwareTrait;
use Payum\Core\Request\GetHttpRequest;
use Payum\Core\Request\Notify;
use Stripe\Event;
use Stripe\Webhook;

/**
 * Class NotifyAction.
 *
 * @property Keys $keys
 */
class NotifyUnsafeAction implements ActionInterface, ApiAwareInterface, GatewayAwareInterface
{
    use GatewayAwareTrait;

    use ApiAwareTrait {
        setApi as _setApi;
    }

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
     *
     * @param Notify $request
     */
    public function execute($request)
    {
        RequestNotSupportedException::assertSupports($this, $request);

        $event = $this->obtainStripeEvent();
        $this->handleStripeEvent($event);
    }

    /**
     * Accepting null models is not standard, but it is required due to the implementation of the symfony bundle's route `payum_notify_do_unsafe`
     * {@inheritdoc}
     */
    public function supports($request)
    {
        return
            $request instanceof Notify &&
            null === $request->getModel()
        ;
    }

    /**
     * @return array
     */
    private function obtainStripeEvent(): Event
    {
        $this->gateway->execute($httpRequest = new GetHttpRequest());

        if (empty($_SERVER['HTTP_STRIPE_SIGNATURE'])) {
            throw new LogicException('The stripe signature is mandatory', 400);
        }
        $sig_header = $_SERVER['HTTP_STRIPE_SIGNATURE'];
        $endpoint_secret = $this->keys->getEndpointSecretKey();
        $payload = $httpRequest->content;
        $event = null;

        try {
            $tolerance = Webhook::DEFAULT_TOLERANCE;

            $event = \Stripe\Webhook::constructEvent($payload, $sig_header, $endpoint_secret, $tolerance);
        } catch (\UnexpectedValueException $e) {
            throw new LogicException('Invalid payload', 400);
        } catch (\Stripe\Error\SignatureVerification $e) {
            throw new LogicException('Invalid signature', 400);
        }

        return $event;
    }

    /**
     * @param $request
     * @param $event
     */
    private function handleStripeEvent(Event $event): void
    {
        switch ($event->type) {
            case Event::CUSTOMER_SUBSCRIPTION_DELETED:
                $request = new HandleSubscriptionCancelledEvent($event, HandleSubscriptionCancelledEvent::TOKEN_CAN_BE_INVALIDATED);
                $this->gateway->execute($request);

                break;

            case Event::CHECKOUT_SESSION_COMPLETED:
                $request = new handleCheckoutCompletedEvent($event, handleCheckoutCompletedEvent::TOKEN_MUST_BE_KEPT);
                $this->gateway->execute($request);

                break;
        }
    }
}
