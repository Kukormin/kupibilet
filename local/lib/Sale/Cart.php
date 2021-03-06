<?
namespace Local\Sale;

use Bitrix\Main\Loader;
use Bitrix\Sale\Compatible\BasketCompatibility;
use Bitrix\Sale\Order;
use Local\Main\Event;
use Local\Main\Hall;
use Local\Main\Run;
use Local\System\User;
use Local\System\Utils;
use Voronkovich\SberbankAcquiring\Client;
use Voronkovich\SberbankAcquiring\Exception\ActionException;
use Voronkovich\SberbankAcquiring\OrderStatus;

/**
 * Class Cart Корзина
 * @package Local\Sale
 */
class Cart
{
	/**
	 * Путь для кеширования
	 */
	const CACHE_PATH = 'Local/Sale/Cart/';

	private static $propNames = array(
		'SIT' => 'ID Места',
		'SECTOR' => 'Сектор',
		'ROW' => 'Ряд',
		'NUM' => 'Место',
		'SECRET' => 'Проверочный код',
	);

	/**
	 * Возвращает корзину текущего пользователя или товары заказа
	 * @param string $orderId
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function getCart($orderId = 'NULL')
	{
		$return = array(
			'COUNT' => 0,
			'PRICE' => 0,
			'SERV_PRICE' => 0,
		    'ITEMS' => array(),
		);
		Loader::IncludeModule('sale');

		$basket = new \CSaleBasket();
		$basket->Init();
		$rsCart = $basket->GetList(array(), array(
			'ORDER_ID' => $orderId,
			'FUSER_ID' => $basket->GetBasketUserID(),
		));
		$ids = array();
		while ($item = $rsCart->Fetch())
		{
			$id = intval($item['ID']);
			$price = intval($item['PRICE']);
			$serv = floor($price * SERVICE_CHARGE / 100);
			$return['ITEMS'][$id] = array(
				'ID' => $id,
				'RUN' => intval($item['PRODUCT_ID']),
				'EVENT' => intval($item['PRODUCT_XML_ID']),
				'SIT' => intval($item['CATALOG_XML_ID']),
			    'DETAIL_PAGE_URL' => $item['DETAIL_PAGE_URL'],
			    'PRICE' => $price,
			    'SERV' => $serv,
			);

			$return['COUNT']++;
			$return['PRICE'] += $price;
			$return['SERV_PRICE'] += $serv;

			$ids[] = $id;
		}

		if ($ids)
		{
			$rsProps = $basket->GetPropsList(Array(), Array("@BASKET_ID" => $ids));
			while ($prop = $rsProps->Fetch())
			{
				$id = $prop['BASKET_ID'];
				$return['ITEMS'][$id]['PROPS'][$prop['CODE']] = $prop['VALUE'];
			}

			$firstId = array_shift($ids);
			$reserveItem = Reserve::getReservedByCartItemId($firstId);
			$return['EXPIRED'] = $reserveItem['UF_EXPIRED'];
		}

		return $return;
	}

	public static function getOrderItems($orderId)
	{
		return self::getCart($orderId);
	}

	/**
	 * Возвращает сводку по корзине
	 */
	public static function getSummaryDB()
	{
		$cart = self::getCart();
		$tickets = Utils::cardinalNumberRus($cart['COUNT'], 'билетов', 'билет', 'билета');
		$tickets = $cart['COUNT'] . ' ' . $tickets;
		return array(
			'COUNT' => $cart['COUNT'],
			'TICKETS' => $tickets,
			'PRICE' => $cart['PRICE'],
			'SERV_PRICE' => $cart['SERV_PRICE'],
			'EXPIRED' => intval($cart['EXPIRED']),
		);
	}

	/**
	 * Обновляет сводку по корзине
	 */
	public static function updateSessionCartSummary()
	{
		$_SESSION['CART_SUMMARY'] = self::getSummaryDB();
	}

	/**
	 * Возвращает сводку по корзине
	 */
	public static function getSummary()
	{
		Loader::IncludeModule('sale');

		// TODO: убрать на следующий день после релиза
		/*if (isset($_SESSION['CART_SUMMARY']))
		{
			// Проверяем, нужно ли обновить кеш корзины
			$basket = new \CSaleBasket();
			$fuserId = $basket->GetBasketUserID();
			$cartCacheId = CartCache::getByFuserId($fuserId);
			if ($cartCacheId)
			{
				self::updateSessionCartSummary();
				CartCache::delete($cartCacheId);
			}
		}
		else*/
			self::updateSessionCartSummary();

		return $_SESSION['CART_SUMMARY'];
	}

