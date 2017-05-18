<?
namespace Local\Sale;

use Bitrix\Highloadblock\HighloadBlockTable;

/**
 * Class Reserve Бронирование билетов
 * @package Local\Sale
 */
class Reserve
{

	/**
	 * ID HL-блока
	 */
	const ENTITY_ID = 2;

	/**
	 * Функция-агент битрикса - удаляет старые брони
	 * @return string
	 */
	public static function deleteExpiredAgent()
	{
		self::deleteExpired();
		return '\Local\Sale\Reserve::deleteExpiredAgent();';
	}

	/**
	 * Получает забронированные билеты для показа
	 * @param $runId
	 * @return array
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 */
	public static function getByRun($runId)
	{
		$entityInfo = HighloadBlockTable::getById(static::ENTITY_ID)->Fetch();
		$entity = HighloadBlockTable::compileEntity($entityInfo);
		$dataClass = $entity->getDataClass();
		$rsItems = $dataClass::getList([
			'filter' => [
				'UF_RUN' => $runId,
			],
		]);
		$return = [];
		while ($item = $rsItems->Fetch())
		{
			$sit = intval($item['UF_SIT']);
			$return[$sit] = $item['UF_PAYED'] ? 'Y' : 'N';
		}

		return $return;
	}

	public static function getByFilter($filter = [], $full = false)
	{
		$entityInfo = HighloadBlockTable::getById(static::ENTITY_ID)->Fetch();
		$entity = HighloadBlockTable::compileEntity($entityInfo);
		$dataClass = $entity->getDataClass();
		$rsItems = $dataClass::getList([
			'filter' => $filter,
		]);
		$return = [];
		while ($item = $rsItems->Fetch())
		{
			if ($full)
			{
				$return[$item['UF_CART']] = $item;
			}
			else
			{
				$sit = intval($item['UF_SIT']);
				$return[$sit] = intval($item['UF_CART']);
			}
		}

		return $return;
	}

	/**
	 * Свободно ли место?
	 * @param $runId
	 * @param $sitId
	 * @return bool
	 */
	public static function check($runId, $sitId)
	{
		$all = self::getByRun($runId);
		if (isset($all[$sitId]))
			return false;
		else
			return true;
	}

	/**
	 * Резервирует место после добавления в корзину
	 * @param $runId
	 * @param $sitId
	 * @param $cartId
	 * @return int
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Exception
	 */
	public static function add($runId, $sitId, $cartId)
	{
		$data = [];
		$data['UF_SIT'] = $sitId;
		$data['UF_RUN'] = $runId;
		$data['UF_EXPIRED'] = time() + RESERVE_TIME;
		$data['UF_CART'] = $cartId;

		$entityInfo = HighloadBlockTable::getById(static::ENTITY_ID)->Fetch();
		$entity = HighloadBlockTable::compileEntity($entityInfo);
		$dataClass = $entity->getDataClass();
		$result = $dataClass::add($data);
		$id = $result->getId();
		return $id;
	}

	/**
	 * Удаляет просроченные резервирования
	 * @throws \Bitrix\Main\ArgumentException
	 * @throws \Bitrix\Main\SystemException
	 * @throws \Exception
	 */
	public static function deleteExpired()
	{
		$entityInfo = HighloadBlockTable::getById(static::ENTITY_ID)->Fetch();
		$entity = HighloadBlockTable::compileEntity($entityInfo);
		$dataClass = $entity->getDataClass();
		$rsItems = $dataClass::getList([
			'filter' => [
				'UF_PAYED' => 0,
			    '<UF_EXPIRED' => time(),
			],
		]);
		while ($item = $rsItems->Fetch())
		{
			Cart::overdueOrderByCartId($item['UF_CART']);
		}
	}

	public static function delete($cartId)
	{
		$entityInfo = HighloadBlockTable::getById(static::ENTITY_ID)->Fetch();
		$entity = HighloadBlockTable::compileEntity($entityInfo);
		$dataClass = $entity->getDataClass();
		$rsItems = $dataClass::getList([
			'filter' => [
				'=UF_CART' => $cartId,
			],
		]);
		if ($item = $rsItems->Fetch())
		{
			return $dataClass::delete($item['ID']);
		}
	}

	public static function getReservedByCartItemId($itemId)
	{
        $entityInfo = HighloadBlockTable::getById(static::ENTITY_ID)->Fetch();
        $entity = HighloadBlockTable::compileEntity($entityInfo);
        $dataClass = $entity->getDataClass();
        $rsItems = $dataClass::getList([
            'filter' => [
                '=UF_CART' => $itemId,
            ],
        ]);
        if ($item = $rsItems->Fetch())
            return $item;

		return [];
    }

	public static function prolong($cartId, $time)
	{
		$entityInfo = HighloadBlockTable::getById(static::ENTITY_ID)->Fetch();
		$entity = HighloadBlockTable::compileEntity($entityInfo);
		$dataClass = $entity->getDataClass();
		$rsItems = $dataClass::getList([
			'filter' => [
				'=UF_CART' => $cartId,
			],
		]);
		if ($item = $rsItems->Fetch())
		{
			if ($time === false)
				$new = time() + RESERVE_TIME;
			else
				$new = $item['UF_EXPIRED'] + $time;
			$dataClass::update($item['ID'], [
				'UF_EXPIRED' => $new,
			]);
		}
	}

	public static function pay($cartId)
	{
		$entityInfo = HighloadBlockTable::getById(static::ENTITY_ID)->Fetch();
		$entity = HighloadBlockTable::compileEntity($entityInfo);
		$dataClass = $entity->getDataClass();
		$rsItems = $dataClass::getList([
			'filter' => [
				'=UF_CART' => $cartId,
			],
		]);
		if ($item = $rsItems->Fetch())
		{
			$dataClass::update($item['ID'], [
				'UF_PAYED' => 1,
			]);
		}
	}

}
