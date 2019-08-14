<?php

class Payfort_Pay_PaymentController extends Mage_Core_Controller_Front_Action {

    public function indexAction() {

    }

	// The redirect action is triggered when someone places an order
    public function redirectAction() {

        $is_active = Mage::getStoreConfig('payment/payfort/active');
        $test_mode = Mage::getStoreConfig('payment/payfort/sandbox_mode');

        $merchant_affiliation_name = Mage::getStoreConfig('payment/payfort/merchant_affiliation_name');
        $sha_in_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_in_pass_phrase');
        $sha_out_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_out_pass_phrase');


        $action_gateway = '';

        if (!$test_mode) {
			$action_gateway = 'https://secure.payfort.com/ncol/';
        } else {
			$action_gateway = 'https://secure.payfort.com/ncol/test/';
        }

        //Loading current layout
        $this->loadLayout();
        //Creating a new block
        $block = $this->getLayout()->createBlock(
			'Mage_Core_Block_Template', 'payfort_block_redirect', array('template' => 'payfort/pay/redirect.phtml')
        )
        ->setData('merchant_affiliation_name', $merchant_affiliation_name)
        ->setData('sha_in_pass_phrase', $sha_in_pass_phrase)
        ->setData('sha_out_pass_phrase', $sha_out_pass_phrase);

        $this->getLayout()->getBlock('content')->append($block);

        //Now showing it with rendering of layout
        $this->renderLayout();
    }

    public function responseAction() {



		$orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
        $order = Mage::getModel('sales/order')->loadByIncrementId(Mage::getSingleton('checkout/session')->getLastRealOrderId());

        /*
         * $order = Mage::getModel('sales/order')->loadByIncrementId(Mage::getSingleton('checkout/session')->getLastRealOrderId());
         * $order->getGrandTotal();
         *
         * */


         /*
          * Most frequent transaction statuses:
          *
			0 - Invalid or incomplete
			1 - Cancelled by customer
			2 - Authorisation declined
			5 - Authorised
			9 - Payment requested
          */

		$sha_in_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_in_pass_phrase');

		$sha_out_pass_phrase = Mage::getStoreConfig('payment/payfort/sha_out_pass_phrase');

        $params_not_included = array('response_type', 'SHASIGN');

        $response_type = $this->getRequest()->getParam('response_type');

        $SHASIGN = $this->getRequest()->getParam('SHASIGN');

        $response_order_id = $this->getRequest()->getParam('orderID');

        $response_status = $this->getRequest()->getParam('STATUS');



		$response_params = $this->getRequest()->getParams();

		uksort($response_params, 'strnatcasecmp');

		$sha_string = '';

		$error = false;
        $status = "";

		foreach($response_params as $key => $param) {

			// ignore not included params
			if(in_array($key, $params_not_included))
			continue;

			// ignore empty params
			if($param == '')
			continue;

			$sha_string .= strtoupper($key) . '=' . $param . $sha_out_pass_phrase;

		}

		$sha_string_encrypted = sha1($sha_string);

		//var_dump(strtolower($sha_string_encrypted));
		//var_dump(strtolower($SHASIGN));

		// check the SHASIGN
		if(strtolower($sha_string_encrypted) !== strtolower($SHASIGN)) {

			$response_message = $this->__('Invalid response encrypted key.');

			$this->loadLayout();
			//Creating a new block
			$block = $this->getLayout()->createBlock(
				'Mage_Core_Block_Template', 'payfort_block_response', array('template' => 'payfort/pay/response.phtml')
			)
			->setData('response_message', $response_message);

			$this->getLayout()->getBlock('content')->append($block);

			//Now showing it with rendering of layout
			$this->renderLayout();

			return false;

		}


		/*$error = false;
        $status = "pending";
        //die('Transaction Pending');
        $order->setState(Mage_Sales_Model_Order::STATE_PENDING, true, 'Transaction is pending on Payfort Team for approval/acceptance');
        $order->save();*/


		$response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);