	/**
	 * Для текущего показа возвращает места, которые уже есть в корзине
	 * @param $runId
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function getSitsByRun($runId)
	{
		$return = array();
		Loader::IncludeModule('sale');

		$basket = new \CSaleBasket();
		$basket->Init();
		$rsCart = $basket->GetList(array(), array(
			'ORDER_ID' => 'NULL',
			'FUSER_ID' => $basket->GetBasketUserID(),
			'PRODUCT_ID' => $runId,
		));
		while ($item = $rsCart->Fetch())
		{
			$id = intval($item['CATALOG_XML_ID']);
			$return[$id] = $item['ID'];
		}

		return $return;
	}

	/**
	 * Добавление товара (выбранное место) в корзину
	 * @param $sitId
	 * @param $eventId
	 * @param $runId
	 * @return bool|int
	 */
	public static function add($sitId, $eventId, $runId)
	{

		$sitId = intval($sitId);
		if ($sitId <= 0)
			return false;

		$event = Event::getById($eventId);
		if (!$event)
			return false;

		$run = Run::getById($runId);
		if (!$run)
			return false;

		$price = $run['PRICES'][$sitId];
		if (!$price)
			return false;

		$hall = Hall::getById($event['PRODUCT']['HALL']);

		$scheme = json_decode($hall['SCHEME'], true);
		$sit = $scheme[$sitId];
		if (!$sit)
			return false;

		Loader::IncludeModule('sale');

		// Проверка на наличие в собственной корзине
		$sits = self::getSitsByRun($runId);
		if ($sits[$sitId])
			return false;

		// Проверка на наличие в других корзинах и заказах
		if (!Reserve::check($runId, $sitId))
			return false;
		
		$props = array();
		$props[] = array(
			'NAME' => self::$propNames['SIT'],
			'CODE' => 'SIT',
			'VALUE' => $sitId,
		);
		$props[] = array(
			'NAME' => self::$propNames['SECTOR'],
			'CODE' => 'SECTOR',
			'VALUE' => $sit[3],
		);
		$props[] = array(
			'NAME' => self::$propNames['ROW'],
			'CODE' => 'ROW',
			'VALUE' => $sit[4],
		);
		$props[] = array(
			'NAME' => self::$propNames['NUM'],
			'CODE' => 'NUM',
			'VALUE' => $sit[5],
		);
		$name = $event['NAME'] . ' ' . $run['DATE_S'];

		$fields = array(
			'PRODUCT_ID' => $runId,
			'PRICE' => $price,
			'CATALOG_XML_ID' => $sitId,
			'PRODUCT_XML_ID' => $eventId,
			'CURRENCY' => 'RUB',
			'QUANTITY' => 1,
			'LID' => SITE_ID,
			'DELAY' => 'N',
			'CAN_BUY' => 'Y',
			'NAME' => $name,
			'MODULE' => 'main',
			'DETAIL_PAGE_URL' => $event['DETAIL_PAGE_URL'] . $run['FURL'],
		    'PROPS' => $props,
		);

		$basket = new \CSaleBasket();
		$basket->Init();
		if (!$basket->CheckFields('ADD', $fields))
			return false;

		$basketItem = BasketCompatibility::add($fields);
		if (!$basketItem)
			return false;

		$cartId = $basketItem->getId();

		if ($cartId)
		{
			// Бронируем билет
			Reserve::add($runId, $sitId, $cartId);
			// Корректируем сводку
			self::updateSessionCartSummary();

			$cart = self::getCart();
			self::prolongReserve($cart['ITEMS']);
		}

		return $cartId;
	}

	/**
	 * Удаление товара из корзины
	 * @param $sitId
	 * @param $eventId
	 * @param $runId
	 * @return bool|int
	 */
	public static function remove($sitId, $eventId, $runId)
	{

		$sitId = intval($sitId);
		if ($sitId <= 0)
			return false;

		$event = Event::getById($eventId);
		if (!$event)
			return false;

		$run = Run::getById($runId);
		if (!$run)
			return false;

		$sits = self::getSitsByRun($runId);
		$cartId = $sits[$sitId];
		if (!$cartId)
			return false;

		return self::delete($cartId);
	}

