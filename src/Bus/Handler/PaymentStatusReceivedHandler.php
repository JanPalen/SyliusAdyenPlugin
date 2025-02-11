<?php

/*
 * This file has been created by developers from BitBag.
 * Feel free to contact us once you face any issues or want to start
 * You can find more information about us on https://bitbag.io and write us
 * an email on hello@bitbag.io.
 */

declare(strict_types=1);

namespace BitBag\SyliusAdyenPlugin\Bus\Handler;

use BitBag\SyliusAdyenPlugin\Bus\Command\CreateReferenceForPayment;
use BitBag\SyliusAdyenPlugin\Bus\Command\PaymentStatusReceived;
use BitBag\SyliusAdyenPlugin\Bus\DispatcherInterface;
use BitBag\SyliusAdyenPlugin\Exception\UnmappedAdyenActionException;
use BitBag\SyliusAdyenPlugin\Traits\OrderFromPaymentTrait;
use SM\Factory\FactoryInterface;
use Sylius\Component\Core\Model\OrderInterface;
use Sylius\Component\Core\Model\PaymentInterface;
use Sylius\Component\Core\OrderCheckoutTransitions;
use Sylius\Component\Resource\Repository\RepositoryInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final class PaymentStatusReceivedHandler
{
    use OrderFromPaymentTrait;

    public const ALLOWED_EVENT_NAMES = ['authorised', 'redirectshopper', 'received'];

    /** @var FactoryInterface */
    private $stateMachineFactory;

    /** @var RepositoryInterface */
    private $paymentRepository;

    /** @var DispatcherInterface */
    private $dispatcher;

    /** @var RepositoryInterface */
    private $orderRepository;

    /** @var MessageBusInterface */
    private $commandBus;

    public function __construct(
        FactoryInterface $stateMachineFactory,
        RepositoryInterface $paymentRepository,
        RepositoryInterface $orderRepository,
        DispatcherInterface $dispatcher,
        MessageBusInterface $commandBus,
    ) {
        $this->stateMachineFactory = $stateMachineFactory;
        $this->paymentRepository = $paymentRepository;
        $this->dispatcher = $dispatcher;
        $this->orderRepository = $orderRepository;
        $this->commandBus = $commandBus;
    }

    public function __invoke(PaymentStatusReceived $command): void
    {
        $payment = $command->getPayment();
        $resultCode = $this->getResultCode($command->getPayment());

        if ($this->isAccepted($resultCode)) {
            $this->updateOrderState($this->getOrderFromPayment($payment));
        }

        try {
            $this->dispatcher->dispatch(new CreateReferenceForPayment($payment));
            $this->paymentRepository->add($payment);

            $this->processCode($resultCode, $command);
        } catch (\InvalidArgumentException $ex) {
            // probably redirect, we don't have a pspReference at this stage
        }
    }

    private function processCode(string $resultCode, PaymentStatusReceived $command): void
    {
        try {
            $subcommand = $this->dispatcher->getCommandFactory()->createForEvent($resultCode, $command->getPayment());
            $this->dispatcher->dispatch($subcommand);
        } catch (UnmappedAdyenActionException $ex) {
            // nothing here
        }
    }

    private function updateOrderState(OrderInterface $order): void
    {
        $sm = $this->stateMachineFactory->get($order, OrderCheckoutTransitions::GRAPH);
        if ($sm->can(OrderCheckoutTransitions::TRANSITION_COMPLETE)) {
            $sm->apply(OrderCheckoutTransitions::TRANSITION_COMPLETE, true);

            $this->orderRepository->add($order);

            $token = $order->getTokenValue();

            // This is necessary because in Sylius 1.11 namespace of SendOrderConfirmation has been changed
            if (null !== $token) {
                /**
                 * @psalm-suppress MixedArgument
                 * @psalm-suppress UndefinedClass
                 */
                if (class_exists('\Sylius\Bundle\ApiBundle\Command\SendOrderConfirmation')) {
                    $this->commandBus->dispatch(new \Sylius\Bundle\ApiBundle\Command\SendOrderConfirmation($token));
                } elseif (class_exists('\Sylius\Bundle\ApiBundle\Command\Checkout\SendOrderConfirmation')) {
                    /**
                     * @psalm-suppress MixedArgument
                     * @psalm-suppress UndefinedClass
                     */
                    $this->commandBus->dispatch(new \Sylius\Bundle\ApiBundle\Command\Checkout\SendOrderConfirmation($token));
                }
            }
        }
    }

    private function getResultCode(PaymentInterface $payment): string
    {
        $details = $payment->getDetails();

        return strtolower((string) $details['resultCode']);
    }

    private function isAccepted(string $resultCode): bool
    {
        return in_array($resultCode, self::ALLOWED_EVENT_NAMES, true);
    }
}
