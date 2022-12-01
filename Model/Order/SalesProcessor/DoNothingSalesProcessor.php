<?php
/**
 * Copyright © MageWorx. All rights reserved.
 * See LICENSE.txt for license details.
 */
declare(strict_types=1);

namespace MageWorx\OrderEditorSalesProcessor\Model\Order\SalesProcessor;

use Magento\Framework\Exception\LocalizedException;
use Magento\Sales\Api\Data\OrderInterface;
use MageWorx\OrderEditor\Api\SalesProcessorInterface;
use MageWorx\OrderEditor\Model\Order;
use MageWorx\OrderEditor\Model\Order\SalesProcessorAbstract;

/**
 * Class DoNothingSalesProcessor
 *
 * Used to perform operations with related entities like: invoice, creditmemo, shipment.
 * Does nothing with existing invoices and creditmemos.
 */
class DoNothingSalesProcessor extends SalesProcessorAbstract implements SalesProcessorInterface
{
    /**
     * Update credit-memos, invoices, shipments
     *
     * @return bool
     */
    public function updateSalesObjects(): bool
    {
        try {
            $order = $this->getOrder();
            if ($order === null) {
                throw new LocalizedException(__('Order is not set!'));
            }

            $this->syncQuoteOfTheOrder($order);

            $this->eventManager->dispatch(
                'mw_oe_process_sales_object_before',
                [
                    'subject' => $this,
                    'order'   => $order
                ]
            );

            $this->returnItems($order);
            // Do nothing & sync info in payment
            $this->updatePayment();

            $this->eventManager->dispatch(
                'mw_oe_process_sales_object_after',
                [
                    'subject' => $this,
                    'order'   => $order
                ]
            );
        } catch (\Exception $e) {
            $this->eventManager->dispatch(
                'mw_oe_process_sales_object_error',
                [
                    'subject'   => $this,
                    'order'     => $order,
                    'exception' => $e
                ]
            );

            return false;
        }

        return true;
    }

    /**
     * @param Order $order
     * @return void
     * @throws LocalizedException
     */
    protected function returnItems(Order $order): void
    {
        if ($order->hasRemovedItems() || $order->hasItemsWithDecreasedQty()) {
            $decreasedItems = $order->getDecreasedItems();
            $removedItems   = $order->getRemovedItems();
            // Removed items has priority against decreased items. Do not change the order!
            $itemsToRemove  = $removedItems + $decreasedItems;

            foreach ($itemsToRemove as $removedOrderItemId => $qty) {
                $orderItem   = $order->getItemById($removedOrderItemId);
                if (!$orderItem) {
                    continue;
                }

                $qtyToRemove = $itemsToRemove[$orderItem->getItemId()] ?? 0;
                $orderItem->setQtyOrdered($orderItem->getQtyOrdered() - $qtyToRemove);
                $this->oeOrderItemRepository->save($orderItem);
            }
        }
    }

    /**
     * Update payment object
     *
     * @return void
     */
    protected function updatePayment(): void
    {
        $order   = $this->getOrder();
        $payment = $this->getOrder()->getPayment();
        $payment->setAmountOrdered($order->getGrandTotal())
                ->setBaseAmountOrdered($order->getBaseGrandTotal())
                ->setBaseShippingAmount($order->getBaseShippingAmount())
                ->setShippingCaptured($order->getShippingInvoiced())
                ->setAmountRefunded($order->getTotalRefunded())
                ->setBaseAmountPaid($order->getBaseTotalPaid())
                ->setAmountCanceled($order->getTotalCanceled())
                ->setBaseAmountAuthorized($order->getBaseTotalInvoiced())
                ->setBaseAmountPaidOnline($order->getBaseTotalInvoiced())
                ->setBaseAmountRefundedOnline($order->getBaseTotalRefunded())
                ->setBaseShippingAmount($order->getBaseShippingAmount())
                ->setShippingAmount($order->getShippingAmount())
                ->setAmountPaid($order->getTotalInvoiced())
                ->setAmountAuthorized($order->getTotalInvoiced())
                ->setBaseAmountOrdered($order->getBaseGrandTotal())
                ->setBaseShippingRefunded($order->getBaseShippingRefunded())
                ->setShippingRefunded($order->getShippingRefunded())
                ->setBaseAmountRefunded($order->getBaseTotalRefunded())
                ->setAmountOrdered($order->getGrandTotal())
                ->setBaseAmountCanceled($order->getBaseTotalCanceled());

        $this->orderPaymentRepository->save($payment);
    }

    /**
     * Check is order must be processed (invoiced, refunded, shipped)
     *
     * @param OrderInterface|Order $order
     * @return bool
     */
    public function isNeedToProcessOrder(\Magento\Sales\Api\Data\OrderInterface $order): bool
    {
        return false;
    }
}