	/**
	 * Удаление товара из корзины
	 * @param $cartId
	 * @return bool|int
	 */
	public static function delete($cartId)
	{
		Loader::IncludeModule('sale');

		$basket = new \CSaleBasket();
		$return = $basket->delete($cartId);

		if ($return)
		{
			Reserve::delete($cartId);
			self::updateSessionCartSummary();
		}

		return $return;
	}

	/**
	 * Устанавливает заказу статус "Просрочен", заказ получаем по позиции в заказе
	 * @param $cartId
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function overdueOrderByCartId($cartId)
	{
		Loader::IncludeModule('sale');

		$basket = new \CSaleBasket();
		$basket->Init();
		$rsCart = $basket->GetList(array(), array(
			'ID' => $cartId,
		));
		if ($cartItem = $rsCart->Fetch())
		{
			// Если заказ уже создан - удаляем только бронь
			$orderId = intval($cartItem['ORDER_ID']);
			if ($orderId)
			{
				$payed = self::checkOrderPayment($cartItem['ORDER_ID']);
				if (!$payed)
				{
					$rsCart1 = $basket->GetList(array(), array(
						'ID' != $cartId,
						'ORDER_ID' => $orderId,
					));
					while ($item = $rsCart1->Fetch())
						Reserve::delete($item['ID']);
					Cart::setOrderOverdue($orderId);
				}
			}
			// Если заказ не создан - удаляем и бронь и позицию
			else
			{
				$basket->Delete($cartId);
				Reserve::delete($cartId);
			}
			CartCache::add($cartItem['FUSER_ID']);
		}
		else
			Reserve::delete($cartId);
	}

	/**
	 * Возвращает свойства заказа
	 * @return array
	 */
	public static function getOrderProps()
	{
		$return = array();
		
		$rsProps = \CSaleOrderProps::GetList(
			array('SORT' => 'ASC'),
			array(
				'PERSON_TYPE_ID' => 1,
				'ACTIVE' => 'Y',
			),
			false,
			false,
			array('ID', 'NAME', 'TYPE', 'SORT', 'CODE')
		);
		while ($item = $rsProps->Fetch())
			$return[$item['CODE']] = $item;

		return $return;
	}

