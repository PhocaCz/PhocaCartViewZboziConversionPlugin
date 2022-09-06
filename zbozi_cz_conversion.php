<?php
/* @package Joomla
 * @copyright Copyright (C) Open Source Matters. All rights reserved.
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL, see LICENSE.php
 * @extension Phoca Extension
 * @copyright Copyright (C) Jan Pavelka www.phoca.cz
 * @license http://www.gnu.org/copyleft/gpl.html GNU/GPL
 */

defined('_JEXEC') or die;
use Joomla\CMS\Plugin\CMSPlugin;
use Joomla\CMS\Factory;

jimport( 'joomla.plugin.plugin' );
jimport( 'joomla.filesystem.file');
jimport( 'joomla.html.parameter' );


JLoader::registerPrefix('Phocacart', JPATH_ADMINISTRATOR . '/components/com_phocacart/libraries/phocacart');

class plgPCVZbozi_cz_conversion extends CMSPlugin
{
	function __construct(& $subject, $config) {
		parent :: __construct($subject, $config);
		$this->loadLanguage();

		$lang = Factory::getLanguage();
		//$lang->load('com_phocacart.sys');
		$lang->load('com_phocacart');
	}

	public function onPCVonInfoViewDisplayContent($context, &$infoData, &$infoAction, $eventData) {


		// DEBUG
		///$infoData['order_id'] = ;
		///$infoData['order_token'] =  '';
		///$infoData['user_id'] = ;

		$p                = [];
		$p['zbozi_id']    = $this->params->get('zbozi_id', '');
		$p['private_key'] = $this->params->get('private_key', '');
		$p['zbozi_type']  = $this->params->get('zbozi_type', 'standard');
		$p['sklik_id']    = $this->params->get('sklik_id', '');
		$p['consent']     = $this->params->get('consent', 1);
		$p['send_customer_email'] = $this->params->get('send_customer_email', 0);
		$p['currency_id']    = $this->params->get('currency_id', 0);

		// If the default currency of the shop is not CZK, then you can set the CZK in options and the prices will be converted to CZK
		$forceCurrency = 0;
		if ($p['currency_id'] != ''){
			$forceCurrency = (int)$p['currency_id'];
		}

		if (!isset($infoData['user_id'])) { $infoData['user_id'] = 0;}

		$order = PhocacartOrder::getOrder($infoData['order_id'], $infoData['order_token'], $infoData['user_id']);

		// $infoAction == 5 means that the order is cancelled, so no conversion
		if (isset($order['id']) && (int)$order['id'] > 0 && $infoAction != 5) {
			$orderProducts = PhocacartOrder::getOrderProducts($order['id']);
			$orderUser = PhocacartOrder::getOrderUser($order['id']);
			$orderTotal = PhocacartOrder::getOrderTotal($order['id'], ['sbrutto', 'snetto', 'pbrutto', 'pnetto']);


			if (!empty($orderProducts)) {

				$price = new PhocacartPrice();


				// DEBUG
				///$order['order_number'] = rand(0,100000);

				// FRONTEND
				$s = [];
				$s[] = 'var conversionConf = {';
				$s[] = '   zboziId: "'.addslashes(trim(strip_tags($p['zbozi_id']))).'",';
				$s[] = '   orderId: "'. $order['order_number'].'",';
				$s[] = '   zboziType: "'. $p['zbozi_type'].'",';

				if ($p['sklik_id'] != '') {
					$s[] = '   id: '.addslashes(trim(strip_tags($p['sklik_id']))).',';
				}

				$s[] = '   value: "'. $price->getPriceFormatRaw($order['total_amount'], 0, 0, $forceCurrency, 2, '.', '').'",';
				$s[] = '   consent: "'. (int)$p['consent'].'",';



				$s[] = '};';

				$s[] = 'if (window.rc && window.rc.conversionHit) {';
				$s[] = '	window.rc.conversionHit(conversionConf);';
				$s[] = '}';

				$app = Factory::getApplication();
        		$wa = $app->getDocument()->getWebAssetManager();
				$wa->registerAndUseScript('com_phocacart.zbozi_cz_conversion', 'https://c.seznam.cz/js/rc.js', array('version' => 'auto'));
				Factory::getDocument()->addScriptDeclaration(implode("\n", $s));


				// BACKEND
				if (!class_exists('ZboziKonverze')) {
            		require_once JPATH_PLUGINS.'/pcv/zbozi_cz_conversion/helpers/ZboziKonverze.php';
        		}

				try {

					// inicializace
					$zbozi = new ZboziKonverze($p['zbozi_id'], $p['private_key']);

					// testovací režim
					if($p['zbozi_type'] == 'sandbox') {
						$zbozi->useSandbox(true);
					}

					$email = false;
					if ($p['send_customer_email'] == 1) {

						if (isset($orderUser[0]['email']) && $orderUser[0]['email'] != '') {
							$email = $orderUser[0]['email'];
						} else if (isset($orderUser[1]['email']) && $orderUser[1]['email'] != '') {
							$email = $orderUser[1]['email'];
						} else if (isset($orderUser[0]['email_contact']) && $orderUser[0]['email_contact'] != '') {
							$email = $orderUser[0]['email_contact'];
						} else if (isset($orderUser[1]['email_contact']) && $orderUser[1]['email_contact'] != '') {
							$email = $orderUser[1]['email_contact'];
						}

					}

					$deliveryType = '';
					if (isset($order['shippingcode']) && $order['shippingcode'] != '') {
						$deliveryType = $order['shippingcode'];
					} else if (isset($order['shippingtitle']) && $order['shippingtitle'] != '') {
						$deliveryType = $order['shippingtitle'];
					}

					$deliveryPrice = '';
					if (isset($orderTotal['sbrutto']['amount']) && $orderTotal['sbrutto']['amount'] > 0) {
						$deliveryPrice = $price->getPriceFormatRaw($orderTotal['sbrutto']['amount'], 0, 0, $forceCurrency, 2, '.', '');
					} else if (isset($orderTotal['snetto']['amount']) && $orderTotal['snetto']['amount'] > 0) {
						$deliveryPrice = $price->getPriceFormatRaw($orderTotal['snetto']['amount'], 0, 0, $forceCurrency, 2, '.', '');
					}

					$otherCosts = '';
					if (isset($orderTotal['pbrutto']['amount']) && $orderTotal['pbrutto']['amount'] > 0) {
						$otherCosts = $price->getPriceFormatRaw($orderTotal['pbrutto']['amount'], 0, 0, $forceCurrency, 2, '.', '');
					} else if (isset($orderTotal['pnetto']['amount']) && $orderTotal['pnetto']['amount'] > 0) {
						$otherCosts = $price->getPriceFormatRaw($orderTotal['pnetto']['amount'], 0, 0, $forceCurrency, 2, '.', '');
					}


					$paymentType = '';
					if (isset($order['paymentcode']) && $order['paymentcode'] != '') {
						$paymentType = $order['paymentcode'];
					} else if (isset($order['paymenttitle']) && $order['paymenttitle'] != '') {
						$paymentType = $order['paymenttitle'];
					}

					// nastavení informací o objednávce
					$orderInfo = [];
					$orderInfo['orderId'] = $order['order_number'];
					if ($email != '') {
						$orderInfo['email'] = $email;
					}
					if ($deliveryType != '') {
						$orderInfo['deliveryType'] = $deliveryType;
					}
					$orderInfo['deliveryPrice'] = 0;
					if ($deliveryPrice != '') {
						$orderInfo['deliveryPrice'] = $deliveryPrice;
					}
					$orderInfo['otherCosts'] = 0;
					if ($otherCosts != '') {
						$orderInfo['otherCosts'] = $otherCosts;
					} else {
						$orderInfo['otherCosts'] = 0;
					}
					if ($paymentType != '') {
						$orderInfo['paymentType'] = $paymentType;
					}

					$zbozi->setOrder($orderInfo);

					// přidáni zakoupené položky
					foreach ($orderProducts as $k => $v) {
						$productPrice  = $price->getPriceFormatRaw($v['brutto'], 0, 0, $forceCurrency, 2, '.', '');
						$zbozi->addCartItem(array(
							"itemId" => (int)$v['product_id'],
							"productName" => addslashes($v['title']),
							"quantity" => (int)$v['quantity'],
							"unitPrice" => $productPrice,
						));
					}

					$zbozi->send();

				} catch (ZboziKonverzeException $e) {
					// zalogování případné chyby
					$ip = PhocacartUtils::getIp();
					PhocacartLog::add(2, 'Conversion error - Zbozi.cz', (int)$order['id'], 'IP: '. $ip.', Order ID: '.(int)$order['id']. ', Message: '.$e->getMessage());
				}

			}
		}

		/*
		$output = array();
		$output['content'] = '';

		return $output;
		*/
	}

}
?>
