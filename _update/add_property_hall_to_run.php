<?php

$iIbId = 4; // Показы

$oCIBlockProperty = new CIBlockProperty();

$sCode = 'HALL';
$sName = 'Зал';
$rsProps = CIBlockProperty::GetList([], [
	'IBLOCK_ID' => $iIbId,
	'CODE' => $sCode,
]);
if ($arProp = $rsProps->Fetch())
{
	echo "Свойство \"$sName\" уже существует\n";
}
else
{
	$arFields = [
		'MULTIPLE' => 'N',
		'ACTIVE' => 'Y',
		'NAME' => $sName,
		'CODE' => $sCode,
		'IBLOCK_ID' => $iIbId,
		'PROPERTY_TYPE' => 'E',
		'LINK_IBLOCK_ID' => 2,
		'SORT' => 200,
	];

	$iPropId = $oCIBlockProperty->Add($arFields);
	if ($iPropId)
	{
		echo "Добавлено свойство \"$sName\"\n";
	}
	else
	{
		echo "Ошибка добавления свойства \"$sName\"\n";
	}
}

echo "\n";