	/**
	 * Создает заказ
	 * @param $cart array корзина полученная методом getCart
	 * @param $user
	 * @param $deliveryPrice
	 * @param $status
	 * @return int
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function createOrder($cart, $user, $deliveryPrice,$status)
	{
		Loader::IncludeModule('sale');

		$userId = $user['ID'];
		if (!$userId)
			return 0;

		$fields = array(
			'LID' => SITE_ID,
			'PERSON_TYPE_ID' => 1,
			'PAYED' => 'N',
			'CANCELED' => 'N',
			'STATUS_ID' => $status,
			'PRICE' => $cart['PRICE'] + $cart['SERV_PRICE'] + $deliveryPrice,
			'PRICE_DELIVERY' => $deliveryPrice,
			'CURRENCY' => 'RUB',
			'USER_ID' => $userId,
			'PAY_SYSTEM_ID' => 2,
		);
		if ($deliveryPrice)
			$fields['DELIVERY_ID'] = 2;

		$order = new \CSaleOrder();
		$basket = new \CSaleBasket();
		$orderId = $order->Add($fields);

		if ($orderId)
		{
			$arOrderProps = self::getOrderProps();

			foreach ($cart['ITEMS'] as $item)
			{
				$basket->Update($item['ID'], array(
					'ORDER_ID' => $orderId,
				));
			}
			$basket->Add(array(
				'ORDER_ID' => $orderId,
				'PRODUCT_ID' => 1,
				'PRICE' => $cart['SERV_PRICE'],
				'CURRENCY' => 'RUB',
				'QUANTITY' => 1,
				'LID' => SITE_ID,
				'DELAY' => 'N',
				'CAN_BUY' => 'Y',
				'NAME' => 'Сервисный сбор',
				'MODULE' => 'main',
			));

			self::updateSessionCartSummary();

			$userName = $user['NAME'];
			if ($user['LAST_NAME'])
			{
				if ($userName)
					$userName .= ' ';
				$userName .= $user['LAST_NAME'];
			}

			$prop = $arOrderProps['FIO'];
			$fields = array(
				'ORDER_ID' => $orderId,
				'ORDER_PROPS_ID' => $prop['ID'],
				'NAME' => $prop['NAME'],
				'CODE' => $prop['CODE'],
				'VALUE' => $userName,
			);
			\CSaleOrderPropsValue::Add($fields);
			$prop = $arOrderProps['EMAIL'];
			$fields = array(
				'ORDER_ID' => $orderId,
				'ORDER_PROPS_ID' => $prop['ID'],
				'NAME' => $prop['NAME'],
				'CODE' => $prop['CODE'],
				'VALUE' => $user['EMAIL'],
			);
			\CSaleOrderPropsValue::Add($fields);
			if ($_REQUEST['order_phone'])
			{
				$prop = $arOrderProps['PHONE'];
				$fields = array(
					'ORDER_ID' => $orderId,
					'ORDER_PROPS_ID' => $prop['ID'],
					'NAME' => $prop['NAME'],
					'CODE' => $prop['CODE'],
					'VALUE' => htmlspecialchars($_REQUEST['order_phone']),
				);
				\CSaleOrderPropsValue::Add($fields);
			}
			if ($_REQUEST['order_address'])
			{
				$prop = $arOrderProps['ADDRESS'];
				$fields = array(
					'ORDER_ID' => $orderId,
					'ORDER_PROPS_ID' => $prop['ID'],
					'NAME' => $prop['NAME'],
					'CODE' => $prop['CODE'],
					'VALUE' => htmlspecialchars($_REQUEST['order_address']),
				);
				\CSaleOrderPropsValue::Add($fields);
			}

			if ($userName)
				$userName = 'Уважаемый ' . $userName . ",";

			$eventFields = array(
				'ORDER_ID' => $orderId,
				'ORDER_DATE' => date('d.m.Y H:i'),
				'ORDER_USER' => $userName,
				'EMAIL' => $user['EMAIL'],
				'PRICE' => $cart['PRICE'] + $cart['SERV_PRICE'],
			    'ORDER_LIST' => '',
			    'SALE_EMAIL' => \COption::GetOptionString('sale', 'order_email', 'order@' . $_SERVER['SERVER_NAME']),
			    'PAYLINK' => 'http://' . \COption::GetOptionString('main', 'server_name', $_SERVER['SERVER_NAME']) .
				    '/personal/order/payment/?id=' . $orderId,
			);
			if ($_SESSION['LOCAL_USER']['PASS'])
			{
				$eventFields['REG_INFO'] = "На сайте был зарегистрирован пользователь с указанным email\n";
				$eventFields['REG_INFO'] .= "Пароль: " . $_SESSION['LOCAL_USER']['PASS'] . "\n";
				//unset($_SESSION['LOCAL_USER']['PASS']);
			}
			\CEvent::SendImmediate('ADD_ORDER', 's1', $eventFields);
		}

		return $orderId;
	}

	/**
	 * Возвращает заказ по ID
	 * @param $id
	 * @return array|\CSaleOrder
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function getOrderById($id)
	{
		Loader::IncludeModule('sale');

		$userId = User::getCurrentUserId();

		$order = new \CSaleOrder();
		$rsOrder = $order->GetList(array(), array(
			'ID' => $id,
			'USER_ID' => $userId,
		));
		$order = $rsOrder->Fetch();

		return $order;
	}

	/**
	 * Проверяет оплату заказа
	 * @param $id
	 * @return bool
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function checkOrderPayment($id)
	{
		Loader::IncludeModule('sale');

		$order = new \CSaleOrder();
		$rsOrder = $order->GetList(array(), array(
			'ID' => $id,
		));
		$order = $rsOrder->Fetch();
		if (!$order['XML_ID'])
			return false;

		try
		{
			$client = new Client(array(
				'userName' => SB_LOGIN,
				'password' => SB_PASS,
			));
			$result = $client->getOrderStatus($order['XML_ID']);
			if (OrderStatus::isDeposited($result['OrderStatus']))
			{
				$orderItems = self::getOrderItems($order['ID']);
				self::setOrderPayed($order['ID'], $orderItems['ITEMS']);
				return true;
			}
		}
		catch (ActionException $e)
		{
			return false;
		}

		return false;
	}

	/**
	 * Устанавливает флаг оплачен для заказа и переводит его в статус "Выполнен"
	 * @param $id
	 * @param $items
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function setOrderPayed($id, $items)
	{
		Loader::IncludeModule('sale');

		// Для того, чтоб в админке видели, что заказ оплачен
		// TODO: объединить с изменением статуса
		$order = Order::load($id);
		$paymentCollection = $order->getPaymentCollection();
		$payment = $paymentCollection[0];
		$payment->setPaid('Y');
		$deliveryPrice = $order->getDeliveryPrice();
		$order->save();
		$propertyCollection = $order->getPropertyCollection();
		$arProps = $propertyCollection->getArray();
		$props = array();
		foreach ($arProps['properties'] as $pr)
			$props[$pr['CODE']] = $pr['VALUE'][0];

		$order = new \CSaleOrder();
		$order->Update($id, array(
			'STATUS_ID' => 'F',
		));

		foreach ($items as $item)
		{
			Reserve::pay($item['ID']);
			self::addSecretToCartId($item);
		}

		$link ='';

		if (!$deliveryPrice)
		{
            $url = 'http://' . \COption::GetOptionString('main', 'server_name',
                    $_SERVER['SERVER_NAME']) . '/personal/order/print/?id=' . $id;
            $link = "<a href='$url'>Распечать билет</a>";
        }

		$eventFields = array(
			'ORDER_ID' => $id,
			'ORDER_USER' => $props['FIO'],
			'EMAIL' => $props['EMAIL'],
			'SALE_EMAIL' => \COption::GetOptionString('sale', 'order_email', 'order@' . $_SERVER['SERVER_NAME']),
		    'PRINT' => $link,
		    'ADDRESS' => $props['ADDRESS'],
		    'PHONE' => $props['PHONE'],
		);
		\CEvent::SendImmediate('PAY_ORDER', 's1', $eventFields);
	}

	/**
	 * Добавление проверочного кода для позиции заказа
	 * @param $cartItem
	 * @return bool
	 */
	public static function addSecretToCartId($cartItem)
	{
		$secret = rand(10, 99) . '-' . rand(10, 99) . '-' . rand(10, 99) . '-' . rand(10, 99);

		$props = array();
		foreach ($cartItem['PROPS'] as $code => $value)
		{
			$props[] = array(
				'NAME' => self::$propNames[$code],
				'CODE' => $code,
				'VALUE' => $value,
			);
			if ($code == 'SECRET')
				return false;
		}
		$props[] = array(
			'NAME' => self::$propNames['SECRET'],
			'CODE' => 'SECRET',
			'VALUE' => $secret,
		);

		$basket = new \CSaleBasket();
		$basket->Update($cartItem['ID'], array('PROPS' => $props));

		return true;
	}