		if($response_status != 9 && $response_status != 5) {

			$response_message = $this->__($response_status_message);

			$this->renderResponse($response_message);

			return false;

		}

        switch($response_type):
			case 'accept':

			/** trying to create invoice * */
			try {
				if (!$order->canInvoice()):
				//Mage::throwException(Mage::helper('core')->__('cannot create invoice !'));
				//Mage::throwException(Mage::helper('core')->__('cannot create an invoice !'));

				$response_message = $this->__('Error: cannot create an invoice !');

				$this->renderResponse($response_message);

				return false;

				else:
				/** create invoice  **/
				//$invoiceId = Mage::getModel('sales/order_invoice_api')->create($order->getIncremenetId(), array());
                $invoice = Mage::getModel('sales/service_order', $order)->prepareInvoice();

                if (!$invoice->getTotalQty()):
                //Mage::throwException(Mage::helper('core')->__('cannot create an invoice without products !'));

                $response_message = $this->__('Error: cannot create an invoice without products !');

				$this->renderResponse($response_message);

				return false;


                endif;

                $invoice->setRequestedCaptureCase(Mage_Sales_Model_Order_Invoice::CAPTURE_ONLINE);
                $invoice->register();
                $transactionSave = Mage::getModel('core/resource_transaction')->addObject($invoice)->addObject($invoice->getOrder());
                $transactionSave->save();

                $order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, 'Payfort has accepted the payment.');
                /** load invoice * */
                //$invoice = Mage::getModel('sales/order_invoice')->loadByIncrementId($invoiceId);
                /** pay invoice * */
                //$invoice->capture()->save();
                endif;
                } catch (Mage_Core_Exception $e) {
					//Mage::throwException(Mage::helper('core')->__('cannot create an invoice !'));
				}

				$order->sendNewOrderEmail();
                $order->setEmailSent(true);
                $order->save();

                if($response_status == 9) {

					$response_message = $this->__('Your payment is accepted.');

				} elseif($response_status == 5) {

					$response_message = $this->__('Your payment is authorized.');

				} else {

					$response_message = $this->__('Unknown response status.');

				}



				$this->renderResponse($response_message);

				return;


			break;
			case 'decline':

			// There is a problem in the response we got
            $this->cancelAction();
            //Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));


            $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);

			$this->renderResponse($response_message);

			return false;


			break;
			case 'exception':

			// There is a problem in the response we got
            $this->cancelAction();
            //Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));

            $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);

			$this->renderResponse($response_message);

			return false;


			break;

			case 'cancel':

			// There is a problem in the response we got
            $this->cancelAction();
            //Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure' => true));

            $response_status_message = Mage::helper('payfort/data')->getResponseCodeDescription($response_status);

			$this->renderResponse($response_message);

			return false;

			break;

			default:

				$response_message = $this->__('Response Unknown');

				$this->renderResponse($response_message);

				return false;

			break;
		endswitch;



    }

    // The cancel action is triggered when an order is to be cancelled
    public function cancelAction() {
        if (Mage::getSingleton('checkout/session')->getLastRealOrderId()) {
            $order = Mage::getModel('sales/order')->loadByIncrementId(Mage::getSingleton('checkout/session')->getLastRealOrderId());
            if ($order->getId()) {
                // Flag the order as 'cancelled' and save it
                $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, 'Gateway has declined the payment.')->save();
            }
        }
    }

    public function successAction() {
        /**/
    }

    public function renderResponse($response_message) {

		$this->loadLayout();
		//Creating a new block
		$block = $this->getLayout()->createBlock(
			'Mage_Core_Block_Template', 'payfort_block_response', array('template' => 'payfort/pay/response.phtml')
		)
		->setData('response_message', $response_message);

		$this->getLayout()->getBlock('content')->append($block);

		//Now showing it with rendering of layout
		$this->renderLayout();

	}

    public function testAction() {

    }

}
