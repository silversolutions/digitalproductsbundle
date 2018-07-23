<?php
/**
 * Product silver.e-shop
 *
 * A powerful e-commerce solution for B2B online shops / portals and complex
 * online applications that have access to ERP data, usually in real time.
 * http://www.silversolutions.de/eng/Products/silver.e-shop
 *
 * This file contains the class OrderConfirmationListener
 *
 * @copyright Copyright (C) 2013 silver.solutions GmbH. All rights reserved.
 * @license see vendor/silversolutions/silver.e-shop/license_txt_ger.pdf
 * @version $Version$
 * @package silver.e-shop
 */

namespace Siso\DigitalProducts\EventListener;

use Silversolutions\Bundle\EshopBundle\Entities\Messages\CreateSalesOrderMessage;
use Silversolutions\Bundle\EshopBundle\Entities\Messages\Document\Order;
use Silversolutions\Bundle\EshopBundle\Entities\Messages\Document\OrderResponse;
use Silversolutions\Bundle\EshopBundle\Entity\Basket;
use Silversolutions\Bundle\EshopBundle\Entity\BasketLine;
use Silversolutions\Bundle\EshopBundle\Entity\BasketRepository;

use Silversolutions\Bundle\EshopBundle\Message\Event\MessageResponseEvent;
use Silversolutions\Bundle\EshopBundle\Message\Event\MessageExceptionEvent;
use Siso\Bundle\LocalOrderManagementBundle\Exception\LocalOrderRequiredException;
use Silversolutions\Bundle\TranslationBundle\Services\TransService;
use Siso\Bundle\CheckoutBundle\Model\CheckoutSources;
use Siso\Bundle\ToolsBundle\Service\MailHelperServiceInterface;

use Silversolutions\Bundle\EshopBundle\Services\Catalog\CatalogDataProviderService;
use Silversolutions\Bundle\EshopBundle\Product\ProductNode;



class DownloadOrderListener {

    /**
     * @var BasketRepository
     */
    protected $basketRepository;

    /**
     * @var TransService
     */
    protected $translator;


    /**
     * @var MailHelperServiceInterface
     */
    protected $mailerService;

    /**
     * @var array
     */
    protected $mailSettings;

    /**
     * @var \Silversolutions\Bundle\EshopBundle\Services\Catalog\CatalogDataProviderService
     */
    protected $catalogService;


    /**
     * @param BasketRepository $basketRepository
     * @param TransService $translator
     * @param MailHelperServiceInterface $mailerService
     * @param CatalogDataProviderService $catalogService
     * @param array $mailSettings
     */
    public function __construct(
        BasketRepository $basketRepository,
        TransService $translator,
        MailHelperServiceInterface $mailerService,
        CatalogDataProviderService $catalogService,
        array $mailSettings
    ) {
        $this->basketRepository = $basketRepository;
        $this->translator = $translator;
        $this->mailerService = $mailerService;
        $this->mailSettings = $mailSettings;
        $this->catalogService = $catalogService;
    }


    /**
     * Listens to the MessageExceptionEvent event.
     * The appropriate exception was thrown by the previous listener ("sendMessage" of the "AbstractMessageTransport")
     * This function:
     * - checks for downloads and send an email to the customer
     *
     * @param MessageExceptionEvent $messageExceptionEvent
     * @return void
     *
     */
    public function onExceptionMessage(MessageExceptionEvent $messageExceptionEvent)
    {
        $message = $messageExceptionEvent->getMessage();
        $exception = $messageExceptionEvent->getException();
        if ($message instanceof CreateSalesOrderMessage && $exception instanceof LocalOrderRequiredException) {
            /** @var Order $requestDocument */
            $requestDocument = $message->getRequestDocument();

            /** @var Basket $basket */
            $basket = $this->basketRepository->findOneBy(array('guid' => $requestDocument->UUID->value));

            $this->checkForDownloads($basket);
        }
    }


    /**
     * This method must registered as a listener to the
     * 'silver_eshop.response_message' event. It will act in case an ERP is in place
     *
     * @param MessageResponseEvent $event
     */
    public function onOrderResponse(MessageResponseEvent $event)
    {
        $message = $event->getMessage();
        if ($message instanceof CreateSalesOrderMessage) {

            /** @var Order $requestDoc */
            $requestDoc = $message->getRequestDocument();

            $basket = $this->basketRepository->findOneBy(array('guid' => $requestDoc->UUID->value));
            $this->checkForDownloads($basket);
        }
    }

    /**
     * Check for downloads in Order and send email
     *
     * @param Basket $basket
     * @return void
     *
     */
    protected function checkForDownloads(Basket $basket)
    {

        // The order was transmitted successfully, if the response contains the order ID
        if ($basket instanceof Basket ) {
            $downloadUrls = array();

            /** @var BasketLine[] $basketLines */
            $basketLines = $basket->getLines()->getValues();

            foreach($basketLines as $basketLine) {
                $productNode = $this->getProductNode($basketLine->getCatalogElement(),$basketLine->getSku());
                $dataMap = $productNode->getDataMap();
                if (isset($dataMap['isDownload']) && $dataMap['isDownload']->bool) {
                    if (isset($dataMap['downloadFile'])) {
                        $downloadUrls[] = $dataMap['downloadFile'];
                    }

                }
            }


            // Send customer a separate email with download links
            if (count($downloadUrls) > 0) {
                $recipientBuyer = $basket->getConfirmationEmail();
                $this->sendMailToRecipient($recipientBuyer, $basket, $downloadUrls);
            }

        }
    }

    /**
     * Gets product node if required
     *
     * @param $catalogElement
     * @return null|\Silversolutions\Bundle\EshopBundle\Product\ProductNode
     *
     */
    protected function getProductNode($catalogElement, $sku)
    {
        if ($catalogElement == null) {
            try {
                $catalogElement = $this->catalogService->getDataProvider()->fetchElementBySku($sku);
                if (!$catalogElement instanceof ProductNode) {
                    return null;
                }
            } catch(\Exception $e) {
                return null;
            }
        }
        return $catalogElement;
    }


    /**
     * sends confirmation email to the recipient
     *
     * @param $recipient
     * @param Basket $basket
     * @param array $downloadUrls
     * @param array $eBooksVouchers
     */
    protected function sendMailToRecipient( $recipient, Basket $basket,
                                            $downloadUrls = array())
    {
        $sender = isset($this->mailSettings['addresses']['mailSender'])
            ? $this->mailSettings['addresses']['mailSender']
            : CheckoutSources::MAIL_SENDER;
        $subject = "Your Downloads are ready ...";
        $subject = $this->translator->translate($subject);
        $this->mailerService->sendMailWithRenderedTemplate(
            $sender,
            $recipient,
            $subject,
            'DigitProductsBundle:Emails:downloads.txt.twig',
            'DigitProductsBundle:Emails:downloads.html.twig',
            array(
                'basket' => $basket,
                'downloadUrls' => $downloadUrls,

                'error' => false,
            )
        );
    }

}