	/**
	 * Переводит заказ в статус "Просрочен"
	 * @param $id
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function setOrderOverdue($id)
	{
		Loader::IncludeModule('sale');

		$order = new \CSaleOrder();
		$order->Update($id, array(
			'STATUS_ID' => 'O',
		));
	}

	/**
	 * Сохраняет в поля заказа свойства эквайринга
	 * @param $id
	 * @param $sbOrderId
	 * @param $sbFormUrl
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function setSbOrderId($id, $sbOrderId, $sbFormUrl)
	{
		Loader::IncludeModule('sale');

		$order = new \CSaleOrder();
		$order->Update($id, array(
			'XML_ID' => $sbOrderId,
			'ADDITIONAL_INFO' => $sbFormUrl,
		));
	}

	/**
	 * Продлевает бронь для позиций
	 * @param $items
	 * @param bool $time
	 */
	public static function prolongReserve($items, $time = false)
	{
		foreach ($items as $item)
			Reserve::prolong($item['ID'], $time);

		self::updateSessionCartSummary();
	}

	/**
	 * Возвращает все заказы текущего пользователя
	 * @return array
	 * @throws \Bitrix\Main\LoaderException
	 */
	public static function getHistory()
	{
		$return = array();

		$userId = User::getCurrentUserId();
		if (!$userId)
			return $return;

		Loader::IncludeModule('sale');

		$order = new \CSaleOrder();
		$rsOrder = $order->GetList(array('ID' => 'DESC'), array(
			'USER_ID' => $userId,
		));
		while ($order = $rsOrder->Fetch())
		{
			$id = intval($order['ID']);
			$return[$id] = $order;
		}

		return $return;
	}
}